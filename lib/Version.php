<?php

declare(strict_types=1);

class Version
{
    public static function current(): string
    {
        $path = Config::installDir() . '/VERSION';
        if (!is_file($path)) {
            return '0.0.0';
        }
        $raw = trim((string) file_get_contents($path));
        return $raw !== '' ? $raw : '0.0.0';
    }

    /**
     * Compare two semver-ish versions (supports -alphaN / -betaN / -rcN).
     * @return int negative if $a < $b, 0 if equal, positive if $a > $b
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        for ($i = 0; $i < 3; $i++) {
            if ($pa['core'][$i] !== $pb['core'][$i]) {
                return $pa['core'][$i] <=> $pb['core'][$i];
            }
        }
        if ($pa['pre'] === null && $pb['pre'] === null) {
            return 0;
        }
        if ($pa['pre'] === null) {
            return 1;
        }
        if ($pb['pre'] === null) {
            return -1;
        }
        if ($pa['pre']['label'] !== $pb['pre']['label']) {
            return $pa['pre']['label'] <=> $pb['pre']['label'];
        }
        return $pa['pre']['num'] <=> $pb['pre']['num'];
    }

    /**
     * @return array{core: list<int>, pre: ?array{label: string, num: int}}
     */
    private static function parse(string $version): array
    {
        $version = strtolower(ltrim(trim($version), 'v'));
        $pre = null;
        $core = $version;
        if (preg_match('/^([0-9.]+)-([a-z]+)(\d*)$/', $version, $m)) {
            $core = $m[1];
            $pre = [
                'label' => $m[2],
                'num' => $m[3] !== '' ? (int) $m[3] : 0,
            ];
        }
        $parts = array_map('intval', explode('.', $core));
        while (count($parts) < 3) {
            $parts[] = 0;
        }
        return [
            'core' => array_slice($parts, 0, 3),
            'pre' => $pre,
        ];
    }
}
