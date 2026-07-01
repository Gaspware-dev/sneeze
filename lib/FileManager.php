<?php

declare(strict_types=1);

class FileManager
{
    private string $siteRoot;
    private string $installDir;
    private string $backupDir;
    private int $retention;

    public function __construct()
    {
        $this->siteRoot = Config::siteRoot();
        $this->installDir = Config::installDir();
        $this->backupDir = Config::dataDir() . '/backups';
        $this->retention = Config::backupRetention();

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0750, true);
        }
    }

    public function listHtmlFiles(): array
    {
        $files = [];
        $root = realpath($this->siteRoot);
        if (!$root) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if ($this->shouldExclude($path)) {
                continue;
            }
            if (!str_ends_with(strtolower($file->getFilename()), '.html')) {
                continue;
            }
            $relative = $this->relativePath($path);
            $files[] = [
                'path' => $relative,
                'name' => $file->getFilename(),
                'modified' => date('c', $file->getMTime()),
            ];
        }

        usort($files, fn($a, $b) => strcasecmp($a['path'], $b['path']));
        return $files;
    }

    public function read(string $relativePath, bool $backup = true): string
    {
        $full = $this->resolvePath($relativePath);
        if (!is_file($full)) {
            Response::error('File not found', 404);
        }
        if ($backup) {
            $this->createBackup($relativePath, $full);
        }
        return file_get_contents($full) ?: '';
    }

    public function write(string $relativePath, string $content): void
    {
        $full = $this->resolvePath($relativePath);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmp = $full . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            Response::error('Failed to write file', 500);
        }
        if (!rename($tmp, $full)) {
            @unlink($tmp);
            Response::error('Failed to save file', 500);
        }
    }

    public function relativePath(string $absolutePath): string
    {
        $root = realpath($this->siteRoot) ?: $this->siteRoot;
        $abs = realpath($absolutePath) ?: $absolutePath;
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $abs = str_replace('\\', '/', $abs);
        if (str_starts_with($abs, $root . '/')) {
            return substr($abs, strlen($root) + 1);
        }
        return basename($abs);
    }

    public function resolvePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');
        if (str_contains($relativePath, '..')) {
            Response::error('Invalid path', 400);
        }

        $full = $this->siteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $real = realpath(dirname($full));
        $root = realpath($this->siteRoot);

        if ($real === false || $root === false) {
            Response::error('Invalid path', 400);
        }

        $basename = basename($full);
        $resolved = $real . DIRECTORY_SEPARATOR . $basename;

        if (!str_starts_with($resolved, $root)) {
            Response::error('Path traversal denied', 403);
        }

        return $resolved;
    }

    public function webPathForFile(string $relativePath): string
    {
        $base = Config::siteWebBase();
        $dir = str_replace('\\', '/', dirname($relativePath));
        if ($dir === '.' || $dir === '') {
            return $base;
        }
        return $base . trim($dir, '/') . '/';
    }

    public function relativeUrlTo(string $fromFile, string $toPath): string
    {
        $fromDir = str_replace('\\', '/', dirname($fromFile));
        if ($fromDir === '.') {
            $fromDir = '';
        }
        $toPath = str_replace('\\', '/', ltrim($toPath, '/'));

        $fromParts = $fromDir === '' ? [] : explode('/', $fromDir);
        $toParts = explode('/', $toPath);

        while ($fromParts && $toParts && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        $ups = count($fromParts);
        $prefix = $ups > 0 ? str_repeat('../', $ups) : '';
        return $prefix . implode('/', $toParts);
    }

    private function createBackup(string $relativePath, string $fullPath): void
    {
        $safe = str_replace(['/', '\\'], '__', $relativePath);
        $dir = $this->backupDir . '/' . $safe;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $timestamp = date('Y-m-d_His');
        $dest = $dir . '/' . $timestamp . '.html';
        copy($fullPath, $dest);
        $this->pruneBackups($dir);
    }

    private function pruneBackups(string $dir): void
    {
        $files = glob($dir . '/*.html') ?: [];
        rsort($files);
        foreach (array_slice($files, $this->retention) as $old) {
            @unlink($old);
        }
    }

    private function shouldExclude(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return true;
        }
        $install = realpath($this->installDir) ?: $this->installDir;
        if (str_starts_with($real, $install)) {
            return true;
        }
        $parts = explode(DIRECTORY_SEPARATOR, $real);
        foreach ($parts as $part) {
            if ($part !== '' && $part[0] === '.') {
                return true;
            }
            if (in_array($part, ['node_modules', 'vendor'], true)) {
                return true;
            }
        }
        return false;
    }
}
