<?php

declare(strict_types=1);

class Sanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'code', 'col', 'colgroup',
        'div', 'em', 'figcaption', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 'section', 'span', 'strong',
        'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
        'header', 'footer', 'main', 'nav', 'article', 'aside', 'small', 'time',
    ];

    private const ALLOWED_ATTRS = [
        'href', 'src', 'alt', 'title', 'class', 'id', 'data-sneeze-id', 'data-sneeze-section',
        'colspan', 'rowspan', 'width', 'height', 'loading', 'decoding', 'target', 'rel',
        'style', 'role', 'aria-label', 'aria-hidden', 'itemprop', 'itemscope', 'itemtype', 'datetime',
    ];

    public function clean(string $html): string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('root');
        if ($root) {
            $this->cleanNode($root);
            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
            return $out;
        }
        return $html;
    }

    private function cleanNode(DOMNode $node): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $tag = strtolower($node->nodeName);
        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            $parent = $node->parentNode;
            if ($parent) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
            }
            return;
        }

        if ($node->hasAttributes()) {
            $remove = [];
            foreach (iterator_to_array($node->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                if (str_starts_with($name, 'on') || !in_array($name, self::ALLOWED_ATTRS, true)) {
                    $remove[] = $attr->nodeName;
                }
            }
            foreach ($remove as $name) {
                if ($node instanceof DOMElement) {
                    $node->removeAttribute($name);
                }
            }
        }

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            $this->cleanNode($child);
        }
    }
}
