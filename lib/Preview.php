<?php

declare(strict_types=1);

class Preview
{
    public function render(string $html, string $relativePath): string
    {
        $base = (new FileManager())->webPathForFile($relativePath);
        $baseTag = '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">';

        if (preg_match('/<head[^>]*>/i', $html)) {
            return preg_replace('/<head([^>]*)>/i', '<head$1>' . $baseTag, $html, 1) ?? $html;
        }

        if (preg_match('/<html[^>]*>/i', $html)) {
            return preg_replace('/<html([^>]*)>/i', '<html$1><head>' . $baseTag . '</head>', $html, 1) ?? $html;
        }

        return '<!DOCTYPE html><html><head>' . $baseTag . '<meta charset="UTF-8"></head><body>' . $html . '</body></html>';
    }

    public function liveUrl(string $relativePath): string
    {
        $base = rtrim(Config::siteWebBase(), '/');
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($base === '') {
            return '/' . $path;
        }
        return $base . '/' . $path;
    }
}
