<?php

declare(strict_types=1);

class TypeScriptCompiler
{
    public static function compile(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }

        $fromNode = self::viaEsbuild($source);
        if ($fromNode !== null) {
            return trim($fromNode);
        }

        return trim(self::stripTypes($source));
    }

    public static function stripTypes(string $source): string
    {
        $source = preg_replace('/^\s*import\s+type\s+[^;]+;/m', '', $source) ?? $source;
        $source = preg_replace('/^\s*export\s+type\s+\{[^}]+\}\s*;?/m', '', $source) ?? $source;
        $source = self::removeBlockDeclarations($source, 'interface');
        $source = self::removeTypeAliases($source);
        $source = preg_replace('/\b(?:public|private|protected|readonly|abstract|override|declare)\s+/', '', $source) ?? $source;
        $source = preg_replace('/\bimplements\s+[A-Za-z0-9_.,\s<>]+/', '', $source) ?? $source;
        $source = preg_replace('/\bsatisfies\s+[A-Za-z0-9_\$]+(?:<[^>;{]+>)?/', '', $source) ?? $source;
        $source = preg_replace('/\sas\s+const\b/', '', $source) ?? $source;
        $source = preg_replace('/\sas\s+[A-Za-z0-9_\$]+(?:<[^>;,)]+>)?(?:\[\])?/', '', $source) ?? $source;
        $source = preg_replace('/\bfunction\s+([A-Za-z0-9_]+)\s*<[^>]+>/', 'function $1', $source) ?? $source;
        $source = preg_replace('/([A-Za-z0-9_\)\]])\!(?!=)/', '$1', $source) ?? $source;
        $source = preg_replace('/\(([^)]*)\)\s*:\s*[A-Za-z0-9_<>,\[\]\s|&?]+\s*(=>|{)/', '($1)$2', $source) ?? $source;
        $source = preg_replace(
            '/\b(const|let|var)\s+([A-Za-z0-9_]+)\s*:\s*[A-Za-z0-9_<>,\[\]\s|&?]+\s*(=|;)/',
            '$1 $2 $3',
            $source
        ) ?? $source;
        $source = preg_replace('/([,(]\s*(?:\.\.\.)?[A-Za-z0-9_]+)\s*:\s*[A-Za-z0-9_<>,\[\]\s|&?]+/', '$1', $source) ?? $source;
        return trim($source);
    }

    private static function viaEsbuild(string $source): ?string
    {
        $cmd = NodeToolchain::esbuild();
        if ($cmd === null) {
            return null;
        }

        $dir = sys_get_temp_dir() . '/sneeze-ts-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $in = $dir . '/input.ts';
        $out = $dir . '/output.js';
        file_put_contents($in, $source);

        $bundle = preg_match('/^\s*(import|export)\b/m', $source) === 1;
        $args = $cmd;
        $args[] = $in;
        $args[] = '--outfile=' . $out;
        $args[] = '--target=es2018';
        $args[] = '--log-level=error';
        if ($bundle) {
            $args[] = '--bundle';
            $args[] = '--format=iife';
        }

        $result = NodeToolchain::run($args, $dir);
        $js = is_file($out) ? (string) file_get_contents($out) : '';
        self::rmDir($dir);

        if (!$result['ok'] || trim($js) === '') {
            return null;
        }
        return $js;
    }

    private static function removeBlockDeclarations(string $source, string $keyword): string
    {
        $pattern = '/\b(?:export\s+)?(?:declare\s+)?' . $keyword . '\s+[A-Za-z0-9_]+(?:\s*<[^>]+>)?/';
        while (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
            $start = (int) $m[0][1];
            $brace = strpos($source, '{', $start);
            if ($brace === false) {
                break;
            }
            $end = self::matchingBrace($source, $brace);
            if ($end === null) {
                break;
            }
            $source = substr($source, 0, $start) . substr($source, $end + 1);
        }
        return $source;
    }

    private static function removeTypeAliases(string $source): string
    {
        $pattern = '/\b(?:export\s+)?type\s+[A-Za-z0-9_]+(?:\s*<[^>]+>)?\s*=/';
        while (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
            $start = (int) $m[0][1];
            $eq = strpos($source, '=', $start);
            if ($eq === false) {
                break;
            }
            $i = $eq + 1;
            $depth = 0;
            $len = strlen($source);
            while ($i < $len) {
                $ch = $source[$i];
                if ($ch === '{' || $ch === '(' || $ch === '[') {
                    $depth++;
                } elseif ($ch === '}' || $ch === ')' || $ch === ']') {
                    $depth = max(0, $depth - 1);
                } elseif ($ch === ';' && $depth === 0) {
                    $source = substr($source, 0, $start) . substr($source, $i + 1);
                    continue 2;
                }
                $i++;
            }
            break;
        }
        return $source;
    }

    private static function matchingBrace(string $source, int $open): ?int
    {
        $depth = 0;
        $len = strlen($source);
        for ($i = $open; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    private static function rmDir(string $dir): void
    {
        foreach (glob($dir . '/{*,.[!.]*}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
