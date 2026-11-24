<?php

declare(strict_types=1);

class Publisher
{
    public function publishPage(int $pageId, ?int $userId = null): array
    {
        $page = PageRepository::get($pageId, false);
        $html = (new Compiler())->compilePage($pageId, false);
        $relative = Compiler::outputPath((string) $page['slug']);
        $this->writeSiteFile($relative, $html);
        PageRepository::addRevision($pageId, $html, $userId);
        PageRepository::markPublished($pageId);
        Auth::audit($userId ?? 0, 'publish', $relative);
        $this->publishFeedsAndSitemap();
        return [
            'ok' => true,
            'path' => $relative,
            'url' => Compiler::pageUrl((string) $page['slug']),
            'page' => PageRepository::get($pageId, false),
        ];
    }

    public function publishAll(?int $userId = null): array
    {
        $results = [];
        foreach (PageRepository::all() as $page) {
            $pageResult = $this->publishPageWithoutFeeds((int) $page['id'], $userId);
            $results[] = $pageResult;
        }
        $posts = $this->publishPosts($userId, false);
        $this->publishFeedsAndSitemap();
        return ['ok' => true, 'pages' => $results, 'posts' => $posts];
    }

    public function publishPosts(?int $userId = null, bool $writeFeeds = true): array
    {
        PostRepository::syncAllToCache();
        $compiler = new Compiler();
        $published = [];
        foreach (PostRepository::published() as $post) {
            $html = $compiler->compilePost($post, false);
            $relative = 'posts/' . $post['slug'] . '.html';
            $this->writeSiteFile($relative, $html);
            $published[] = [
                'id' => $post['id'],
                'slug' => $post['slug'],
                'path' => $relative,
                'url' => Compiler::postUrl((string) $post['slug']),
            ];
            Auth::audit($userId ?? 0, 'publish_post', $relative);
        }
        $indexHtml = $compiler->compilePostIndex(PostRepository::published(), false);
        $this->writeSiteFile('posts/index.html', $indexHtml);
        if ($writeFeeds) {
            $this->publishFeedsAndSitemap();
        }
        return [
            'ok' => true,
            'posts' => $published,
            'index' => 'posts/index.html',
        ];
    }

    public function publishFeedsAndSitemap(): void
    {
        $compiler = new Compiler();
        $posts = PostRepository::published();
        $this->writeSiteFile('feed.xml', $compiler->compileRss($posts));
        $this->writeSiteFile('sitemap.xml', $compiler->compileSitemap(
            PageRepository::all(),
            $posts
        ));
    }

    private function publishPageWithoutFeeds(int $pageId, ?int $userId = null): array
    {
        $page = PageRepository::get($pageId, false);
        $html = (new Compiler())->compilePage($pageId, false);
        $relative = Compiler::outputPath((string) $page['slug']);
        $this->writeSiteFile($relative, $html);
        PageRepository::addRevision($pageId, $html, $userId);
        PageRepository::markPublished($pageId);
        Auth::audit($userId ?? 0, 'publish', $relative);
        return [
            'ok' => true,
            'path' => $relative,
            'url' => Compiler::pageUrl((string) $page['slug']),
            'page' => PageRepository::get($pageId, false),
        ];
    }

    public function writeSiteFile(string $relativePath, string $content): void
    {
        $full = $this->resolvePath($relativePath);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (is_file($full)) {
            $this->backup($relativePath, $full);
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

    public function resolvePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            Response::error('Invalid path', 400);
        }

        $siteRoot = Config::siteRoot();
        $full = $siteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $realDir = realpath($dir);
        $root = realpath($siteRoot);
        if ($realDir === false || $root === false) {
            Response::error('Invalid path', 400);
        }
        $resolved = $realDir . DIRECTORY_SEPARATOR . basename($full);
        if (!str_starts_with($resolved, $root)) {
            Response::error('Path traversal denied', 403);
        }
        return $resolved;
    }

    public static function relativeUrlTo(string $fromFile, string $toPath): string
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
        $prefix = count($fromParts) > 0 ? str_repeat('../', count($fromParts)) : '';
        return $prefix . implode('/', $toParts);
    }

    private function backup(string $relativePath, string $fullPath): void
    {
        $backupDir = Config::dataDir() . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }
        $safe = str_replace(['/', '\\'], '__', $relativePath);
        $dir = $backupDir . '/' . $safe;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $ext = pathinfo($fullPath, PATHINFO_EXTENSION) ?: 'html';
        copy($fullPath, $dir . '/' . date('Y-m-d_His') . '.' . $ext);
        $files = glob($dir . '/*.' . $ext) ?: [];
        rsort($files);
        $keep = Config::backupRetention();
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
