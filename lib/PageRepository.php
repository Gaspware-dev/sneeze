<?php

declare(strict_types=1);

class PageRepository
{
    public static function all(): array
    {
        $rows = Database::pdo()->query(
            'SELECT p.*, t.name AS template_name, t.slug AS template_slug
             FROM pages p
             LEFT JOIN templates t ON t.id = p.template_id
             ORDER BY CASE p.slug WHEN \'index\' THEN 0 ELSE 1 END, p.title'
        )->fetchAll();
        return array_map([self::class, 'formatPage'], $rows);
    }

    public static function get(int $id, bool $withTree = true): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, t.name AS template_name, t.slug AS template_slug
             FROM pages p
             LEFT JOIN templates t ON t.id = p.template_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Page not found', 404);
        }
        $page = self::formatPage($row);
        if ($withTree) {
            $page['blocks'] = self::tree($id);
        }
        return $page;
    }

    public static function formatPage(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['template_id'] = $row['template_id'] !== null ? (int) $row['template_id'] : null;
        $row['header_mode'] = self::normalizeMode($row['header_mode'] ?? 'inherit');
        $row['footer_mode'] = self::normalizeMode($row['footer_mode'] ?? 'inherit');
        $row['header_html'] = (string) ($row['header_html'] ?? '');
        $row['footer_html'] = (string) ($row['footer_html'] ?? '');
        $row['disabled_stylesheet_ids'] = self::idList($row['disabled_stylesheet_ids'] ?? '[]');
        $row['disabled_script_ids'] = self::idList($row['disabled_script_ids'] ?? '[]');
        return $row;
    }

    public static function normalizeMode(mixed $mode): string
    {
        $mode = (string) $mode;
        return in_array($mode, ['inherit', 'custom', 'none'], true) ? $mode : 'inherit';
    }

    /**
     * @return list<int>
     */
    public static function idList(mixed $value): array
    {
        if (is_array($value)) {
            $raw = $value;
        } else {
            $decoded = json_decode((string) $value, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $ids = [];
        foreach ($raw as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public static function create(array $body): array
    {
        $title = trim((string) ($body['title'] ?? ''));
        $slug = self::slug((string) ($body['slug'] ?? $title), $title);
        if ($title === '' || $slug === '') {
            Response::error('title required');
        }
        $templateId = (int) ($body['template_id'] ?? 0);
        if ($templateId < 1) {
            $first = Database::pdo()->query('SELECT id FROM templates ORDER BY id LIMIT 1')->fetch();
            $templateId = $first ? (int) $first['id'] : 0;
        }
        if ($templateId < 1) {
            Response::error('Create a template first');
        }

        try {
            Database::pdo()->prepare(
                'INSERT INTO pages (title, slug, template_id, seo_title, seo_description, custom_css, custom_js,
                    header_mode, footer_mode, header_html, footer_html, disabled_stylesheet_ids, disabled_script_ids)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $title,
                $slug,
                $templateId,
                trim((string) ($body['seo_title'] ?? $title)),
                (string) ($body['seo_description'] ?? ''),
                (string) ($body['custom_css'] ?? ''),
                (string) ($body['custom_js'] ?? ''),
                self::normalizeMode($body['header_mode'] ?? 'inherit'),
                self::normalizeMode($body['footer_mode'] ?? 'inherit'),
                (string) ($body['header_html'] ?? ''),
                (string) ($body['footer_html'] ?? ''),
                Json::encode(self::idList($body['disabled_stylesheet_ids'] ?? [])),
                Json::encode(self::idList($body['disabled_script_ids'] ?? [])),
            ]);
        } catch (PDOException $e) {
            Response::error('Page slug already exists', 409);
        }

        return self::get((int) Database::pdo()->lastInsertId());
    }

    public static function update(int $id, array $body): array
    {
        $page = self::get($id, false);
        $title = trim((string) ($body['title'] ?? $page['title']));
        $slug = isset($body['slug']) ? self::slug((string) $body['slug'], $title) : $page['slug'];
        $templateId = (int) ($body['template_id'] ?? $page['template_id']);
        $seoTitle = array_key_exists('seo_title', $body) ? trim((string) $body['seo_title']) : ($page['seo_title'] ?: $title);
        if ($seoTitle === '') {
            $seoTitle = $title;
        }
        $headerMode = array_key_exists('header_mode', $body)
            ? self::normalizeMode($body['header_mode'])
            : $page['header_mode'];
        $footerMode = array_key_exists('footer_mode', $body)
            ? self::normalizeMode($body['footer_mode'])
            : $page['footer_mode'];
        $headerHtml = array_key_exists('header_html', $body)
            ? (string) $body['header_html']
            : $page['header_html'];
        $footerHtml = array_key_exists('footer_html', $body)
            ? (string) $body['footer_html']
            : $page['footer_html'];
        $disabledSheets = array_key_exists('disabled_stylesheet_ids', $body)
            ? self::idList($body['disabled_stylesheet_ids'])
            : $page['disabled_stylesheet_ids'];
        $disabledScripts = array_key_exists('disabled_script_ids', $body)
            ? self::idList($body['disabled_script_ids'])
            : $page['disabled_script_ids'];

        try {
            Database::pdo()->prepare(
                'UPDATE pages SET title = ?, slug = ?, template_id = ?, seo_title = ?, seo_description = ?,
                    custom_css = ?, custom_js = ?, header_mode = ?, footer_mode = ?, header_html = ?, footer_html = ?,
                    disabled_stylesheet_ids = ?, disabled_script_ids = ?, updated_at = datetime(\'now\')
                 WHERE id = ?'
            )->execute([
                $title,
                $slug,
                $templateId,
                $seoTitle,
                (string) ($body['seo_description'] ?? $page['seo_description']),
                (string) ($body['custom_css'] ?? $page['custom_css']),
                (string) ($body['custom_js'] ?? $page['custom_js']),
                $headerMode,
                $footerMode,
                $headerHtml,
                $footerHtml,
                Json::encode($disabledSheets),
                Json::encode($disabledScripts),
                $id,
            ]);
        } catch (PDOException $e) {
            Response::error('Page slug already exists', 409);
        }

        if (isset($body['blocks']) && is_array($body['blocks'])) {
            self::saveTree($id, $body['blocks']);
        }

        return self::get($id);
    }

    public static function delete(int $id): void
    {
        $page = self::get($id, false);
        if ($page['slug'] === 'index') {
            Response::error('Cannot delete the home page');
        }
        Database::pdo()->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);
    }

    public static function tree(int $pageId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT b.*, t.name AS type_name, t.slug AS type_slug, t.category, t.icon,
                    t.schema_json, t.html AS type_html, t.css AS type_css, t.js AS type_js, t.is_system
             FROM page_blocks b
             JOIN block_types t ON t.id = b.block_type_id
             WHERE b.page_id = ?
             ORDER BY b.parent_id IS NOT NULL, b.sort_order, b.id'
        );
        $stmt->execute([$pageId]);
        $rows = $stmt->fetchAll();

        $byParent = [];
        foreach ($rows as $row) {
            $block = self::formatBlock($row);
            $pid = $block['parent_id'] ?? 0;
            $byParent[$pid][] = $block;
        }

        $nest = function (int $parentId) use (&$nest, $byParent): array {
            $out = [];
            foreach ($byParent[$parentId] ?? [] as $node) {
                $node['children'] = $nest((int) $node['id']);
                $out[] = $node;
            }
            return $out;
        };

        return $nest(0);
    }

    public static function formatBlock(array $row): array
    {
        $schema = Json::object($row['schema_json'] ?? '{}');
        return [
            'id' => (int) $row['id'],
            'page_id' => (int) $row['page_id'],
            'block_type_id' => (int) $row['block_type_id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'slot' => $row['slot'] ?: 'content',
            'sort_order' => (int) $row['sort_order'],
            'data' => Json::object($row['data_json'] ?? '{}'),
            'cms_source_id' => $row['cms_source_id'] !== null ? (int) $row['cms_source_id'] : null,
            'cms_entry_key' => $row['cms_entry_key'],
            'children' => [],
            'block_type' => [
                'id' => (int) $row['block_type_id'],
                'name' => $row['type_name'],
                'slug' => $row['type_slug'],
                'category' => $row['category'],
                'icon' => $row['icon'],
                'schema' => $schema,
                'html' => $row['type_html'],
                'css' => $row['type_css'],
                'js' => $row['type_js'],
                'is_system' => (int) ($row['is_system'] ?? 0),
            ],
        ];
    }

    public static function saveTree(int $pageId, array $tree): array
    {
        $pdo = Database::pdo();
        $keep = [];

        $walk = function (array $nodes, ?int $parentId) use (&$walk, &$keep, $pageId, $pdo): void {
            foreach (array_values($nodes) as $index => $node) {
                if (!is_array($node)) {
                    continue;
                }
                $typeId = (int) ($node['block_type_id'] ?? $node['block_type']['id'] ?? 0);
                if ($typeId < 1) {
                    continue;
                }
                $slot = trim((string) ($node['slot'] ?? 'content')) ?: 'content';
                $data = $node['data'] ?? [];
                if (!is_array($data)) {
                    $data = [];
                }
                $data = self::sanitizeData($typeId, $data);
                $cmsSource = isset($node['cms_source_id']) && $node['cms_source_id'] !== '' && $node['cms_source_id'] !== null
                    ? (int) $node['cms_source_id']
                    : null;
                $cmsKey = isset($node['cms_entry_key']) ? trim((string) $node['cms_entry_key']) : null;
                if ($cmsKey === '') {
                    $cmsKey = null;
                }

                $id = (int) ($node['id'] ?? 0);
                $exists = false;
                if ($id > 0) {
                    $check = $pdo->prepare('SELECT id FROM page_blocks WHERE id = ? AND page_id = ?');
                    $check->execute([$id, $pageId]);
                    $exists = (bool) $check->fetch();
                }

                if ($exists) {
                    $pdo->prepare(
                        'UPDATE page_blocks SET block_type_id = ?, parent_id = ?, slot = ?, sort_order = ?, data_json = ?,
                            cms_source_id = ?, cms_entry_key = ?, updated_at = datetime(\'now\')
                         WHERE id = ?'
                    )->execute([$typeId, $parentId, $slot, $index, Json::encode($data), $cmsSource, $cmsKey, $id]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO page_blocks (page_id, block_type_id, parent_id, slot, sort_order, data_json, cms_source_id, cms_entry_key)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$pageId, $typeId, $parentId, $slot, $index, Json::encode($data), $cmsSource, $cmsKey]);
                    $id = (int) $pdo->lastInsertId();
                }

                $keep[] = $id;
                $children = $node['children'] ?? [];
                if (is_array($children) && $children !== []) {
                    $walk($children, $id);
                }
            }
        };

        $pdo->beginTransaction();
        try {
            $walk($tree, null);
            if ($keep === []) {
                $pdo->prepare('DELETE FROM page_blocks WHERE page_id = ?')->execute([$pageId]);
            } else {
                $placeholders = implode(',', array_fill(0, count($keep), '?'));
                $params = $keep;
                array_unshift($params, $pageId);
                $pdo->prepare("DELETE FROM page_blocks WHERE page_id = ? AND id NOT IN ($placeholders)")->execute($params);
            }
            $pdo->prepare('UPDATE pages SET updated_at = datetime(\'now\'), status = CASE WHEN status = \'published\' THEN \'draft\' ELSE status END WHERE id = ?')
                ->execute([$pageId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return self::tree($pageId);
    }

    public static function addBlock(int $pageId, array $body): array
    {
        $typeId = (int) ($body['block_type_id'] ?? 0);
        BlockTypeRepository::get($typeId);
        $parentId = isset($body['parent_id']) && $body['parent_id'] ? (int) $body['parent_id'] : null;
        $slot = trim((string) ($body['slot'] ?? 'content')) ?: 'content';
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM page_blocks WHERE page_id = ? AND IFNULL(parent_id, 0) = ? AND slot = ?'
        );
        $stmt->execute([$pageId, $parentId ?? 0, $slot]);
        $order = (int) $stmt->fetchColumn();

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        Database::pdo()->prepare(
            'INSERT INTO page_blocks (page_id, block_type_id, parent_id, slot, sort_order, data_json) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$pageId, $typeId, $parentId, $slot, $order, Json::encode($data)]);

        return self::get($pageId);
    }

    private static function sanitizeData(int $typeId, array $data): array
    {
        $type = BlockTypeRepository::get($typeId);
        $fields = $type['schema']['fields'] ?? [];
        $allowed = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $value = $data[$name] ?? '';
            $kind = $field['type'] ?? 'text';
            if ($kind === 'richtext' || $kind === 'code') {
                $allowed[$name] = $kind === 'richtext' ? (new Sanitizer())->clean((string) $value) : (string) $value;
            } elseif ($kind === 'number') {
                $allowed[$name] = is_numeric($value) ? (0 + $value) : 0;
            } else {
                $allowed[$name] = is_scalar($value) ? (string) $value : '';
            }
        }
        return $allowed;
    }

    public static function slug(string $value, string $fallback = ''): string
    {
        $value = strtolower(trim($value !== '' ? $value : $fallback));
        $value = preg_replace('/[^a-z0-9\/-]+/', '-', $value) ?? '';
        $value = trim($value, '/-');
        if ($value === 'index' || $value === 'home') {
            return 'index';
        }
        return $value;
    }

    public static function markPublished(int $id): void
    {
        Database::pdo()->prepare(
            'UPDATE pages SET status = \'published\', published_at = datetime(\'now\'), updated_at = datetime(\'now\') WHERE id = ?'
        )->execute([$id]);
    }

    public static function addRevision(int $pageId, string $html, ?int $userId): void
    {
        Database::pdo()->prepare(
            'INSERT INTO page_revisions (page_id, html, created_by) VALUES (?, ?, ?)'
        )->execute([$pageId, $html, $userId]);

        $keep = Database::pdo()->prepare(
            'SELECT id FROM page_revisions WHERE page_id = ? ORDER BY id DESC LIMIT 30'
        );
        $keep->execute([$pageId]);
        $ids = $keep->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            array_unshift($params, $pageId);
            Database::pdo()->prepare(
                "DELETE FROM page_revisions WHERE page_id = ? AND id NOT IN ($placeholders)"
            )->execute($params);
        }
    }
}
