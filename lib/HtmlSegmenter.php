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
                    'content' => $dom->saveHTML($node),
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

    public function reassemble(array $segments, string $originalHtml): string
    {
        $dom = $this->loadDom($originalHtml);
        $docType = '';
        if (preg_match('/^<!DOCTYPE[^>]*>/i', $originalHtml, $m)) {
            $docType = $m[0] . "\n";
        }

        $map = [];
        foreach ($segments as $seg) {
            $map[$seg['id']] = $seg['content'];
        }

        if (isset($map['head'])) {
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
            if (isset($map['body_content'])) {
                $this->replaceInnerHtml($body, $map['body_content']);
            }
        } else {
            foreach ($blocks as $node) {
                $blockIndex = array_search($node, $blocks, true) + 1;
                $id = $node->attributes?->getNamedItem('data-sneeze-id')?->nodeValue
                    ?: $node->attributes?->getNamedItem('id')?->nodeValue
                    ?: ('block_' . $blockIndex);
                if (!isset($map[$id])) {
                    continue;
                }
                $newNode = $this->fragmentToNode($dom, $map[$id]);
                if ($newNode) {
                    $node->parentNode?->replaceChild($newNode, $node);
                }
            }
        }

        $html = $dom->saveHTML();
        if ($docType && !str_starts_with($html, '<!DOCTYPE')) {
            $html = $docType . $html;
        }
        return $html;
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

        $result = $dom->saveHTML();
        if (preg_match('/^<!DOCTYPE[^>]*>/i', $html, $m) && !str_starts_with($result, '<!DOCTYPE')) {
            $result = $m[0] . "\n" . $result;
        }
        return $result;
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

        foreach ($xpath->query('.//section', $body) as $section) {
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

        $main = $xpath->query('.//main', $body)->item(0);
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
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        return $dom;
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
        return $html;
    }

    private function replaceInnerHtml(DOMNode $node, string $html): void
    {
        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }
        if ($html === '') {
            return;
        }
        $frag = $node->ownerDocument?->createDocumentFragment();
        if ($frag && @$frag->appendXML('<wrapper>' . $html . '</wrapper>') === false) {
            $tmp = new DOMDocument();
            libxml_use_internal_errors(true);
            $tmp->loadHTML('<meta charset="utf-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            $body = $tmp->getElementsByTagName('body')->item(0);
            if ($body) {
                foreach ($body->childNodes as $child) {
                    $imported = $node->ownerDocument?->importNode($child, true);
                    if ($imported) {
                        $node->appendChild($imported);
                    }
                }
            }
            return;
        }
        if ($frag) {
            $wrapper = $frag->firstChild;
            if ($wrapper) {
                while ($wrapper->firstChild) {
                    $node->appendChild($wrapper->firstChild);
                }
            }
        }
    }

    private function fragmentToNode(DOMDocument $dom, string $html): ?DOMNode
    {
        $tmp = new DOMDocument();
        libxml_use_internal_errors(true);
        $tmp->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        foreach ($tmp->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                return $dom->importNode($child, true);
            }
        }
        $body = $tmp->getElementsByTagName('body')->item(0);
        if ($body && $body->firstChild) {
            return $dom->importNode($body->firstChild, true);
        }
        return null;
    }
}
