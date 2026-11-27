<?php

declare(strict_types=1);

class Compiler
{
    private Interpolator $interpolator;

    public function __construct(?Interpolator $interpolator = null)
    {
        $this->interpolator = $interpolator ?? new Interpolator();
    }

    private static int $compileDepth = 0;

    public static function isCompiling(): bool
    {
        return self::$compileDepth > 0;
    }

    private function guarded(callable $run): string
    {
        self::$compileDepth++;
        try {
            return $run();
        } finally {
            self::$compileDepth--;
        }
    }

    /**
     * @param array<string, mixed>|null $draft page overrides: title, slug, template_id, seo_*, custom_css/js, chrome, blocks
     */
    public function compilePage(int $pageId, bool $preview = false, ?array $draft = null): string
    {
        return $this->guarded(fn () => $this->compilePageNow($pageId, $preview, $draft));
    }

    /**
     * @param array<string, mixed>|null $draft
     */
    private function compilePageNow(int $pageId, bool $preview = false, ?array $draft = null): string
    {
        $page = PageRepository::get($pageId, $draft === null || !isset($draft['blocks']));
        if ($draft) {
            foreach ([
                'title', 'slug', 'template_id', 'seo_title', 'seo_description', 'custom_css', 'custom_js',
                'header_mode', 'footer_mode', 'header_html', 'footer_html',
                'disabled_stylesheet_ids', 'disabled_script_ids',
            ] as $key) {
                if (array_key_exists($key, $draft)) {
                    $page[$key] = $draft[$key];
                }
            }
            if (isset($draft['blocks']) && is_array($draft['blocks'])) {
                $page['blocks'] = $this->hydrateDraftTree($draft['blocks']);
            }
        }
        $page = $this->normalizePageAssetFields($page);

        $templateId = (int) ($page['template_id'] ?? 0);
        if ($templateId < 1) {
            Response::error('Page has no template');
        }
        $template = TemplateRepository::get($templateId);
        if (is_array($draft) && is_array($draft['template'] ?? null)) {
            $template = array_merge($template, $draft['template']);
        }
        $blocks = $this->filterBlocks($page['blocks'] ?? [], $page, $preview);

        $context = $this->filterContext($this->siteContext($page), $page, $preview);
        $slotHtml = [
            'content' => $this->renderBlocks($blocks, 'content', $preview),
        ];
        $context['_slots'] = $slotHtml;

        $html = $this->interpolator->render($template['html'], $context);
        $html = $this->injectChrome($html, $page, $context);
        $assets = $this->collectAssets($template, $blocks, $page);
        $assets = $this->compileLanguages($html, $assets);

        return $this->sealDocument($html, $page, $assets, $preview, $preview);
    }

    public function compileDraft(array $page, array $template, array $blocks, bool $preview = true): string
    {
        return $this->guarded(fn () => $this->compileDraftNow($page, $template, $blocks, $preview));
    }

    private function compileDraftNow(array $page, array $template, array $blocks, bool $preview = true): string
    {
        $page['blocks'] = $blocks;
        $page = $this->normalizePageAssetFields($page);
        $blocks = $this->filterBlocks($blocks, $page, $preview);
        $context = $this->filterContext($this->siteContext($page), $page, $preview);
        $context['_slots'] = [
            'content' => $this->renderBlocks($blocks, 'content', $preview),
        ];
        $html = $this->interpolator->render($template['html'], $context);
        $html = $this->injectChrome($html, $page, $context);
        $assets = $this->collectAssets($template, $blocks, $page);
        $assets = $this->compileLanguages($html, $assets);
        return $this->sealDocument($html, $page, $assets, $preview, $preview);
    }

    /**
     * Render a template around slot HTML. No block tree and no preview selection chrome.
     *
     * @param array<string, mixed> $spec
     */
    public function compileDocument(array $spec): string
    {
        return $this->guarded(fn () => $this->compileDocumentNow($spec));
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function compileDocumentNow(array $spec): string
    {
        $template = $this->documentTemplate($spec);
        $pageSpec = is_array($spec['page'] ?? null) ? $spec['page'] : [];
        $preview = !empty($spec['preview']);
        $slug = trim((string) ($pageSpec['slug'] ?? 'index'));
        $page = [
            'id' => null,
            'title' => (string) ($pageSpec['title'] ?? ''),
            'slug' => $slug !== '' ? $slug : 'index',
            'seo_title' => (string) ($pageSpec['seo_title'] ?? ''),
            'seo_description' => (string) ($pageSpec['seo_description'] ?? ''),
            'header_mode' => (string) ($pageSpec['header_mode'] ?? 'inherit'),
            'footer_mode' => (string) ($pageSpec['footer_mode'] ?? 'inherit'),
            'header_html' => (string) ($pageSpec['header_html'] ?? ''),
            'footer_html' => (string) ($pageSpec['footer_html'] ?? ''),
            'custom_css' => '',
            'custom_js' => '',
            'disabled_stylesheet_ids' => [],
            'disabled_script_ids' => [],
        ];
        $page = $this->normalizePageAssetFields($page);
        $context = $this->filterContext($this->siteContext($page), $page, $preview);
        if (!isset($context['page']) || !is_array($context['page'])) {
            $context['page'] = [];
        }
        foreach (['robots', 'canonical', 'og_image', 'json_ld'] as $key) {
            if (array_key_exists($key, $pageSpec) && is_scalar($pageSpec[$key])) {
                $context['page'][$key] = (string) $pageSpec[$key];
            }
        }
        $context['_slots'] = [
            'content' => (string) ($spec['content'] ?? ''),
        ];
        $html = $this->interpolator->render((string) ($template['html'] ?? ''), $context);
        $html = $this->injectChrome($html, $page, $context);
        $assets = $this->collectAssets($template, [], $page);
        $extra = (string) ($spec['extra_css'] ?? '');
        if (trim($extra) !== '') {
            $assets['css'] .= ($assets['css'] !== '' ? "\n\n" : '') . $extra;
        }
        $assets = $this->compileLanguages($html, $assets);

        return $this->sealDocument($html, $page, $assets, $preview, false);
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function documentTemplate(array $spec): array
    {
        $templateId = (int) ($spec['template_id'] ?? 0);
        $inline = is_array($spec['template'] ?? null) ? $spec['template'] : null;
        if ($templateId < 1 && $inline === null) {
            throw new PluginException('compileDocument requires a template');
        }
        if ($templateId > 0) {
            $template = TemplateRepository::get($templateId);
            if ($inline !== null) {
                foreach (['html', 'css', 'js'] as $key) {
                    if (array_key_exists($key, $inline)) {
                        $template[$key] = (string) $inline[$key];
                    }
                }
            }
            return $template;
        }
        if (!is_array($inline) || !is_string($inline['html'] ?? null)) {
            throw new PluginException('compileDocument requires template html');
        }
        return [
            'id' => 0,
            'html' => $inline['html'],
            'css' => (string) ($inline['css'] ?? ''),
            'js' => (string) ($inline['js'] ?? ''),
        ];
    }

    private function normalizePageAssetFields(array $page): array
    {
        $page['header_mode'] = PageRepository::normalizeMode($page['header_mode'] ?? 'inherit');
        $page['footer_mode'] = PageRepository::normalizeMode($page['footer_mode'] ?? 'inherit');
        $page['header_html'] = (string) ($page['header_html'] ?? '');
        $page['footer_html'] = (string) ($page['footer_html'] ?? '');
        $page['disabled_stylesheet_ids'] = PageRepository::idList($page['disabled_stylesheet_ids'] ?? []);
        $page['disabled_script_ids'] = PageRepository::idList($page['disabled_script_ids'] ?? []);
        return $page;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function injectChrome(string $html, array $page, array $context): string
    {
        $hasHeader = str_contains($html, '<!-- sneeze:header -->');
        $hasFooter = str_contains($html, '<!-- sneeze:footer -->');
        if (!$hasHeader && !$hasFooter) {
            return $html;
        }

        $chrome = DesignRepository::chrome();
        $header = $this->resolveChromePiece(
            (string) ($page['header_mode'] ?? 'inherit'),
            (string) ($page['header_html'] ?? ''),
            (string) ($chrome['header_html'] ?? '')
        );
        $footer = $this->resolveChromePiece(
            (string) ($page['footer_mode'] ?? 'inherit'),
            (string) ($page['footer_html'] ?? ''),
            (string) ($chrome['footer_html'] ?? '')
        );

        if ($hasHeader) {
            $rendered = $header !== '' ? $this->interpolator->render($header, $context) : '';
            $html = str_replace('<!-- sneeze:header -->', $rendered, $html);
        }
        if ($hasFooter) {
            $rendered = $footer !== '' ? $this->interpolator->render($footer, $context) : '';
            $html = str_replace('<!-- sneeze:footer -->', $rendered, $html);
        }
        return $html;
    }

    private function resolveChromePiece(string $mode, string $custom, string $sitewide): string
    {
        return match (PageRepository::normalizeMode($mode)) {
            'none' => '',
            'custom' => $custom,
            default => $sitewide,
        };
    }

    private function hydrateDraftTree(array $tree): array
    {
        $types = [];
        foreach (BlockTypeRepository::all() as $type) {
            $types[(int) $type['id']] = $type;
        }

        $walk = function (array $nodes) use (&$walk, $types): array {
            $out = [];
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $typeId = (int) ($node['block_type_id'] ?? $node['block_type']['id'] ?? 0);
                $type = $types[$typeId] ?? null;
                if (!$type) {
                    continue;
                }
                if (isset($node['block_type']) && is_array($node['block_type'])) {
                    $type = array_merge($type, $node['block_type']);
                    $type['schema'] = $node['block_type']['schema'] ?? $type['schema'];
                }
                $node['block_type'] = $type;
                $node['block_type_id'] = $typeId;
                $node['slot'] = $node['slot'] ?? 'content';
                $node['data'] = is_array($node['data'] ?? null) ? $node['data'] : [];
                $node['children'] = $walk($node['children'] ?? []);
                $out[] = $node;
            }
            return $out;
        };

        return $walk($tree);
    }

    private function siteContext(array $page): array
    {
        $webBase = Config::siteWebBase();
        $home = $webBase === '/' ? 'index.html' : $webBase . 'index.html';
        $pages = [];
        foreach (PageRepository::all() as $item) {
            $url = self::pageUrl($item['slug'], $webBase);
            $pages[] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'slug' => $item['slug'],
                'url' => $url,
                'current' => (string) $item['id'] === (string) $page['id'] || $item['slug'] === ($page['slug'] ?? ''),
            ];
        }

        $title = (string) ($page['title'] ?? '');
        $seoTitle = trim((string) ($page['seo_title'] ?? '')) ?: $title;
        $siteTitle = (string) Config::get('site_title', 'Sneeze');

        return [
            'site' => [
                'title' => $siteTitle,
                'home_url' => $home,
                'web_base' => $webBase,
            ],
            'page' => [
                'id' => $page['id'] ?? null,
                'title' => $title,
                'slug' => $page['slug'] ?? '',
                'seo_title' => $seoTitle,
                'seo_description' => (string) ($page['seo_description'] ?? ''),
                'url' => self::pageUrl((string) ($page['slug'] ?? 'index'), $webBase),
            ],
            'pages' => $pages,
        ];
    }

    public static function pageUrl(string $slug, ?string $webBase = null): string
    {
        $webBase = $webBase ?? Config::siteWebBase();
        $file = ($slug === '' || $slug === 'index') ? 'index.html' : $slug . '.html';
        if ($webBase === '/') {
            return $file;
        }
        return $webBase . ltrim($file, '/');
    }

    public static function absoluteUrl(string $relative, ?string $webBase = null): string
    {
        $webBase = $webBase ?? Config::siteWebBase();
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (preg_match('#^https?://#i', $relative)) {
            return $relative;
        }
        if ($webBase === '/') {
            return '/' . $relative;
        }
        return rtrim($webBase, '/') . '/' . $relative;
    }

    public static function outputPath(string $slug): string
    {
        $slug = trim($slug, '/');
        if ($slug === '' || $slug === 'index') {
            return 'index.html';
        }
        return $slug . '.html';
    }

    public function compileSitemap(array $pages): string
    {
        $body = '';
        foreach ($pages as $page) {
            $loc = htmlspecialchars(
                self::absoluteUrl(self::pageUrl((string) $page['slug'])),
                ENT_QUOTES,
                'UTF-8'
            );
            $last = htmlspecialchars(
                substr((string) ($page['updated_at'] ?? date('Y-m-d')), 0, 10),
                ENT_QUOTES,
                'UTF-8'
            );
            $body .= "<url><loc>{$loc}</loc><lastmod>{$last}</lastmod></url>\n";
        }
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . $body
            . "</urlset>\n";
    }

    private function renderBlocks(array $blocks, string $slot, bool $preview, int|string $parentId = 0): string
    {
        $matched = [];
        foreach ($blocks as $block) {
            if (($block['slot'] ?? 'content') !== $slot) {
                continue;
            }
            $matched[] = $block;
        }
        if ($preview && $matched === []) {
            return $this->dropzoneHtml($parentId, $slot, 0, 'Add a block');
        }

        $html = '';
        foreach ($matched as $index => $block) {
            if ($preview) {
                $html .= $this->dropzoneHtml($parentId, $slot, $index, '');
            }
            $html .= $this->renderBlock($block, $preview, $parentId, $slot, $index);
        }
        if ($preview) {
            $html .= $this->dropzoneHtml($parentId, $slot, count($matched), '');
        }
        return $html;
    }

    private function dropzoneHtml(int|string $parentId, string $slot, int $index, string $label): string
    {
        $labelAttr = $label !== '' ? htmlspecialchars($label, ENT_QUOTES, 'UTF-8') : 'Drop here';
        $cls = $label !== '' ? 'sneeze-dropzone' : 'sneeze-dropzone sneeze-dropzone--slim';
        return '<div class="' . $cls . '" data-sneeze-parent="' . htmlspecialchars((string) $parentId, ENT_QUOTES, 'UTF-8')
            . '" data-sneeze-slot="' . htmlspecialchars($slot, ENT_QUOTES, 'UTF-8')
            . '" data-sneeze-index="' . $index . '">' . $labelAttr . '</div>';
    }

    private function renderBlock(array $block, bool $preview, int|string $parentId = 0, string $slot = 'content', int $index = 0): string
    {
        $type = $block['block_type'] ?? null;
        if (!$type) {
            return '';
        }
        $blockId = $block['id'] ?? 0;
        $children = $block['children'] ?? [];
        $schemaSlots = $type['schema']['slots'] ?? [];
        $slots = [];
        if (is_array($schemaSlots)) {
            foreach ($schemaSlots as $name) {
                $slots[(string) $name] = $this->renderBlocks($children, (string) $name, $preview, $blockId);
            }
        }
        foreach ($children as $child) {
            $name = (string) ($child['slot'] ?? 'content');
            if (!array_key_exists($name, $slots)) {
                $slots[$name] = $this->renderBlocks($children, $name, $preview, $blockId);
            }
        }

        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $data = $this->prepareData($type, $data, $preview);
        $data['_slots'] = $slots;

        $templateHtml = (string) ($type['html'] ?? '');
        if ($preview) {
            $templateHtml = $this->previewEditableTemplate($templateHtml, $type);
        }
        $inner = $this->interpolator->render($templateHtml, $data);
        $filteredInner = PluginHooks::filter(PluginHooks::BLOCK_HTML, $inner, [
            'preview' => $preview,
            'block' => [
                'id' => $blockId,
                'slug' => (string) ($type['slug'] ?? ''),
                'slot' => $slot,
            ],
        ]);
        if (is_string($filteredInner)) {
            $inner = $filteredInner;
        }
        if (!$preview) {
            return $inner;
        }

        $id = htmlspecialchars((string) $blockId, ENT_QUOTES, 'UTF-8');
        $slug = htmlspecialchars((string) ($type['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        $parentAttr = htmlspecialchars((string) $parentId, ENT_QUOTES, 'UTF-8');
        $slotAttr = htmlspecialchars($slot, ENT_QUOTES, 'UTF-8');
        return '<div class="sneeze-block" draggable="true" data-sneeze-block="' . $id
            . '" data-sneeze-type="' . $slug
            . '" data-sneeze-parent="' . $parentAttr
            . '" data-sneeze-slot="' . $slotAttr
            . '" data-sneeze-index="' . $index . '">' . $inner . '</div>';
    }

    private function prepareData(array $type, array $data, bool $preview = false): array
    {
        if (($type['slug'] ?? '') === 'heading') {
            $level = strtolower((string) ($data['level'] ?? 'h2'));
            if (!in_array($level, ['h1', 'h2', 'h3', 'h4'], true)) {
                $level = 'h2';
            }
            $text = htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($preview) {
                $text = '<span class="sneeze-editable" contenteditable="true" data-sneeze-field="text">' . $text . '</span>';
            }
            $data['heading_html'] = '<' . $level . '>' . $text . '</' . $level . '>';
        }
        if (($type['slug'] ?? '') === 'spacer' && empty($data['height'])) {
            $data['height'] = 48;
        }
        if ($preview) {
            $data = $this->wrapEditableFields($type, $data);
        }
        return $data;
    }

    private function wrapEditableFields(array $type, array $data): array
    {
        foreach ($type['schema']['fields'] ?? [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            $kind = (string) ($field['type'] ?? 'text');
            if ($name === '' || !in_array($kind, ['text', 'textarea', 'richtext'], true)) {
                continue;
            }
            if (($type['slug'] ?? '') === 'heading' && $name === 'text') {
                continue;
            }
            $raw = (string) ($data[$name] ?? '');
            if ($kind === 'richtext') {
                $data[$name] = '<div class="sneeze-editable sneeze-editable--rich" contenteditable="true" data-sneeze-field="'
                    . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" data-sneeze-rich="1">' . $raw . '</div>';
            } else {
                $data[$name] = '<span class="sneeze-editable" contenteditable="true" data-sneeze-field="'
                    . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            }
        }
        return $data;
    }

    private function previewEditableTemplate(string $html, array $type): string
    {
        foreach ($type['schema']['fields'] ?? [] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            $kind = (string) ($field['type'] ?? 'text');
            if ($name === '' || !in_array($kind, ['text', 'textarea'], true)) {
                continue;
            }
            if (($type['slug'] ?? '') === 'heading' && $name === 'text') {
                continue;
            }
            $html = preg_replace(
                '/\{\{\s*' . preg_quote($name, '/') . '\s*\}\}/',
                '{{{' . $name . '}}}',
                $html
            ) ?? $html;
        }
        return $html;
    }

    private function collectAssets(array $template, array $blocks, array $page): array
    {
        $disabledSheets = array_fill_keys(PageRepository::idList($page['disabled_stylesheet_ids'] ?? []), true);
        $disabledScripts = array_fill_keys(PageRepository::idList($page['disabled_script_ids'] ?? []), true);

        $cssParts = [DesignRepository::tokensToCss(DesignRepository::tokens())];
        foreach (DesignRepository::stylesheets() as $sheet) {
            $id = (int) ($sheet['id'] ?? 0);
            if (isset($disabledSheets[$id])) {
                continue;
            }
            if ((int) $sheet['enabled'] === 1 && trim((string) $sheet['css']) !== '') {
                $cssParts[] = "/* {$sheet['name']} */\n" . $sheet['css'];
            }
        }
        if (trim((string) $template['css']) !== '') {
            $cssParts[] = "/* template */\n" . $template['css'];
        }

        $jsHead = [];
        $jsFoot = [];
        foreach (DesignRepository::scripts() as $script) {
            $id = (int) ($script['id'] ?? 0);
            if (isset($disabledScripts[$id])) {
                continue;
            }
            if ((int) $script['enabled'] !== 1 || trim((string) $script['js']) === '') {
                continue;
            }
            $chunk = "/* {$script['name']} */\n" . $script['js'];
            if (($script['placement'] ?? 'footer') === 'head') {
                $jsHead[] = $chunk;
            } else {
                $jsFoot[] = $chunk;
            }
        }
        if (trim((string) $template['js']) !== '') {
            $jsFoot[] = "/* template */\n" . $template['js'];
        }

        $seenTypes = [];
        $walk = function (array $nodes) use (&$walk, &$seenTypes, &$cssParts, &$jsFoot): void {
            foreach ($nodes as $block) {
                $type = $block['block_type'] ?? null;
                if ($type) {
                    $id = (int) ($type['id'] ?? 0);
                    if ($id && !isset($seenTypes[$id])) {
                        $seenTypes[$id] = true;
                        if (trim((string) ($type['css'] ?? '')) !== '') {
                            $cssParts[] = "/* block: {$type['slug']} */\n" . $type['css'];
                        }
                        if (trim((string) ($type['js'] ?? '')) !== '') {
                            $jsFoot[] = "/* block: {$type['slug']} */\n" . $type['js'];
                        }
                    }
                }
                if (!empty($block['children']) && is_array($block['children'])) {
                    $walk($block['children']);
                }
            }
        };
        $walk($blocks);

        if (trim((string) ($page['custom_css'] ?? '')) !== '') {
            $cssParts[] = "/* page */\n" . $page['custom_css'];
        }
        if (trim((string) ($page['custom_js'] ?? '')) !== '') {
            $jsFoot[] = "/* page */\n" . $page['custom_js'];
        }

        return [
            'css' => implode("\n\n", array_filter($cssParts)),
            'jsHead' => implode("\n\n", $jsHead),
            'jsFoot' => implode("\n\n", $jsFoot),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function filterContext(array $context, array $page, bool $preview): array
    {
        $next = PluginHooks::filter(PluginHooks::COMPILE_CONTEXT, $context, [
            'page' => $page,
            'preview' => $preview,
        ]);
        return is_array($next) ? $next : $context;
    }

    /**
     * @param array<int, mixed> $blocks
     * @param array<string, mixed> $page
     * @return array<int, mixed>
     */
    private function filterBlocks(array $blocks, array $page, bool $preview): array
    {
        $next = PluginHooks::filter(PluginHooks::COMPILE_BLOCKS, $blocks, [
            'page' => $page,
            'preview' => $preview,
        ]);
        return is_array($next) ? $next : $blocks;
    }

    /**
     * @param array<string, mixed> $page
     * @param array{css: string, jsHead: string, jsFoot: string} $assets
     */
    private function sealDocument(string $html, array $page, array $assets, bool $preview, bool $previewChrome): string
    {
        $base = ['page' => $page, 'preview' => $preview];
        $nextAssets = PluginHooks::filter(PluginHooks::COMPILE_ASSETS, $assets, $base + ['html' => $html]);
        if (
            is_array($nextAssets)
            && isset($nextAssets['css'], $nextAssets['jsHead'], $nextAssets['jsFoot'])
            && is_string($nextAssets['css'])
            && is_string($nextAssets['jsHead'])
            && is_string($nextAssets['jsFoot'])
        ) {
            $assets = $nextAssets;
        }
        $html = $this->inject($html, $assets['css'], $assets['jsHead'], $assets['jsFoot'], $preview);
        $html = MediaStore::applyToDocument($html, $preview, (string) ($page['slug'] ?? 'index'));
        if ($previewChrome) {
            $html = $this->injectPreviewChrome($html);
        }
        $next = PluginHooks::filter(PluginHooks::COMPILE_HTML, $html, $base);
        return is_string($next) ? $next : $html;
    }

    /**
     * @param array{css: string, jsHead: string, jsFoot: string} $assets
     * @return array{css: string, jsHead: string, jsFoot: string}
     */
    private function compileLanguages(string $html, array $assets): array
    {
        $tool = ToolchainRepository::settings();
        if (!empty($tool['typescript'])) {
            $assets['jsHead'] = TypeScriptCompiler::compile($assets['jsHead']);
            $assets['jsFoot'] = TypeScriptCompiler::compile($assets['jsFoot']);
        }
        if (!empty($tool['tailwind'])) {
            $assets['css'] = TailwindCompiler::compile($assets['css'], $html, $tool);
        }
        return $assets;
    }

    private function inject(string $html, string $css, string $jsHead, string $jsFoot, bool $preview): string
    {
        $html = str_replace(['<!-- sneeze:css -->', '<!-- sneeze:js -->'], '', $html);

        $css = trim($css);
        // All JS runs after parse: head-placement first, then footer. Inline scripts
        // cannot use defer/async (invalid without src) so they belong before </body>.
        $js = trim($jsHead);
        $foot = trim($jsFoot);
        if ($js !== '' && $foot !== '') {
            $js .= "\n" . $foot;
        } elseif ($js === '') {
            $js = $foot;
        }

        if (!$preview) {
            if ($css !== '') {
                $css = Minifier::css($css);
            }
            if ($js !== '') {
                $js = Minifier::js($js);
            }
        }

        if ($css !== '') {
            $css = Minifier::escapeInline($css, 'style');
            $style = '<style>' . $css . '</style>';
            if (preg_match('/<\/head>/i', $html)) {
                $html = preg_replace('/<\/head>/i', $style . "\n</head>", $html, 1) ?? ($html . $style);
            } else {
                $html = $style . $html;
            }
        }

        if ($js !== '') {
            $js = Minifier::escapeInline($js, 'script');
            $script = '<script>' . $js . '</script>';
            if (preg_match('/<\/body>/i', $html)) {
                $html = preg_replace('/<\/body>/i', $script . "\n</body>", $html, 1) ?? ($html . $script);
            } else {
                $html .= $script;
            }
        }

        return $this->normalizeHtml($html);
    }

    private function normalizeHtml(string $html): string
    {
        $html = preg_replace('/\s+(class|id|style|title)="\s*"/i', '', $html) ?? $html;

        $html = preg_replace_callback('/<img\b([^>]*)>/i', static function (array $m): string {
            $attrs = $m[1];
            if (preg_match('/\balt\s*=/i', $attrs)) {
                return '<img' . $attrs . '>';
            }
            return '<img alt=""' . $attrs . '>';
        }, $html) ?? $html;

        if (!preg_match('/^\s*<!DOCTYPE html>/i', $html)) {
            $html = "<!DOCTYPE html>\n" . ltrim($html);
        }

        $html = preg_replace("/[ \t]+$/m", '', $html) ?? $html;
        $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;
        return rtrim($html) . "\n";
    }

    private function injectPreviewChrome(string $html): string
    {
        $chrome = <<<'HTML'
<style>
.sneeze-block { position: relative; outline: 1px solid transparent; cursor: grab; }
.sneeze-block:hover { outline: 1px dashed #d04c4c; outline-offset: 2px; }
.sneeze-block.is-selected { outline: 2px solid #d04c4c; outline-offset: 2px; }
.sneeze-block.is-dragging { opacity: .45; }
.sneeze-dropzone {
  border: 1px dashed rgba(208,76,76,.45);
  color: #8f3333;
  border-radius: 12px;
  padding: 1rem;
  text-align: center;
  font: 600 13px system-ui, sans-serif;
  background: rgba(208,76,76,.06);
  margin: .35rem 0;
  min-height: 1.5rem;
  transition: background .15s, padding .15s, border-color .15s;
}
.sneeze-dropzone--slim {
  padding: 0;
  min-height: 8px;
  border-color: transparent;
  background: transparent;
  color: transparent;
  font-size: 0;
  margin: 0;
}
.sneeze-dropzone.is-over,
.sneeze-dropzone--slim.is-over {
  border-color: rgba(208,76,76,.7);
  background: rgba(208,76,76,.14);
  padding: .65rem;
  min-height: 1.5rem;
  color: #8f3333;
  font-size: 12px;
}
.sneeze-editable {
  outline: 1px dashed transparent;
  border-radius: 2px;
  min-width: .75em;
  cursor: text;
}
.sneeze-editable:hover,
.sneeze-editable:focus {
  outline-color: rgba(208,76,76,.55);
  background: rgba(208,76,76,.06);
}
.sneeze-editable--rich { display: block; }
</style>
<script>
(function () {
  var dragId = null;
  function select(id) {
    document.querySelectorAll(".sneeze-block.is-selected").forEach(function (el) {
      el.classList.remove("is-selected");
    });
    if (!id) return;
    var target = document.querySelector('[data-sneeze-block="' + id + '"]');
    if (target) {
      target.classList.add("is-selected");
      target.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }
  }
  function clearOver() {
    document.querySelectorAll(".sneeze-dropzone.is-over").forEach(function (el) {
      el.classList.remove("is-over");
    });
  }
  function emitEdit(fieldEl) {
    var block = fieldEl.closest("[data-sneeze-block]");
    if (!block) return;
    var rich = fieldEl.getAttribute("data-sneeze-rich") === "1";
    parent.postMessage({
      type: "sneeze:edit",
      id: block.getAttribute("data-sneeze-block"),
      field: fieldEl.getAttribute("data-sneeze-field"),
      value: rich ? fieldEl.innerHTML : (fieldEl.textContent || "")
    }, "*");
  }
  document.addEventListener("click", function (e) {
    var field = e.target.closest(".sneeze-editable");
    if (field) {
      e.stopPropagation();
      var block = field.closest("[data-sneeze-block]");
      if (block) {
        var id = block.getAttribute("data-sneeze-block");
        select(id);
        parent.postMessage({ type: "sneeze:select", id: id }, "*");
      }
      return;
    }
    var el = e.target.closest("[data-sneeze-block]");
    if (!el) return;
    e.preventDefault();
    e.stopPropagation();
    var id = el.getAttribute("data-sneeze-block");
    select(id);
    parent.postMessage({ type: "sneeze:select", id: id }, "*");
  }, true);
  document.addEventListener("focusin", function (e) {
    var field = e.target.closest(".sneeze-editable");
    if (!field) return;
    var block = field.closest("[data-sneeze-block]");
    if (block) block.setAttribute("draggable", "false");
  }, true);
  document.addEventListener("focusout", function (e) {
    var field = e.target.closest(".sneeze-editable");
    if (!field) return;
    var block = field.closest("[data-sneeze-block]");
    if (block) block.setAttribute("draggable", "true");
    emitEdit(field);
  }, true);
  document.addEventListener("input", function (e) {
    var field = e.target.closest(".sneeze-editable");
    if (!field) return;
    emitEdit(field);
  }, true);
  document.addEventListener("dragstart", function (e) {
    var block = e.target.closest("[data-sneeze-block]");
    if (!block || e.target.closest(".sneeze-editable")) {
      e.preventDefault();
      return;
    }
    dragId = block.getAttribute("data-sneeze-block");
    block.classList.add("is-dragging");
    e.dataTransfer.effectAllowed = "move";
    e.dataTransfer.setData("application/x-sneeze-block", dragId);
    e.dataTransfer.setData("text/plain", dragId);
  }, true);
  document.addEventListener("dragend", function () {
    document.querySelectorAll(".sneeze-block.is-dragging").forEach(function (el) {
      el.classList.remove("is-dragging");
    });
    clearOver();
    dragId = null;
  }, true);
  document.addEventListener("dragover", function (e) {
    var zone = e.target.closest(".sneeze-dropzone");
    if (!zone) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = e.dataTransfer.types.indexOf("application/x-sneeze-type") >= 0 ? "copy" : "move";
    clearOver();
    zone.classList.add("is-over");
  }, true);
  document.addEventListener("dragleave", function (e) {
    var zone = e.target.closest(".sneeze-dropzone");
    if (zone) zone.classList.remove("is-over");
  }, true);
  document.addEventListener("drop", function (e) {
    var zone = e.target.closest(".sneeze-dropzone");
    if (!zone) return;
    e.preventDefault();
    clearOver();
    var parentId = zone.getAttribute("data-sneeze-parent") || "0";
    var slot = zone.getAttribute("data-sneeze-slot") || "content";
    var index = Number(zone.getAttribute("data-sneeze-index") || "0");
    var typeId = e.dataTransfer.getData("application/x-sneeze-type");
    if (typeId) {
      parent.postMessage({
        type: "sneeze:add",
        typeId: typeId,
        parentId: parentId,
        slot: slot,
        index: index
      }, "*");
      return;
    }
    var id = e.dataTransfer.getData("application/x-sneeze-block") || dragId;
    if (!id) return;
    parent.postMessage({
      type: "sneeze:drop",
      id: id,
      parentId: parentId,
      slot: slot,
      index: index
    }, "*");
  }, true);
  window.addEventListener("message", function (e) {
    if (!e.data || e.data.type !== "sneeze:highlight") return;
    select(String(e.data.id || ""));
  });
})();
</script>
HTML;
        if (preg_match('/<\/body>/i', $html)) {
            return preg_replace('/<\/body>/i', $chrome . "\n</body>", $html, 1) ?? ($html . $chrome);
        }
        return $html . $chrome;
    }
}
