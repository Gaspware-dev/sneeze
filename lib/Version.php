<?php

declare(strict_types=1);

class Version
{
    /** Prerelease precedence. A release with no label outranks all of these. */
    private const PRE_RANK = [
        'alpha' => 1,
        'beta' => 2,
        'rc' => 3,
    ];

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
     * Compare two product versions.
     * Dotted prereleases (-alpha.N, -beta.N, -rc.N) are preferred.
     * The legacy glued form (-alphaN) compares equal to the dotted form.
     * A release with no prerelease is greater than any prerelease of the same core.
     * An optional leading v is ignored.
     *
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
        $ra = self::PRE_RANK[$pa['pre']['label']] ?? 0;
        $rb = self::PRE_RANK[$pb['pre']['label']] ?? 0;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return $pa['pre']['num'] <=> $pb['pre']['num'];
    }

    /**
     * @return array{core: list<int>, pre: ?array{label: string, num: int}}
     */
    private static function parse(string $version): array
    {
        $version = ltrim(strtolower(trim($version)), 'v');
        $pre = null;
        $core = $version;
        if (preg_match('/^([0-9.]+)-(alpha|beta|rc)\.(\d+)$/', $version, $m)) {
            $core = $m[1];
            $pre = [
                'label' => $m[2],
                'num' => (int) $m[3],
            ];
        } elseif (preg_match('/^([0-9.]+)-(alpha|beta|rc)(\d*)$/', $version, $m)) {
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
