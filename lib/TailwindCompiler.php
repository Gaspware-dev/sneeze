<?php

declare(strict_types=1);

class TailwindCompiler
{
    private const BREAKPOINTS = [
        'sm' => '640px',
        'md' => '768px',
        'lg' => '1024px',
        'xl' => '1280px',
        '2xl' => '1536px',
    ];

    private const SPACING = [
        '0' => '0',
        'px' => '1px',
        '0.5' => '0.125rem',
        '1' => '0.25rem',
        '1.5' => '0.375rem',
        '2' => '0.5rem',
        '2.5' => '0.625rem',
        '3' => '0.75rem',
        '3.5' => '0.875rem',
        '4' => '1rem',
        '5' => '1.25rem',
        '6' => '1.5rem',
        '7' => '1.75rem',
        '8' => '2rem',
        '9' => '2.25rem',
        '10' => '2.5rem',
        '11' => '2.75rem',
        '12' => '3rem',
        '14' => '3.5rem',
        '16' => '4rem',
        '20' => '5rem',
        '24' => '6rem',
        '28' => '7rem',
        '32' => '8rem',
        '36' => '9rem',
        '40' => '10rem',
        '44' => '11rem',
        '48' => '12rem',
        '52' => '13rem',
        '56' => '14rem',
        '60' => '15rem',
        '64' => '16rem',
        '72' => '18rem',
        '80' => '20rem',
        '96' => '24rem',
    ];

    public static function compile(string $css, string $html, array $toolchain): string
    {
        $css = trim($css);
        $fromCli = self::viaCli($css, $html, $toolchain);
        if ($fromCli !== null) {
            return trim($fromCli);
        }
        return self::viaPhp($css, $html, $toolchain);
    }

    public static function viaPhp(string $css, string $html, array $toolchain): string
    {
        $colors = self::colorMap($toolchain['tokens'] ?? DesignRepository::tokens());
        $colors = self::mergeConfigColors($colors, (string) ($toolchain['tailwind_config_ts'] ?? ''));
        $css = self::expandApply($css, $colors);
        $classes = self::extractClasses($html . ' ' . $css);
        foreach (self::applyClassNames($css) as $name) {
            $classes[$name] = true;
        }

        $utilities = self::utilities(array_keys($classes), $colors);
        $injected = false;
        if (preg_match('/@import\s+[\'"]tailwindcss[\'"][^;]*;?/', $css)) {
            $css = preg_replace('/@import\s+[\'"]tailwindcss[\'"][^;]*;?/', '/* sneeze:tailwind */', $css, 1) ?? $css;
            $css = str_replace('/* sneeze:tailwind */', $utilities, $css);
            $injected = true;
        }
        $css = preg_replace('/@tailwind\s+(base|components|utilities)\s*;?/', '', $css) ?? $css;
        $css = trim($css);

        if ($utilities === '') {
            return $css;
        }
        if ($injected) {
            return $css;
        }
        return $utilities . ($css !== '' ? "\n" . $css : '');
    }

    private static function viaCli(string $css, string $html, array $toolchain): ?string
    {
        $cmd = NodeToolchain::tailwind();
        if ($cmd === null) {
            return null;
        }

        $dir = sys_get_temp_dir() . '/sneeze-tw-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/content.html', $html);
        $input = $css;
        if (!preg_match('/@tailwind\b|@import\s+[\'"]tailwindcss[\'"]/', $input)) {
            $input = "@tailwind utilities;\n" . $input;
        }
        file_put_contents($dir . '/input.css', $input);
        $config = trim((string) ($toolchain['tailwind_config_ts'] ?? ''));
        if ($config === '') {
            $config = ToolchainRepository::defaultConfigTs($toolchain['tokens'] ?? []);
        }
        file_put_contents($dir . '/tailwind.config.js', self::configToCjs($config));

        $args = $cmd;
        $args[] = '-i';
        $args[] = $dir . '/input.css';
        $args[] = '-o';
        $args[] = $dir . '/output.css';
        $args[] = '-c';
        $args[] = $dir . '/tailwind.config.js';
        $args[] = '--content';
        $args[] = $dir . '/content.html';

        $result = NodeToolchain::run($args, $dir, null, 60);
        $out = is_file($dir . '/output.css') ? (string) file_get_contents($dir . '/output.css') : '';
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        if (!$result['ok'] || trim($out) === '') {
            return null;
        }
        return $out;
    }

    public static function configToCjs(string $ts): string
    {
        $js = TypeScriptCompiler::stripTypes($ts);
        $js = preg_replace('/^\s*import\s+[^;]+;/m', '', $js) ?? $js;
        $js = preg_replace('/export\s+default\s+/', 'module.exports = ', $js) ?? $js;
        return trim($js);
    }

    /**
     * @param array<string, string> $colors
     * @return array<string, string>
     */
    private static function mergeConfigColors(array $colors, string $configTs): array
    {
        if ($configTs === '' || !preg_match('/\bcolors\s*:\s*\{/', $configTs, $m, PREG_OFFSET_CAPTURE)) {
            return $colors;
        }
        $brace = strpos($configTs, '{', (int) $m[0][1]);
        if ($brace === false) {
            return $colors;
        }
        $end = self::matchingBrace($configTs, $brace);
        if ($end === null) {
            return $colors;
        }
        $block = substr($configTs, $brace + 1, $end - $brace - 1);
        if (preg_match_all('/["\']?([A-Za-z_][A-Za-z0-9_-]*)["\']?\s*:\s*["\']([^"\']+)["\']/', $block, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                $colors[$pair[1]] = $pair[2];
            }
        }
        return $colors;
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

    /**
     * @return array<string, true>
     */
    public static function extractClasses(string $html): array
    {
        $found = [];
        if (preg_match_all('/class(?:Name)?\s*=\s*["\']([^"\']+)["\']/', $html, $matches)) {
            foreach ($matches[1] as $list) {
                foreach (preg_split('/\s+/', trim($list)) ?: [] as $class) {
                    if ($class !== '') {
                        $found[$class] = true;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * @return list<string>
     */
    private static function applyClassNames(string $css): array
    {
        $names = [];
        if (preg_match_all('/@apply\s+([^;]+);/', $css, $matches)) {
            foreach ($matches[1] as $list) {
                foreach (preg_split('/\s+/', trim($list)) ?: [] as $class) {
                    if ($class !== '') {
                        $names[] = $class;
                    }
                }
            }
        }
        return $names;
    }

    private static function expandApply(string $css, array $colors): string
    {
        return preg_replace_callback('/@apply\s+([^;]+);/', static function (array $m) use ($colors): string {
            $classes = preg_split('/\s+/', trim($m[1])) ?: [];
            $decls = [];
            foreach ($classes as $class) {
                $parsed = self::declarationsFor($class, $colors);
                if ($parsed !== null) {
                    foreach ($parsed['decls'] as $decl) {
                        $decls[] = $decl;
                    }
                }
            }
            return implode(' ', $decls);
        }, $css) ?? $css;
    }

    /**
     * @param list<string> $classes
     */
    public static function utilities(array $classes, array $colors): string
    {
        $base = [];
        $media = [];
        foreach ($classes as $class) {
            $parsed = self::declarationsFor($class, $colors);
            if ($parsed === null) {
                continue;
            }
            $selector = '.' . self::escapeClass($class) . implode('', $parsed['suffixes']);
            $body = implode(' ', $parsed['decls']);
            $rule = $selector . '{' . $body . '}';
            if ($parsed['media'] === null) {
                $base[] = $rule;
            } else {
                $media[$parsed['media']][] = $rule;
            }
        }

        $out = implode('', $base);
        foreach ($media as $query => $rules) {
            $out .= '@media (min-width:' . $query . '){' . implode('', $rules) . '}';
        }
        return $out;
    }

    /**
     * @return array{decls: list<string>, media: ?string, suffixes: list<string>}|null
     */
    private static function declarationsFor(string $class, array $colors): ?array
    {
        $parts = explode(':', $class);
        $utility = array_pop($parts);
        $media = null;
        $suffixes = [];
        foreach ($parts as $variant) {
            if (isset(self::BREAKPOINTS[$variant])) {
                $media = self::BREAKPOINTS[$variant];
                continue;
            }
            if ($variant === 'hover' || $variant === 'focus' || $variant === 'focus-visible' || $variant === 'active') {
                $suffixes[] = ':' . $variant;
                continue;
            }
            return null;
        }

        $decls = self::utilityDecls($utility, $colors);
        if ($decls === null) {
            return null;
        }
        return ['decls' => $decls, 'media' => $media, 'suffixes' => $suffixes];
    }

    /**
     * @return list<string>|null
     */
    private static function utilityDecls(string $name, array $colors): ?array
    {
        $static = [
            'block' => ['display:block'],
            'inline' => ['display:inline'],
            'inline-block' => ['display:inline-block'],
            'flex' => ['display:flex'],
            'inline-flex' => ['display:inline-flex'],
            'grid' => ['display:grid'],
            'hidden' => ['display:none'],
            'contents' => ['display:contents'],
            'flex-row' => ['flex-direction:row'],
            'flex-col' => ['flex-direction:column'],
            'flex-wrap' => ['flex-wrap:wrap'],
            'items-start' => ['align-items:flex-start'],
            'items-center' => ['align-items:center'],
            'items-end' => ['align-items:flex-end'],
            'items-stretch' => ['align-items:stretch'],
            'justify-start' => ['justify-content:flex-start'],
            'justify-center' => ['justify-content:center'],
            'justify-end' => ['justify-content:flex-end'],
            'justify-between' => ['justify-content:space-between'],
            'justify-around' => ['justify-content:space-around'],
            'grow' => ['flex-grow:1'],
            'shrink' => ['flex-shrink:1'],
            'shrink-0' => ['flex-shrink:0'],
            'relative' => ['position:relative'],
            'absolute' => ['position:absolute'],
            'fixed' => ['position:fixed'],
            'sticky' => ['position:sticky'],
            'inset-0' => ['inset:0'],
            'overflow-hidden' => ['overflow:hidden'],
            'overflow-auto' => ['overflow:auto'],
            'object-cover' => ['object-fit:cover'],
            'object-contain' => ['object-fit:contain'],
            'text-left' => ['text-align:left'],
            'text-center' => ['text-align:center'],
            'text-right' => ['text-align:right'],
            'italic' => ['font-style:italic'],
            'not-italic' => ['font-style:normal'],
            'underline' => ['text-decoration-line:underline'],
            'no-underline' => ['text-decoration-line:none'],
            'uppercase' => ['text-transform:uppercase'],
            'lowercase' => ['text-transform:lowercase'],
            'truncate' => ['overflow:hidden', 'text-overflow:ellipsis', 'white-space:nowrap'],
            'sr-only' => ['position:absolute', 'width:1px', 'height:1px', 'padding:0', 'margin:-1px', 'overflow:hidden', 'clip:rect(0,0,0,0)', 'white-space:nowrap', 'border-width:0'],
            'w-full' => ['width:100%'],
            'h-full' => ['height:100%'],
            'min-h-screen' => ['min-height:100vh'],
            'h-screen' => ['height:100vh'],
            'mx-auto' => ['margin-left:auto', 'margin-right:auto'],
            'border' => ['border-width:1px'],
            'border-0' => ['border-width:0'],
            'rounded' => ['border-radius:0.25rem'],
            'rounded-md' => ['border-radius:0.375rem'],
            'rounded-lg' => ['border-radius:0.5rem'],
            'rounded-full' => ['border-radius:9999px'],
            'shadow' => ['box-shadow:0 1px 3px 0 rgb(0 0 0 / 0.1),0 1px 2px -1px rgb(0 0 0 / 0.1)'],
            'shadow-lg' => ['box-shadow:0 10px 15px -3px rgb(0 0 0 / 0.1),0 4px 6px -4px rgb(0 0 0 / 0.1)'],
            'font-sans' => ['font-family:var(--font-body)'],
            'font-serif' => ['font-family:var(--font-display)'],
            'font-normal' => ['font-weight:400'],
            'font-medium' => ['font-weight:500'],
            'font-semibold' => ['font-weight:600'],
            'font-bold' => ['font-weight:700'],
            'text-xs' => ['font-size:0.75rem', 'line-height:1rem'],
            'text-sm' => ['font-size:0.875rem', 'line-height:1.25rem'],
            'text-base' => ['font-size:1rem', 'line-height:1.5rem'],
            'text-lg' => ['font-size:1.125rem', 'line-height:1.75rem'],
            'text-xl' => ['font-size:1.25rem', 'line-height:1.75rem'],
            'text-2xl' => ['font-size:1.5rem', 'line-height:2rem'],
            'text-3xl' => ['font-size:1.875rem', 'line-height:2.25rem'],
            'text-4xl' => ['font-size:2.25rem', 'line-height:2.5rem'],
            'leading-tight' => ['line-height:1.25'],
            'leading-normal' => ['line-height:1.5'],
            'leading-relaxed' => ['line-height:1.625'],
        ];
        if (isset($static[$name])) {
            return self::semi($static[$name]);
        }

        if (preg_match('/^grid-cols-(\d+)$/', $name, $m)) {
            return self::semi(['grid-template-columns:repeat(' . $m[1] . ',minmax(0,1fr))']);
        }
        if (preg_match('/^col-span-(\d+)$/', $name, $m)) {
            return self::semi(['grid-column:span ' . $m[1] . ' / span ' . $m[1]]);
        }
        if (preg_match('/^opacity-(\d+)$/', $name, $m)) {
            return self::semi(['opacity:' . ((int) $m[1] / 100)]);
        }

        $spacing = [
            'p' => ['padding'],
            'px' => ['padding-left', 'padding-right'],
            'py' => ['padding-top', 'padding-bottom'],
            'pt' => ['padding-top'],
            'pr' => ['padding-right'],
            'pb' => ['padding-bottom'],
            'pl' => ['padding-left'],
            'm' => ['margin'],
            'mx' => ['margin-left', 'margin-right'],
            'my' => ['margin-top', 'margin-bottom'],
            'mt' => ['margin-top'],
            'mr' => ['margin-right'],
            'mb' => ['margin-bottom'],
            'ml' => ['margin-left'],
            'gap' => ['gap'],
            'gap-x' => ['column-gap'],
            'gap-y' => ['row-gap'],
            'w' => ['width'],
            'h' => ['height'],
            'min-w' => ['min-width'],
            'min-h' => ['min-height'],
            'max-w' => ['max-width'],
            'max-h' => ['max-height'],
            'inset' => ['inset'],
            'top' => ['top'],
            'right' => ['right'],
            'bottom' => ['bottom'],
            'left' => ['left'],
        ];
        foreach ($spacing as $prefix => $props) {
            if (str_starts_with($name, $prefix . '-')) {
                $key = substr($name, strlen($prefix) + 1);
                $value = self::spacingValue($key);
                if ($value !== null) {
                    $decls = [];
                    foreach ($props as $prop) {
                        $decls[] = $prop . ':' . $value;
                    }
                    return self::semi($decls);
                }
            }
        }

        $arbitrary = self::arbitrary($name);
        if ($arbitrary !== null) {
            return self::semi([$arbitrary]);
        }

        $colorUtil = self::colorUtility($name, $colors);
        if ($colorUtil !== null) {
            return self::semi([$colorUtil]);
        }

        return null;
    }

    private static function spacingValue(string $key): ?string
    {
        if (isset(self::SPACING[$key])) {
            return self::SPACING[$key];
        }
        if ($key === 'auto') {
            return 'auto';
        }
        if ($key === 'full') {
            return '100%';
        }
        if ($key === 'screen') {
            return '100vh';
        }
        if ($key === 'min') {
            return 'min-content';
        }
        if ($key === 'max') {
            return 'max-content';
        }
        if ($key === 'fit') {
            return 'fit-content';
        }
        if (preg_match('#^(\d+)/(\d+)$#', $key, $m)) {
            return round(((int) $m[1] / (int) $m[2]) * 100, 6) . '%';
        }
        if (preg_match('/^\[(.+)\]$/', $key, $m)) {
            return str_replace('_', ' ', $m[1]);
        }
        return null;
    }

    private static function arbitrary(string $name): ?string
    {
        if (!preg_match('/^([a-z]+(?:-[a-z]+)*)-\[(.+)\]$/', $name, $m)) {
            return null;
        }
        $value = str_replace('_', ' ', $m[2]);
        $map = [
            'w' => 'width',
            'h' => 'height',
            'max-w' => 'max-width',
            'text' => 'color',
            'bg' => 'background-color',
            'top' => 'top',
            'left' => 'left',
            'right' => 'right',
            'bottom' => 'bottom',
        ];
        $prop = $map[$m[1]] ?? null;
        return $prop ? $prop . ':' . $value : null;
    }

    private static function colorUtility(string $name, array $colors): ?string
    {
        $prefixes = [
            'text' => 'color',
            'bg' => 'background-color',
            'border' => 'border-color',
            'fill' => 'fill',
            'stroke' => 'stroke',
        ];
        foreach ($prefixes as $prefix => $prop) {
            if (!str_starts_with($name, $prefix . '-')) {
                continue;
            }
            $key = substr($name, strlen($prefix) + 1);
            if (isset($colors[$key])) {
                return $prop . ':' . $colors[$key];
            }
            if ($key === 'transparent') {
                return $prop . ':transparent';
            }
            if ($key === 'current') {
                return $prop . ':currentColor';
            }
            if ($key === 'inherit') {
                return $prop . ':inherit';
            }
            if ($key === 'white') {
                return $prop . ':' . ($colors['white'] ?? '#fff');
            }
            if ($key === 'black') {
                return $prop . ':' . ($colors['black'] ?? '#000');
            }
        }
        return null;
    }

    /**
     * @param list<string> $decls
     * @return list<string>
     */
    private static function semi(array $decls): array
    {
        return array_map(static fn(string $d): string => str_ends_with($d, ';') ? $d : $d . ';', $decls);
    }

    public static function colorMap(array $tokens): array
    {
        $colors = [
            'white' => 'var(--color-white, #fff)',
            'black' => '#000',
        ];
        $walk = function (array $node, string $prefix) use (&$walk, &$colors): void {
            foreach ($node as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix . '-' . $key;
                if (is_array($value)) {
                    $walk($value, $name);
                    continue;
                }
                $colors[$name] = 'var(--' . $name . ', ' . $value . ')';
                if (str_starts_with($name, 'color-')) {
                    $short = substr($name, strlen('color-'));
                    $colors[$short] = 'var(--' . $name . ', ' . $value . ')';
                }
            }
        };
        $walk($tokens, '');
        return $colors;
    }

    private static function escapeClass(string $class): string
    {
        return preg_replace('/([^a-zA-Z0-9_-])/', '\\\\$1', $class) ?? $class;
    }
}
