<?php

declare(strict_types=1);

class Updater
{
    private const SKIP_TOP = ['data', '.', '..'];

    /**
     * Apply a GitHub "Download ZIP" (or flat sneeze zip) over the install dir.
     * Preserves data/ entirely.
     *
     * @param array{name?: string, tmp_name?: string, error?: int, size?: int, type?: string} $file
     * @return array{version: string, previous_version: string, files_updated: int}
     */
    public static function applyUpload(array $file): array
    {
        if (!class_exists('ZipArchive')) {
            Response::error('PHP zip extension is required to update Sneeze. Install php-zip.', 500);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            Response::error('Upload failed');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? 'update.zip');
        if ($tmp === '' || !is_file($tmp)) {
            Response::error('Upload missing');
        }
        if (!str_ends_with(strtolower($name), '.zip')) {
            Response::error('Only .zip files are accepted');
        }
        $size = (int) ($file['size'] ?? filesize($tmp) ?: 0);
        if ($size < 100 || $size > 80 * 1024 * 1024) {
            Response::error('Zip size out of range');
        }

        $dataDir = Config::dataDir();
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0750, true);
        }
        $extractRoot = $dataDir . '/update-' . bin2hex(random_bytes(8));
        if (!mkdir($extractRoot, 0750, true) && !is_dir($extractRoot)) {
            Response::error('Could not create extract directory', 500);
        }

        try {
            $zip = new ZipArchive();
            $open = $zip->open($tmp);
            if ($open !== true) {
                Response::error('Could not open zip archive');
            }
            if (!$zip->extractTo($extractRoot)) {
                $zip->close();
                Response::error('Could not extract zip archive');
            }
            $zip->close();

            $packageRoot = self::detectPackageRoot($extractRoot);
            self::validatePackage($packageRoot);

            $newVersion = self::readVersionFile($packageRoot . '/VERSION');
            $previous = Version::current();
            if ($newVersion !== '0.0.0' && Version::compare($newVersion, $previous) < 0) {
                Response::error("Refusing downgrade from {$previous} to {$newVersion}");
            }

            $updated = self::overlay($packageRoot, Config::installDir());

            return [
                'version' => Version::current(),
                'previous_version' => $previous,
                'files_updated' => $updated,
            ];
        } finally {
            self::rmTree($extractRoot);
        }
    }

    private static function detectPackageRoot(string $extractRoot): string
    {
        $entries = array_values(array_filter(scandir($extractRoot) ?: [], static fn ($e) => $e !== '.' && $e !== '..'));
        if (count($entries) === 1) {
            $only = $extractRoot . DIRECTORY_SEPARATOR . $entries[0];
            if (is_dir($only) && self::looksLikePackage($only)) {
                return $only;
            }
        }
        if (self::looksLikePackage($extractRoot)) {
            return $extractRoot;
        }
        foreach ($entries as $entry) {
            $path = $extractRoot . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && self::looksLikePackage($path)) {
                return $path;
            }
        }
        Response::error('Zip does not look like a Sneeze package (missing index.php / lib/)');
        return $extractRoot;
    }

    private static function looksLikePackage(string $dir): bool
    {
        return is_file($dir . '/index.php') && is_dir($dir . '/lib');
    }

    private static function validatePackage(string $root): void
    {
        if (!self::looksLikePackage($root)) {
            Response::error('Invalid Sneeze package');
        }
    }

    private static function readVersionFile(string $path): string
    {
        if (!is_file($path)) {
            return '0.0.0';
        }
        $raw = trim((string) file_get_contents($path));
        return $raw !== '' ? $raw : '0.0.0';
    }

    private static function overlay(string $from, string $to): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $fromReal = realpath($from) ?: $from;
        $toReal = realpath($to) ?: $to;

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $full = $item->getPathname();
            $rel = substr($full, strlen($fromReal) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($rel === false || $rel === '') {
                continue;
            }
            $top = explode('/', $rel, 2)[0];
            if (in_array($top, self::SKIP_TOP, true) || $top === 'data') {
                continue;
            }
            // Never overwrite runtime data even if nested oddly
            if (str_starts_with($rel, 'data/') || $rel === 'data') {
                continue;
            }

            $dest = $toReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0755, true);
                }
                continue;
            }
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            if (!copy($full, $dest)) {
                Response::error('Failed to copy ' . $rel, 500);
            }
            $count++;
        }
        return $count;
    }

    private static function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
