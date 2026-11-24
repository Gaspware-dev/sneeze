<?php

declare(strict_types=1);

class Config
{
    private static ?array $data = null;
    private static string $configPath;
    private static string $installDir;

    public static function init(string $installDir): void
    {
        self::$installDir = realpath($installDir) ?: $installDir;
        self::$configPath = self::$installDir . '/data/config.json';
        self::load();
    }

    public static function installDir(): string
    {
        return self::$installDir;
    }

    public static function dataDir(): string
    {
        return self::$installDir . '/data';
    }

    public static function load(): void
    {
        if (!is_file(self::$configPath)) {
            self::$data = self::defaults();
            return;
        }

        $json = file_get_contents(self::$configPath);
        $decoded = json_decode($json ?: '{}', true);
        self::$data = array_merge(self::defaults(), is_array($decoded) ? $decoded : []);
    }

    public static function defaults(): array
    {
        $parent = realpath(self::$installDir . '/..') ?: dirname(self::$installDir);

        return [
            'setup_complete' => false,
            'site_root' => $parent,
            'site_web_base' => '/',
            'upload_dir' => 'uploads',
            'backup_retention' => 20,
            'site_title' => 'Sneeze Admin',
            // Images at or under this size are embedded as data URIs on publish.
            // 0 disables inlining. Capped at 512 KiB.
            'inline_image_max_bytes' => 8192,
        ];
    }

    public static function all(): array
    {
        return self::$data ?? self::defaults();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    public static function isSetupComplete(): bool
    {
        return (bool) self::get('setup_complete', false)
            && is_string(self::get('site_root'))
            && self::get('site_root') !== '';
    }

    public static function siteRoot(): string
    {
        $root = (string) self::get('site_root', '');
        $real = realpath($root);
        return $real ?: $root;
    }

    public static function siteWebBase(): string
    {
        $base = (string) self::get('site_web_base', '/');
        if ($base === '') {
            return '/';
        }
        if ($base[0] !== '/') {
            $base = '/' . $base;
        }
        return rtrim($base, '/') === '' ? '/' : rtrim($base, '/') . '/';
    }

    public static function uploadDir(): string
    {
        return self::sanitizeUploadDir((string) self::get('upload_dir', 'uploads'), false);
    }

    /**
     * Folder inside the site root where published media is copied.
     */
    public static function sanitizeUploadDir(string $dir, bool $strict = true): string
    {
        $dir = str_replace('\\', '/', trim($dir));
        $dir = trim($dir, '/');
        if ($dir === '') {
            $dir = 'uploads';
        }
        $valid = !str_contains($dir, '..')
            && !str_starts_with($dir, '/')
            && preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#', $dir) === 1;
        if (!$valid) {
            if ($strict) {
                Response::error('Asset folder must be a relative path (letters, numbers, /, -, _)');
            }
            return 'uploads';
        }
        return $dir;
    }

    public static function draftUploadDir(): string
    {
        return self::dataDir() . '/uploads';
    }

    public static function sneezeBasePath(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/sneeze/index.php';
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '/' || $dir === '.' || $dir === '') {
            return '';
        }
        return rtrim($dir, '/');
    }

    public static function sneezeOrigin(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    public static function backupRetention(): int
    {
        return max(1, (int) self::get('backup_retention', 20));
    }

    /**
     * Bytes at or under which published images are inlined as base64 data URIs.
     * 0 disables inlining. Values above 512 KiB are capped.
     */
    public static function inlineImageMaxBytes(): int
    {
        return self::normalizeInlineImageMaxBytes(self::get('inline_image_max_bytes', 8192));
    }

    public static function normalizeInlineImageMaxBytes(mixed $bytes): int
    {
        $n = (int) $bytes;
        if ($n < 0) {
            $n = 0;
        }
        return min($n, 524288);
    }

    /**
     * SQLite is the source of truth for settings after the database is connected.
     * Keys missing from the table keep their defaults (so new settings apply
     * without a migration of every existing install).
     */
    public static function hydrateFromDatabase(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();
        if (!$rows) {
            return;
        }
        $data = self::defaults();
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data[(string) $row['key']] = $decoded;
            }
        }
        self::$data = $data;
    }

    public static function save(): void
    {
        $dir = dirname(self::$configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        if (isset(self::$data['inline_image_max_bytes'])) {
            self::$data['inline_image_max_bytes'] = self::normalizeInlineImageMaxBytes(self::$data['inline_image_max_bytes']);
        }
        file_put_contents(
            self::$configPath,
            json_encode(self::$data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        try {
            $pdo = Database::pdo();
        } catch (RuntimeException) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO settings (key, value) VALUES (?, ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value'
            );
            foreach (self::$data as $key => $value) {
                $stmt->execute([(string) $key, json_encode($value)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function detectWebBase(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/sneeze/index.php';
        $dir = str_replace('\\', '/', dirname($script));
        $dir = rtrim($dir, '/');
        if (str_ends_with($dir, '/sneeze')) {
            $base = substr($dir, 0, -strlen('/sneeze'));
        } else {
            $base = $dir;
        }
        if ($base === '' || $base === '.') {
            return '/';
        }
        return rtrim($base, '/') . '/';
    }
}
