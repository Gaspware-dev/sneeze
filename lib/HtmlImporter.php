<?php

declare(strict_types=1);

class HtmlImporter
{
    /**
     * Split a preexisting HTML document (or fragment) into template chrome + main content.
     *
     * @return array{
     *   is_document: bool,
     *   title: string,
     *   description: string,
     *   slug: string,
     *   template_html: string,
     *   content: string,
     *   css: string,
     *   js: string,
     *   local_assets: list<array{url: string, abs: string}>
     * }
     */
    public function parse(string $html, ?string $sourcePath = null): array
    {
        $html = trim($html);
        if ($html === '') {
            Response::error('HTML is empty');
        }

        $isDocument = preg_match('/<html[\s>]/i', $html) === 1
            || preg_match('/<!DOCTYPE\s+html/i', $html) === 1;
        $sourceDir = $sourcePath !== null && is_file($sourcePath) ? dirname($sourcePath) : null;

        if (!$isDocument) {
            return [
                'is_document' => false,
                'title' => 'Imported',
                'description' => '',
                'slug' => 'imported',
                'template_html' => '',
                'content' => $html,
                'css' => '',
                'js' => '',
                'local_assets' => $sourceDir ? $this->findLocalAssets($html, $sourceDir) : [],
            ];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $title = $this->textContent($dom->getElementsByTagName('title')->item(0)) ?: 'Imported';
        $description = $this->metaContent($dom, 'description');

        $cssParts = [];
        $jsParts = [];
        $localAssets = [];

        foreach (iterator_to_array($dom->getElementsByTagName('style')) as $style) {
            $cssParts[] = trim($style->textContent);
            $style->parentNode?->removeChild($style);
        }

        foreach (iterator_to_array($dom->getElementsByTagName('link')) as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            $rel = strtolower($link->getAttribute('rel'));
            $href = $link->getAttribute('href');
            if ($rel !== 'stylesheet' || $href === '') {
                continue;
            }
            if ($this->isRemote($href)) {
                continue;
            }
            $abs = $this->resolveLocal($href, $sourceDir);
            if ($abs !== null) {
                $cssParts[] = (string) file_get_contents($abs);
            }
            $link->parentNode?->removeChild($link);
        }

        foreach (iterator_to_array($dom->getElementsByTagName('script')) as $script) {
            if (!$script instanceof DOMElement) {
                continue;
            }
            $src = $script->getAttribute('src');
            if ($src === '') {
                $jsParts[] = trim($script->textContent);
                $script->parentNode?->removeChild($script);
                continue;
            }
            if ($this->isRemote($src)) {
                continue;
            }
            $abs = $this->resolveLocal($src, $sourceDir);
            if ($abs !== null) {
                $jsParts[] = (string) file_get_contents($abs);
                $script->parentNode?->removeChild($script);
            }
        }

        foreach (iterator_to_array($dom->getElementsByTagName('base')) as $base) {
            $base->parentNode?->removeChild($base);
        }

        $titleEl = $dom->getElementsByTagName('title')->item(0);
        if ($titleEl) {
            $titleEl->textContent = '{{page.seo_title}}';
        }
        $this->setMetaContent($dom, 'description', '{{page.seo_description}}');

        $contentNode = $this->findContentNode($dom);
        $content = '';
        if ($contentNode instanceof DOMElement) {
            $content = $this->innerHtml($contentNode);
            while ($contentNode->firstChild) {
                $contentNode->removeChild($contentNode->firstChild);
            }
            $contentNode->appendChild($dom->createTextNode('{{slot:content}}'));
        } else {
            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body instanceof DOMElement) {
                $extracted = $this->extractLooseContent($body, $dom);
                $content = $extracted;
            }
        }

        $this->ensureComments($dom);
        $templateHtml = $this->saveDocument($dom);

        $css = trim(implode("\n\n", array_filter($cssParts)));
        $js = trim(implode("\n\n", array_filter($jsParts)));
        $haystack = $templateHtml . "\n" . $content . "\n" . $css;
        if ($sourceDir) {
            $localAssets = $this->findLocalAssets($haystack, $sourceDir);
        }

        return [
            'is_document' => true,
            'title' => $title,
            'description' => $description,
            'slug' => PageRepository::slug($title),
            'template_html' => $templateHtml,
            'content' => trim($content),
            'css' => $css,
            'js' => $js,
            'local_assets' => $localAssets,
        ];
    }

    /**
     * @param array<string, mixed> $opts name, slug, source_path, user_id, create_template, create_page, copy_assets
     * @return array{template: ?array, page: ?array, media: list<array>}
     */
    public function import(string $html, array $opts = []): array
    {
        $sourcePath = isset($opts['source_path']) && is_string($opts['source_path']) && $opts['source_path'] !== ''
            ? $opts['source_path']
            : null;
        $parsed = $this->parse($html, $sourcePath);

        $media = [];
        $copy = ($opts['copy_assets'] ?? true) !== false;
        if ($copy && $parsed['local_assets'] !== []) {
            $store = new MediaStore();
            $userId = (int) ($opts['user_id'] ?? 0);
            foreach ($parsed['local_assets'] as $asset) {
                $item = $store->ingest($asset['abs'], basename($asset['abs']), $userId, false);
                if ($item === null) {
                    continue;
                }
                $media[] = $item;
                $from = $asset['url'];
                $to = $item['path'];
                $parsed['template_html'] = str_replace($from, $to, $parsed['template_html']);
                $parsed['content'] = str_replace($from, $to, $parsed['content']);
                $parsed['css'] = str_replace($from, $to, $parsed['css']);
            }
        }

        $name = trim((string) ($opts['name'] ?? $parsed['title'])) ?: $parsed['title'];
        $createTemplate = ($opts['create_template'] ?? $parsed['is_document']) !== false;
        $createPage = ($opts['create_page'] ?? true) !== false;

        $template = null;
        if ($createTemplate && $parsed['is_document'] && $parsed['template_html'] !== '') {
            $template = TemplateRepository::save([
                'name' => $name,
                'slug' => $this->uniqueTemplateSlug($opts['slug'] ?? $parsed['slug'] ?: $name),
                'html' => $parsed['template_html'],
                'css' => $parsed['css'],
                'js' => $parsed['js'],
            ]);
        } elseif ($parsed['css'] !== '' || $parsed['js'] !== '') {
            $templateId = (int) ($opts['template_id'] ?? 0);
            if ($templateId > 0) {
                $existing = TemplateRepository::get($templateId);
                $template = TemplateRepository::save([
                    'id' => $templateId,
                    'name' => $existing['name'],
                    'slug' => $existing['slug'],
                    'html' => $existing['html'],
                    'css' => trim($existing['css'] . "\n" . $parsed['css']),
                    'js' => trim($existing['js'] . "\n" . $parsed['js']),
                ]);
            }
        }

        $page = null;
        if ($createPage) {
            $templateId = (int) ($template['id'] ?? $opts['template_id'] ?? 0);
            $page = PageRepository::create([
                'title' => $name,
                'slug' => $this->uniquePageSlug((string) ($opts['slug'] ?? $parsed['slug'] ?: $name)),
                'template_id' => $templateId,
                'seo_title' => $name,
                'seo_description' => $parsed['description'],
            ]);
            $htmlType = Database::pdo()->query("SELECT id FROM block_types WHERE slug = 'html' LIMIT 1")->fetch();
            if ($htmlType && $parsed['content'] !== '') {
                PageRepository::saveTree((int) $page['id'], [[
                    'block_type_id' => (int) $htmlType['id'],
                    'slot' => 'content',
                    'data' => ['html' => $parsed['content']],
                    'children' => [],
                ]]);
                $page = PageRepository::get((int) $page['id']);
            }
        }

        return [
            'template' => $template,
            'page' => $page,
            'media' => $media,
            'parsed' => [
                'is_document' => $parsed['is_document'],
                'title' => $parsed['title'],
            ],
        ];
    }

    private function findContentNode(DOMDocument $dom): ?DOMElement
    {
        $main = $dom->getElementsByTagName('main')->item(0);
        if ($main instanceof DOMElement) {
            return $main;
        }
        $xpath = new DOMXPath($dom);
        $role = $xpath->query('//*[@role="main"]')->item(0);
        if ($role instanceof DOMElement) {
            return $role;
        }
        $id = $dom->getElementById('content') ?? $dom->getElementById('main');
        return $id instanceof DOMElement ? $id : null;
    }

    private function extractLooseContent(DOMElement $body, DOMDocument $dom): string
    {
        $keep = ['header', 'footer', 'nav'];
        $move = [];
        foreach (iterator_to_array($body->childNodes) as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), $keep, true)) {
                continue;
            }
            if ($child instanceof DOMComment) {
                continue;
            }
            $move[] = $child;
        }
        $html = '';
        foreach ($move as $node) {
            $html .= $dom->saveHTML($node);
            $body->removeChild($node);
        }

        $main = $dom->createElement('main');
        $main->appendChild($dom->createTextNode('{{slot:content}}'));
        $footer = null;
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'footer') {
                $footer = $child;
                break;
            }
        }
        if ($footer) {
            $body->insertBefore($main, $footer);
        } else {
            $body->appendChild($main);
        }
        return $html;
    }

    private function ensureComments(DOMDocument $dom): void
    {
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head instanceof DOMElement && !str_contains($this->innerHtml($head), 'sneeze:css')) {
            $head->appendChild($dom->createComment(' sneeze:css '));
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body instanceof DOMElement && !str_contains($this->innerHtml($body), 'sneeze:js')) {
            $body->appendChild($dom->createComment(' sneeze:js '));
        }
    }

    private function saveDocument(DOMDocument $dom): string
    {
        $html = $dom->saveHTML() ?: '';
        $html = preg_replace('/^<\?xml[^>]*>\s*/i', '', $html) ?? $html;
        if (!preg_match('/^\s*<!DOCTYPE html>/i', $html)) {
            $html = "<!DOCTYPE html>\n" . ltrim($html);
        }
        return trim($html) . "\n";
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }
        return $html;
    }

    private function textContent(?DOMNode $node): string
    {
        return $node ? trim($node->textContent) : '';
    }

    private function metaContent(DOMDocument $dom, string $name): string
    {
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            if (strtolower($meta->getAttribute('name')) === $name) {
                return trim($meta->getAttribute('content'));
            }
        }
        return '';
    }

    private function setMetaContent(DOMDocument $dom, string $name, string $value): void
    {
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            if (strtolower($meta->getAttribute('name')) === $name) {
                $meta->setAttribute('content', $value);
                return;
            }
        }
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head instanceof DOMElement) {
            $meta = $dom->createElement('meta');
            $meta->setAttribute('name', $name);
            $meta->setAttribute('content', $value);
            $head->appendChild($meta);
        }
    }

    /**
     * @return list<array{url: string, abs: string}>
     */
    private function findLocalAssets(string $haystack, string $sourceDir): array
    {
        $found = [];
        $seen = [];
        $urls = [];
        if (preg_match_all('/(?:src|href|poster)\s*=\s*["\']([^"\']+)["\']/i', $haystack, $m)) {
            $urls = array_merge($urls, $m[1]);
        }
        if (preg_match_all('/url\((["\']?)([^"\')]+)\1\)/i', $haystack, $m)) {
            $urls = array_merge($urls, $m[2]);
        }
        if (preg_match_all('/srcset\s*=\s*["\']([^"\']+)["\']/i', $haystack, $m)) {
            foreach ($m[1] as $set) {
                foreach (preg_split('/\s*,\s*/', $set) ?: [] as $part) {
                    $urls[] = trim(explode(' ', trim($part))[0]);
                }
            }
        }
        foreach ($urls as $url) {
            $url = trim(html_entity_decode($url));
            if ($url === '' || $this->isRemote($url) || str_starts_with($url, 'data:') || str_starts_with($url, '{{')) {
                continue;
            }
            $abs = $this->resolveLocal($url, $sourceDir);
            if ($abs === null || isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;
            $found[] = ['url' => $url, 'abs' => $abs];
        }
        return $found;
    }

    private function resolveLocal(string $url, ?string $sourceDir): ?string
    {
        if ($sourceDir === null) {
            return null;
        }
        $url = strtok($url, '?#') ?: $url;
        $url = str_replace('\\', '/', $url);
        $path = str_starts_with($url, '/')
            ? rtrim(Config::siteRoot(), '/') . $url
            : $sourceDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $url);
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $root = realpath(Config::siteRoot()) ?: Config::siteRoot();
        $install = realpath(Config::installDir()) ?: Config::installDir();
        if (str_starts_with($real, $install . DIRECTORY_SEPARATOR) || $real === $install) {
            return null;
        }
        if ($root && !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR))) {
            return null;
        }
        return $real;
    }

    private function isRemote(string $url): bool
    {
        return (bool) preg_match('#^(https?:)?//#i', $url) || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:');
    }

    private function uniqueTemplateSlug(string $slug): string
    {
        $base = TemplateRepository::slug($slug) ?: 'imported';
        $try = $base;
        $n = 2;
        while (true) {
            $stmt = Database::pdo()->prepare('SELECT id FROM templates WHERE slug = ?');
            $stmt->execute([$try]);
            if (!$stmt->fetch()) {
                return $try;
            }
            $try = $base . '-' . $n;
            $n++;
        }
    }

    private function uniquePageSlug(string $slug): string
    {
        $base = PageRepository::slug($slug) ?: 'imported';
        if ($base === 'index') {
            $base = 'imported';
        }
        $try = $base;
        $n = 2;
        while (true) {
            $stmt = Database::pdo()->prepare('SELECT id FROM pages WHERE slug = ?');
            $stmt->execute([$try]);
            if (!$stmt->fetch()) {
                return $try;
            }
            $try = $base . '-' . $n;
            $n++;
        }
    }
}
