<?php

declare(strict_types=1);

class PostRepository
{
    public static function all(?string $status = null): array
    {
        $sql = 'SELECT * FROM cms_posts';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY COALESCE(published_at, updated_at) DESC, id DESC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    public static function published(): array
    {
        return self::all('published');
    }

    public static function get(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM cms_posts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Post not found', 404);
        }
        return self::hydrate($row);
    }

    public static function getBySlug(string $slug): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM cms_posts WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    public static function save(array $body): array
    {
        $id = isset($body['id']) ? (int) $body['id'] : 0;
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            Response::error('title required');
        }
        $slug = self::slugify((string) ($body['slug'] ?? ''), $title);
        $status = ($body['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $markdown = (string) ($body['body_markdown'] ?? $body['markdown'] ?? '');
        $excerpt = trim((string) ($body['excerpt'] ?? ''));
        $featured = trim((string) ($body['featured_image'] ?? ''));
        $authorName = trim((string) ($body['author_name'] ?? ''));
        $authorUrl = trim((string) ($body['author_url'] ?? ''));
        $categories = self::stringList($body['categories'] ?? []);
        $tags = self::stringList($body['tags'] ?? []);
        $seoTitle = trim((string) ($body['seo_title'] ?? '')) ?: $title;
        $seoDescription = trim((string) ($body['seo_description'] ?? '')) ?: $excerpt;
        $ogImage = trim((string) ($body['og_image'] ?? '')) ?: $featured;
        $canonical = trim((string) ($body['canonical_url'] ?? ''));
        $robots = trim((string) ($body['robots'] ?? 'index,follow')) ?: 'index,follow';
        $publishedAt = $body['published_at'] ?? null;
        if ($status === 'published' && (!$publishedAt || trim((string) $publishedAt) === '')) {
            $publishedAt = date('Y-m-d H:i:s');
        }
        if ($status === 'draft') {
            $publishedAt = $publishedAt !== null && trim((string) $publishedAt) !== ''
                ? (string) $publishedAt
                : null;
        }

        self::assertUniqueSlug($slug, $id);

        $bodyHtml = (new Sanitizer())->clean(Markdown::toHtml($markdown));

        if ($id > 0) {
            Database::pdo()->prepare(
                'UPDATE cms_posts SET title = ?, slug = ?, body_markdown = ?, body_html = ?, excerpt = ?,
                 featured_image = ?, author_name = ?, author_url = ?, categories_json = ?, tags_json = ?,
                 status = ?, published_at = ?, seo_title = ?, seo_description = ?, og_image = ?,
                 canonical_url = ?, robots = ?, updated_at = datetime(\'now\')
                 WHERE id = ?'
            )->execute([
                $title, $slug, $markdown, $bodyHtml, $excerpt, $featured, $authorName, $authorUrl,
                Json::encode($categories), Json::encode($tags), $status, $publishedAt,
                $seoTitle, $seoDescription, $ogImage, $canonical, $robots, $id,
            ]);
        } else {
            Database::pdo()->prepare(
                'INSERT INTO cms_posts (
                    title, slug, body_markdown, body_html, excerpt, featured_image, author_name, author_url,
                    categories_json, tags_json, status, published_at, seo_title, seo_description, og_image,
                    canonical_url, robots
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $title, $slug, $markdown, $bodyHtml, $excerpt, $featured, $authorName, $authorUrl,
                Json::encode($categories), Json::encode($tags), $status, $publishedAt,
                $seoTitle, $seoDescription, $ogImage, $canonical, $robots,
            ]);
            $id = (int) Database::pdo()->lastInsertId();
        }

        $post = self::get($id);
        self::syncEntry($post);
        return $post;
    }

    public static function delete(int $id): void
    {
        $post = self::get($id);
        Database::pdo()->prepare('DELETE FROM cms_posts WHERE id = ?')->execute([$id]);
        $sourceId = CmsRepository::builtinSourceId();
        if ($sourceId) {
            Database::pdo()->prepare(
                'DELETE FROM cms_entries WHERE source_id = ? AND external_id = ?'
            )->execute([$sourceId, (string) $post['id']]);
        }
    }

    public static function entryPayload(array $post): array
    {
        $slug = (string) $post['slug'];
        return [
            'id' => (string) $post['id'],
            'title' => $post['title'],
            'slug' => $slug,
            'excerpt' => $post['excerpt'],
            'body' => $post['body_html'],
            'body_html' => $post['body_html'],
            'body_markdown' => $post['body_markdown'],
            'image' => $post['featured_image'],
            'featured_image' => $post['featured_image'],
            'author_name' => $post['author_name'],
            'author_url' => $post['author_url'],
            'categories' => $post['categories'],
            'tags' => $post['tags'],
            'url' => 'posts/' . $slug . '.html',
            'seo_title' => $post['seo_title'],
            'seo_description' => $post['seo_description'],
            'og_image' => $post['og_image'],
            'canonical_url' => $post['canonical_url'],
            'robots' => $post['robots'],
            'status' => $post['status'],
            'published_at' => $post['published_at'],
            'updated_at' => $post['updated_at'],
        ];
    }

    public static function syncEntry(array $post): void
    {
        $sourceId = CmsRepository::ensureBuiltinSource();
        if (($post['status'] ?? '') !== 'published') {
            Database::pdo()->prepare(
                'DELETE FROM cms_entries WHERE source_id = ? AND external_id = ?'
            )->execute([$sourceId, (string) $post['id']]);
            return;
        }
        CmsRepository::upsertEntry($sourceId, (string) $post['id'], self::entryPayload($post));
        CmsRepository::applyBindings($sourceId);
    }

    public static function syncAllToCache(): int
    {
        $sourceId = CmsRepository::ensureBuiltinSource();
        Database::pdo()->prepare('DELETE FROM cms_entries WHERE source_id = ?')->execute([$sourceId]);
        $count = 0;
        foreach (self::published() as $post) {
            CmsRepository::upsertEntry($sourceId, (string) $post['id'], self::entryPayload($post));
            $count++;
        }
        CmsRepository::applyBindings($sourceId);
        CmsRepository::markSynced($sourceId, null);
        return $count;
    }

    private static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['categories'] = Json::decodeArray($row['categories_json'] ?? '[]');
        $row['tags'] = Json::decodeArray($row['tags_json'] ?? '[]');
        unset($row['categories_json'], $row['tags_json']);
        return $row;
    }

    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== '');
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return array_values(array_unique($out));
    }

    private static function slugify(string $slug, string $title): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            $slug = $title;
        }
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'post';
        }
        return $slug;
    }

    private static function assertUniqueSlug(string $slug, int $ignoreId): void
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM cms_posts WHERE slug = ? AND id != ?');
        $stmt->execute([$slug, $ignoreId]);
        if ($stmt->fetch()) {
            Response::error('Slug already in use');
        }
    }
}
