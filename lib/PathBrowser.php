<?php

declare(strict_types=1);

class PathBrowser
{
    private string $installDir;
    private string $allowedRoot;

    public function __construct(string $installDir)
    {
        $this->installDir = realpath($installDir) ?: $installDir;
        $parent = realpath($this->installDir . '/..') ?: dirname($this->installDir);
        $this->allowedRoot = $parent;
    }

    public function defaultPath(): string
    {
        return realpath($this->installDir . '/..') ?: dirname($this->installDir);
    }

    public function browse(?string $path = null): array
    {
        $target = $this->resolveSafe($path ?? $this->defaultPath());
        if (!is_dir($target)) {
            Response::error('Directory not found', 404);
        }

        $dirs = [];
        $htmlFiles = [];

        foreach (scandir($target) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $target . DIRECTORY_SEPARATOR . $entry;
            if ($this->isBlocked($full)) {
                continue;
            }
            if (is_dir($full)) {
                $dirs[] = [
                    'name' => $entry,
                    'path' => $full,
                ];
            } elseif (is_file($full) && str_ends_with(strtolower($entry), '.html')) {
                $htmlFiles[] = $entry;
            }
        }

        usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        sort($htmlFiles, SORT_NATURAL | SORT_FLAG_CASE);

        $parent = dirname($target);
        $canGoUp = $this->isWithinAllowed($parent) && $parent !== $target;

        return [
            'path' => $target,
            'parent' => $canGoUp ? $parent : null,
            'directories' => $dirs,
            'html_files' => $htmlFiles,
            'writable' => is_writable($target),
            'valid' => count($htmlFiles) > 0 || $this->hasHtmlRecursive($target),
        ];
    }

    public function validateSiteRoot(string $path): array
    {
        $resolved = $this->resolveSafe($path);
        if (!is_dir($resolved)) {
            Response::error('Path is not a directory');
        }
        if (!is_readable($resolved)) {
            Response::error('Directory is not readable');
        }

        $htmlCount = $this->countHtmlFiles($resolved);
        if ($htmlCount === 0) {
            Response::error('No HTML files found in this directory');
        }

        return [
            'path' => $resolved,
            'html_count' => $htmlCount,
            'writable' => is_writable($resolved),
        ];
    }

    private function resolveSafe(string $path): string
    {
        $real = realpath($path);
        if ($real === false) {
            Response::error('Invalid path', 400);
        }
        if (!$this->isWithinAllowed($real)) {
            Response::error('Path outside allowed area', 403);
        }
        return $real;
    }

    private function isWithinAllowed(string $path): bool
    {
        $allowed = realpath($this->allowedRoot) ?: $this->allowedRoot;
        $check = realpath($path) ?: $path;
        return str_starts_with($check, $allowed);
    }

    private function isBlocked(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        $install = realpath($this->installDir) ?: $this->installDir;
        if (str_starts_with($real, $install . DIRECTORY_SEPARATOR) || $real === $install) {
            return true;
        }
        $name = basename($real);
        return $name[0] === '.' || in_array($name, ['node_modules', 'vendor', 'data'], true);
    }

    private function hasHtmlRecursive(string $dir, int $depth = 0): bool
    {
        if ($depth > 5) {
            return false;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            if ($this->isBlocked($full)) {
                continue;
            }
            if (is_file($full) && str_ends_with(strtolower($entry), '.html')) {
                return true;
            }
            if (is_dir($full) && $this->hasHtmlRecursive($full, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    private function countHtmlFiles(string $dir): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if ($this->isBlocked($path)) {
                continue;
            }
            if (str_ends_with(strtolower($file->getFilename()), '.html')) {
                $count++;
            }
        }
        return $count;
    }
}
