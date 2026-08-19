<?php

declare(strict_types=1);

class Compiler
{
    private Interpolator $interpolator;

    public function __construct(?Interpolator $interpolator = null)
    {
        $this->interpolator = $interpolator ?? new Interpolator();
    }

    /**
     * @param array<string, mixed>|null $draft page overrides: title, slug, template_id, seo_*, custom_css/js, blocks
     */
    public function compilePage(int $pageId, bool $preview = false, ?array $draft = null): string
    {
        $page = PageRepository::get($pageId, $draft === null || !isset($draft['blocks']));
        if ($draft) {
            foreach (['title', 'slug', 'template_id', 'seo_title', 'seo_description', 'custom_css', 'custom_js'] as $key) {
                if (array_key_exists($key, $draft)) {
                    $page[$key] = $draft[$key];
                }
            }
            if (isset($draft['blocks']) && is_array($draft['blocks'])) {
                $page['blocks'] = $this->hydrateDraftTree($draft['blocks']);
            }
        }

        $templateId = (int) ($page['template_id'] ?? 0);
        if ($templateId < 1) {
            Response::error('Page has no template');
        }
        $template = TemplateRepository::get($templateId);
        if (is_array($draft) && is_array($draft['template'] ?? null)) {
            $template = array_merge($template, $draft['template']);
        }
        $blocks = $page['blocks'] ?? [];

        $context = $this->siteContext($page);
        $slotHtml = [
            'content' => $this->renderBlocks($blocks, 'content', $preview),
        ];
        $context['_slots'] = $slotHtml;

        $html = $this->interpolator->render($template['html'], $context);
        $assets = $this->collectAssets($template, $blocks, $page);
        $assets = $this->compileLanguages($html, $assets);

        $html = $this->inject($html, $assets['css'], $assets['jsHead'], $assets['jsFoot'], $preview);

        if ($preview) {
            $html = $this->injectPreviewChrome($html);
        }

        return $html;
    }

    public function compileDraft(array $page, array $template, array $blocks, bool $preview = true): string
    {
        $page['blocks'] = $blocks;
        $context = $this->siteContext($page);
        $context['_slots'] = [
            'content' => $this->renderBlocks($blocks, 'content', $preview),
        ];
        $html = $this->interpolator->render($template['html'], $context);
        $assets = $this->collectAssets($template, $blocks, $page);
        $assets = $this->compileLanguages($html, $assets);
        $html = $this->inject($html, $assets['css'], $assets['jsHead'], $assets['jsFoot'], $preview);
        if ($preview) {
            $html = $this->injectPreviewChrome($html);
        }
        return $html;
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

    public static function outputPath(string $slug): string
    {
        $slug = trim($slug, '/');
        if ($slug === '' || $slug === 'index') {
            return 'index.html';
        }
        return $slug . '.html';
    }

    private function renderBlocks(array $blocks, string $slot, bool $preview): string
    {
        $html = '';
        foreach ($blocks as $block) {
            if (($block['slot'] ?? 'content') !== $slot) {
                continue;
            }
            $html .= $this->renderBlock($block, $preview);
        }
        if ($preview && $html === '') {
            $html = '<div class="sneeze-dropzone" data-sneeze-slot="' . htmlspecialchars($slot) . '">Add a block</div>';
        }
        return $html;
    }

    private function renderBlock(array $block, bool $preview): string
    {
        $type = $block['block_type'] ?? null;
        if (!$type) {
            return '';
        }
        $children = $block['children'] ?? [];
        $schemaSlots = $type['schema']['slots'] ?? [];
        $slots = [];
        if (is_array($schemaSlots)) {
            foreach ($schemaSlots as $name) {
                $slots[(string) $name] = $this->renderChildSlot($children, (string) $name, $preview);
            }
        }
        foreach ($children as $child) {
            $name = (string) ($child['slot'] ?? 'content');
            if (!array_key_exists($name, $slots)) {
                $slots[$name] = $this->renderChildSlot($children, $name, $preview);
            }
        }

        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $data = $this->prepareData($type, $data);
        $data['_slots'] = $slots;

        $inner = $this->interpolator->render((string) ($type['html'] ?? ''), $data);
        if (!$preview) {
            return $inner;
        }

        $id = (int) ($block['id'] ?? 0);
        $slug = htmlspecialchars((string) ($type['slug'] ?? ''), ENT_QUOTES, 'UTF-8');
        return '<div class="sneeze-block" data-sneeze-block="' . $id . '" data-sneeze-type="' . $slug . '">' . $inner . '</div>';
    }

    private function renderChildSlot(array $children, string $slot, bool $preview): string
    {
        $html = '';
        foreach ($children as $child) {
            if (($child['slot'] ?? 'content') !== $slot) {
                continue;
            }
            $html .= $this->renderBlock($child, $preview);
        }
        if ($preview && $html === '') {
            $html = '<div class="sneeze-dropzone" data-sneeze-slot="' . htmlspecialchars($slot) . '">Drop in ' . htmlspecialchars($slot) . '</div>';
        }
        return $html;
    }

    private function prepareData(array $type, array $data): array
    {
        if (($type['slug'] ?? '') === 'heading') {
            $level = strtolower((string) ($data['level'] ?? 'h2'));
            if (!in_array($level, ['h1', 'h2', 'h3', 'h4'], true)) {
                $level = 'h2';
            }
            $text = htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $data['heading_html'] = '<' . $level . '>' . $text . '</' . $level . '>';
        }
        if (($type['slug'] ?? '') === 'spacer' && empty($data['height'])) {
            $data['height'] = 48;
        }
        return $data;
    }

    private function collectAssets(array $template, array $blocks, array $page): array
    {
        $cssParts = [DesignRepository::tokensToCss(DesignRepository::tokens())];
        foreach (DesignRepository::stylesheets() as $sheet) {
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
.sneeze-block { position: relative; outline: 1px solid transparent; cursor: pointer; }
.sneeze-block:hover { outline: 1px dashed #d04c4c; outline-offset: 2px; }
.sneeze-block.is-selected { outline: 2px solid #d04c4c; outline-offset: 2px; }
.sneeze-dropzone {
  border: 1px dashed rgba(208,76,76,.45);
  color: #8f3333;
  border-radius: 12px;
  padding: 1rem;
  text-align: center;
  font: 600 13px system-ui, sans-serif;
  background: rgba(208,76,76,.06);
  margin: .35rem 0;
}
</style>
<script>
(function () {
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
  document.addEventListener("click", function (e) {
    var el = e.target.closest("[data-sneeze-block]");
    if (!el) return;
    e.preventDefault();
    e.stopPropagation();
    var id = el.getAttribute("data-sneeze-block");
    select(id);
    parent.postMessage({ type: "sneeze:select", id: id }, "*");
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
