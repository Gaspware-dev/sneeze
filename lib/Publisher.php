<?php

declare(strict_types=1);

class Publisher
{
    public function publishPage(int $pageId, ?int $userId = null): array
    {
        $result = $this->publishPageWithoutFeeds($pageId, $userId);
        $this->writeSitemap();
        $this->finishPublish('page', $userId, [$pageId]);
        return $result;
    }

    public function publishAll(?int $userId = null): array
    {
        $results = [];
        $pageIds = [];
        foreach (PageRepository::all() as $page) {
            $pageIds[] = (int) $page['id'];
            $results[] = $this->publishPageWithoutFeeds((int) $page['id'], $userId);
        }
        $this->writeSitemap();
        $this->finishPublish('all', $userId, $pageIds);
        return ['ok' => true, 'pages' => $results];
    }

    /**
     * @param list<int> $pageIds
     */
    private function finishPublish(string $scope, ?int $userId, array $pageIds): void
    {
        PluginHooks::action(PluginHooks::PUBLISH_FINISHED, [
            'scope' => $scope,
            'user_id' => $userId,
            'page_ids' => array_values($pageIds),
        ]);
    }

    private function writeSitemap(): void
    {
        $this->writeSiteFile('sitemap.xml', (new Compiler())->compileSitemap(PageRepository::all()));
    }

    private function publishPageWithoutFeeds(int $pageId, ?int $userId = null): array
    {
        PluginHooks::action(PluginHooks::PUBLISH_BEFORE, [
            'page_id' => $pageId,
            'user_id' => $userId,
        ]);
        $page = PageRepository::get($pageId, false);
        $html = (new Compiler())->compilePage($pageId, false);
        $relative = Compiler::outputPath((string) $page['slug']);
        $this->writeSiteFile($relative, $html);
        PageRepository::addRevision($pageId, $html, $userId);
        PageRepository::markPublished($pageId);
        Auth::audit($userId ?? 0, 'publish', $relative);
        $result = [
            'ok' => true,
            'path' => $relative,
            'url' => Compiler::pageUrl((string) $page['slug']),
            'page' => PageRepository::get($pageId, false),
        ];
        PluginHooks::action(PluginHooks::PUBLISH_AFTER, [
            'page_id' => $pageId,
            'user_id' => $userId,
            'result' => $result,
        ]);
        return $result;
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
            throw new PluginException('Invalid path');
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
            throw new PluginException('Invalid path');
        }
        $resolved = $realDir . DIRECTORY_SEPARATOR . basename($full);
        if (!str_starts_with($resolved, $root)) {
            throw new PluginException('Path traversal denied');
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
