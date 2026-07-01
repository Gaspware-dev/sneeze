<?php

declare(strict_types=1);

class HtmlSegmenter
{
    public function parse(string $html): array
    {
        $dom = $this->loadDom($html);
        $segments = [];

        if (preg_match('/^<!DOCTYPE[^>]*>/i', $html, $m)) {
            $segments[] = [
                'id' => 'doctype',
                'label' => 'DOCTYPE',
                'type' => 'doctype',
                'content' => $m[0],
            ];
        }

        $segments[] = [
            'id' => 'head',
            'label' => 'Head',
            'type' => 'head',
            'content' => $this->innerHtml($dom->getElementsByTagName('head')->item(0)),
        ];

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            Response::error('No body element found');
        }

        $blockIndex = 0;
        $sections = $this->findEditableBlocks($body);

        if (empty($sections)) {
            $segments[] = [
                'id' => 'body_content',
                'label' => 'Body',
                'type' => 'block',
                'content' => $this->innerHtml($body),
            ];
        } else {
            foreach ($sections as $node) {
                $blockIndex++;
                $tag = strtolower($node->nodeName);
                $id = $node->attributes?->getNamedItem('data-sneeze-id')?->nodeValue
                    ?: $node->attributes?->getNamedItem('id')?->nodeValue
                    ?: ('block_' . $blockIndex);
                $label = $this->blockLabel($node, $blockIndex);
                $segments[] = [
                    'id' => (string) $id,
                    'label' => $label,
                    'type' => 'block',
                    'tag' => $tag,
                    'content' => $this->outerHtml($node),
                ];
            }
        }

        return [
            'segments' => $segments,
            'full' => $html,
        ];
    }

    public function toFullHtml(array $segments, string $originalHtml): string
    {
        return $this->reassemble($segments, $originalHtml);
    }

    /**
     * @param bool $blocksOnly When true (Basic View save), only body blocks are updated; head is left untouched.
     */
    public function reassemble(array $segments, string $originalHtml, bool $blocksOnly = false): string
    {
        $dom = $this->loadDom($originalHtml);
        $docType = '';
        if (preg_match('/^<!DOCTYPE[^>]*>/i', $originalHtml, $m)) {
            $docType = $m[0];
        }

        $map = [];
        foreach ($segments as $seg) {
            $map[$seg['id']] = $seg['content'];
        }

        if (!$blocksOnly && isset($map['head']) && trim($map['head']) !== '') {
            $head = $dom->getElementsByTagName('head')->item(0);
            if ($head) {
                $this->replaceInnerHtml($head, $map['head']);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            Response::error('No body element');
        }

        $blocks = $this->findEditableBlocks($body);
        if (empty($blocks)) {
            if (isset($map['body_content']) && trim($map['body_content']) !== '') {
                $this->replaceInnerHtml($body, $map['body_content']);
            }
        } else {
            foreach ($blocks as $node) {
                $blockIndex = array_search($node, $blocks, true) + 1;
                $id = $node->attributes?->getNamedItem('data-sneeze-id')?->nodeValue
                    ?: $node->attributes?->getNamedItem('id')?->nodeValue
                    ?: ('block_' . $blockIndex);
                if (!isset($map[$id]) || trim($map[$id]) === '') {
                    continue;
                }
                $newNode = $this->fragmentToNode($dom, $map[$id]);
                if ($newNode) {
                    $node->parentNode?->replaceChild($newNode, $node);
                }
            }
        }

        return $this->serializeDom($dom, $docType);
    }

    public function insertSection(string $html, string $sectionHtml, string $position = 'main'): string
    {
        $dom = $this->loadDom($html);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            Response::error('No body element');
        }

        $newNode = $this->fragmentToNode($dom, $sectionHtml);
        if (!$newNode) {
            Response::error('Invalid section HTML');
        }

        $main = $dom->getElementsByTagName('main')->item(0);
        if ($main) {
            $main->appendChild($newNode);
        } else {
            $body->appendChild($newNode);
        }

        $docType = '';
        if (preg_match('/^<!DOCTYPE[^>]*>/i', $html, $m)) {
            $docType = $m[0];
        }
        return $this->serializeDom($dom, $docType);
    }

    public function sectionTemplates(): array
    {
        $uid = fn() => 'sneeze-' . bin2hex(random_bytes(4));
        return [
            [
                'id' => 'blank',
                'label' => 'Blank section',
                'html' => '<section data-sneeze-id="' . $uid() . '"><div class="container"><h2>New section</h2><p>Edit this content.</p></div></section>',
            ],
            [
                'id' => 'hero',
                'label' => 'Hero',
                'html' => '<section data-sneeze-id="' . $uid() . '" class="hero"><div class="container"><h1>Heading</h1><p>Intro text goes here.</p></div></section>',
            ],
            [
                'id' => 'two-col',
                'label' => 'Two columns',
                'html' => '<section data-sneeze-id="' . $uid() . '"><div class="container" style="display:grid;grid-template-columns:1fr 1fr;gap:2rem"><div><h3>Column 1</h3><p>Content.</p></div><div><h3>Column 2</h3><p>Content.</p></div></div></section>',
            ],
            [
                'id' => 'cta',
                'label' => 'Call to action',
                'html' => '<section data-sneeze-id="' . $uid() . '" class="section--cta"><div class="container"><h2>Ready to get started?</h2><p>Add your call to action here.</p></div></section>',
            ],
        ];
    }

    private function findEditableBlocks(DOMNode $body): array
    {
        $blocks = [];
        $xpath = new DOMXPath($body->ownerDocument);

        foreach ($xpath->query('./section | ./main/section | .//main/section', $body) as $section) {
            $blocks[] = $section;
        }
        if (!empty($blocks)) {
            return $blocks;
        }

        foreach ($xpath->query('.//*[@data-sneeze-section]', $body) as $node) {
            $blocks[] = $node;
        }
        if (!empty($blocks)) {
            return $blocks;
        }

        $main = $xpath->query('./main', $body)->item(0);
        if ($main) {
            foreach ($main->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $blocks[] = $child;
                }
            }
            if (!empty($blocks)) {
                return $blocks;
            }
        }

        foreach ($body->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);
                if (!in_array($tag, ['script', 'style', 'link'], true)) {
                    $blocks[] = $child;
                }
            }
        }

        return $blocks;
    }

    private function blockLabel(DOMElement $node, int $index): string
    {
        $id = $node->getAttribute('id');
        if ($id) {
            return '#' . $id;
        }
        $tag = strtolower($node->nodeName);
        foreach ($node->getElementsByTagName('h2') as $h2) {
            $text = trim($h2->textContent ?? '');
            if ($text !== '') {
                return $text;
            }
        }
        return ucfirst($tag) . ' ' . $index;
    }

    private function loadDom(string $html): DOMDocument
    {
        $html = $this->stripXmlArtifacts($html);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        return $dom;
    }

    private function serializeDom(DOMDocument $dom, string $docType = ''): string
    {
        $html = $dom->saveHTML();
        $html = $this->stripXmlArtifacts($html);

        if ($docType !== '' && !preg_match('/^<!DOCTYPE/i', $html)) {
            $html = $docType . "\n" . $html;
        }

        return $html;
    }

    private function stripXmlArtifacts(string $html): string
    {
        return preg_replace('/<\?xml[^>]*\??>/i', '', $html) ?? $html;
    }

    private function innerHtml(?DOMNode $node): string
    {
        if (!$node) {
            return '';
        }
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }
        return $this->stripXmlArtifacts($html);
    }

    private function outerHtml(DOMNode $node): string
    {
        return $this->stripXmlArtifacts($node->ownerDocument?->saveHTML($node) ?? '');
    }

    /**
     * Parse an HTML fragment and return nodes to append (imported into target document).
     */
    private function parseFragment(DOMDocument $owner, string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $tmp = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $wrapped = '<div id="sneeze-fragment-root">' . $html . '</div>';
        $tmp->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $tmp->getElementById('sneeze-fragment-root');
        if (!$root) {
            return [];
        }

        $nodes = [];
        foreach ($root->childNodes as $child) {
            $imported = $owner->importNode($child, true);
            if ($imported) {
                $nodes[] = $imported;
            }
        }
        return $nodes;
    }

    private function replaceInnerHtml(DOMNode $node, string $html): void
    {
        $html = trim($html);
        if ($html === '') {
            return;
        }

        $owner = $node->ownerDocument;
        if (!$owner) {
            return;
        }

        $newNodes = $this->parseFragment($owner, $html);
        if ($newNodes === []) {
            return;
        }

        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }

        foreach ($newNodes as $child) {
            $node->appendChild($child);
        }
    }

    private function fragmentToNode(DOMDocument $dom, string $html): ?DOMNode
    {
        $nodes = $this->parseFragment($dom, $html);
        if ($nodes === []) {
            return null;
        }
        if (count($nodes) === 1) {
            return $nodes[0];
        }

        $wrapper = $dom->createElement('div');
        foreach ($nodes as $child) {
            $wrapper->appendChild($child);
        }
        return $wrapper;
    }
}
