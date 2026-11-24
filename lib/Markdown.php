<?php

declare(strict_types=1);

/**
 * Lightweight Markdown → HTML (CommonMark-ish subset). No Composer dependency.
 */
class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = str_replace("\t", '    ', $markdown);
        $lines = explode("\n", $markdown);
        $html = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            $trim = trim($line);

            if ($trim === '') {
                $i++;
                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trim)) {
                $html[] = '<hr>';
                $i++;
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trim, $m)) {
                $level = strlen($m[1]);
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                $i++;
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $quote = [];
                while ($i < $n && preg_match('/^>\s?(.*)$/', $lines[$i], $qm)) {
                    $quote[] = $qm[1];
                    $i++;
                }
                $inner = self::toHtml(implode("\n", $quote));
                $html[] = '<blockquote>' . $inner . '</blockquote>';
                continue;
            }

            if (preg_match('/^```/', $trim)) {
                $lang = trim(substr($trim, 3));
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^```/', trim($lines[$i]))) {
                    $code[] = $lines[$i];
                    $i++;
                }
                if ($i < $n) {
                    $i++;
                }
                $attr = $lang !== '' ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"' : '';
                $html[] = '<pre><code' . $attr . '>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                continue;
            }

            if (preg_match('/^([-*+])\s+(.+)$/', $trim) || preg_match('/^\d+\.\s+(.+)$/', $trim)) {
                $ordered = (bool) preg_match('/^\d+\.\s+/', $trim);
                $tag = $ordered ? 'ol' : 'ul';
                $items = [];
                while ($i < $n) {
                    $t = trim($lines[$i]);
                    if ($ordered && preg_match('/^\d+\.\s+(.+)$/', $t, $im)) {
                        $items[] = $im[1];
                        $i++;
                        continue;
                    }
                    if (!$ordered && preg_match('/^[-*+]\s+(.+)$/', $t, $im)) {
                        $items[] = $im[1];
                        $i++;
                        continue;
                    }
                    break;
                }
                $lis = array_map(static fn (string $item): string => '<li>' . self::inline($item) . '</li>', $items);
                $html[] = '<' . $tag . '>' . implode('', $lis) . '</' . $tag . '>';
                continue;
            }

            if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)$/', $trim, $m)) {
                $alt = htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $src = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                $title = isset($m[3]) && $m[3] !== ''
                    ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                    : '';
                $html[] = '<figure><img src="' . $src . '" alt="' . $alt . '"' . $title . ' loading="lazy" decoding="async"></figure>';
                $i++;
                continue;
            }

            $para = [$trim];
            $i++;
            while ($i < $n) {
                $next = trim($lines[$i]);
                if ($next === ''
                    || preg_match('/^(#{1,6})\s+/', $next)
                    || preg_match('/^>\s?/', $lines[$i])
                    || preg_match('/^```/', $next)
                    || preg_match('/^([-*+])\s+/', $next)
                    || preg_match('/^\d+\.\s+/', $next)
                    || preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $next)
                ) {
                    break;
                }
                $para[] = $next;
                $i++;
            }
            $html[] = '<p>' . self::inline(implode(' ', $para)) . '</p>';
        }

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $text = preg_replace(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/',
            '<img src="$2" alt="$1" loading="lazy" decoding="async">',
            $text
        ) ?? $text;

        $text = preg_replace(
            '/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/',
            '<a href="$2">$1</a>',
            $text
        ) ?? $text;

        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/_([^_]+)_/', '<em>$1</em>', $text) ?? $text;

        return $text;
    }
}
