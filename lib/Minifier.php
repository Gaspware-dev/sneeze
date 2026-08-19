<?php

declare(strict_types=1);

class Minifier
{
    public static function css(string $css): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        $out = '';
        $len = strlen($css);
        $i = 0;
        $inStr = null;
        $inComment = false;

        while ($i < $len) {
            $ch = $css[$i];
            $next = $i + 1 < $len ? $css[$i + 1] : '';

            if ($inComment) {
                if ($ch === '*' && $next === '/') {
                    $inComment = false;
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }

            if ($inStr !== null) {
                $out .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $out .= $next;
                    $i += 2;
                    continue;
                }
                if ($ch === $inStr) {
                    $inStr = null;
                }
                $i++;
                continue;
            }

            if ($ch === '/' && $next === '*') {
                $inComment = true;
                $i += 2;
                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $inStr = $ch;
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === "\r" || $ch === "\n" || $ch === "\t") {
                $out .= ' ';
                $i++;
                continue;
            }

            $out .= $ch;
            $i++;
        }

        $out = preg_replace('/\s+/', ' ', $out) ?? $out;
        $out = preg_replace('/\s*{\s*/', '{', $out) ?? $out;
        $out = preg_replace('/\s*}\s*/', '}', $out) ?? $out;
        $out = preg_replace('/\s*;\s*/', ';', $out) ?? $out;
        $out = preg_replace('/\s*,\s*/', ',', $out) ?? $out;
        $out = str_replace(';}', '}', $out);
        $out = trim($out);
        return $out;
    }

    public static function js(string $js): string
    {
        $js = str_replace(["\r\n", "\r"], "\n", $js);
        $js = trim($js);
        if ($js === '') {
            return '';
        }

        $out = '';
        $len = strlen($js);
        $i = 0;
        $inStr = null;
        $inLine = false;
        $inBlock = false;
        $inRegex = false;
        $prevCode = '';

        while ($i < $len) {
            $ch = $js[$i];
            $next = $i + 1 < $len ? $js[$i + 1] : '';

            if ($inLine) {
                if ($ch === "\n") {
                    $inLine = false;
                    $out .= "\n";
                }
                $i++;
                continue;
            }

            if ($inBlock) {
                if ($ch === '*' && $next === '/') {
                    $inBlock = false;
                    $i += 2;
                    continue;
                }
                $i++;
                continue;
            }

            if ($inStr !== null) {
                $out .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $out .= $next;
                    $i += 2;
                    continue;
                }
                if ($ch === $inStr) {
                    $inStr = null;
                }
                $i++;
                continue;
            }

            if ($inRegex) {
                $out .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $out .= $next;
                    $i += 2;
                    continue;
                }
                if ($ch === '/') {
                    $inRegex = false;
                }
                $i++;
                continue;
            }

            if ($ch === '/' && $next === '/') {
                $inLine = true;
                $i += 2;
                continue;
            }

            if ($ch === '/' && $next === '*') {
                $inBlock = true;
                $i += 2;
                continue;
            }

            if ($ch === '"' || $ch === "'" || $ch === '`') {
                $inStr = $ch;
                $out .= $ch;
                $prevCode = $ch;
                $i++;
                continue;
            }

            if ($ch === '/' && self::maybeRegex($prevCode)) {
                $inRegex = true;
                $out .= $ch;
                $i++;
                continue;
            }

            if ($ch === ' ' || $ch === "\t") {
                $j = $i + 1;
                while ($j < $len && ($js[$j] === ' ' || $js[$j] === "\t")) {
                    $j++;
                }
                $left = $prevCode;
                $right = $j < $len ? $js[$j] : '';
                if (self::needSpace($left, $right)) {
                    $out .= ' ';
                    $prevCode = ' ';
                }
                $i = $j;
                continue;
            }

            if ($ch === "\n") {
                $left = rtrim($prevCode);
                $j = $i + 1;
                while ($j < $len && ($js[$j] === ' ' || $js[$j] === "\t" || $js[$j] === "\n")) {
                    $j++;
                }
                $right = $j < $len ? $js[$j] : '';
                if (self::needNewline($left, $right)) {
                    $out .= "\n";
                    $prevCode = "\n";
                } elseif (self::needSpace($left, $right)) {
                    $out .= ' ';
                    $prevCode = ' ';
                }
                $i = $j;
                continue;
            }

            $out .= $ch;
            if (!ctype_space($ch)) {
                $prevCode = $ch;
            }
            $i++;
        }

        return trim($out);
    }

    public static function escapeInline(string $code, string $tag): string
    {
        return str_ireplace('</' . $tag, '<\\/' . $tag, $code);
    }

    private static function maybeRegex(string $prev): bool
    {
        $prev = rtrim($prev);
        if ($prev === '') {
            return true;
        }
        $last = $prev[strlen($prev) - 1];
        return str_contains('=(:[{,!&|?+~>;', $last) || preg_match('/(?:return|throw|case|else|typeof|void|in|of)$/', $prev) === 1;
    }

    private static function needSpace(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }
        $alnum = fn(string $c): bool => (bool) preg_match('/[A-Za-z0-9_$\x80-\xFF]/', $c);
        return $alnum($left[strlen($left) - 1]) && $alnum($right[0]);
    }

    private static function needNewline(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }
        return preg_match('/(?:return|throw|break|continue|yield)$/', $left) === 1;
    }
}
