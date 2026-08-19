<?php

declare(strict_types=1);

class BlockTypeRepository
{
    public static function all(): array
    {
        $rows = Database::pdo()->query('SELECT * FROM block_types ORDER BY category, name')->fetchAll();
        return array_map([self::class, 'format'], $rows);
    }

    public static function get(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM block_types WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Block type not found', 404);
        }
        return self::format($row);
    }

    public static function format(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['is_system'] = (int) ($row['is_system'] ?? 0);
        $row['schema'] = Json::object($row['schema_json'] ?? '{}');
        unset($row['schema_json']);
        return $row;
    }

    public static function save(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $slug = TemplateRepository::slug((string) ($body['slug'] ?? $name));
        if ($name === '' || $slug === '') {
            Response::error('name required');
        }

        $schema = $body['schema'] ?? Json::object($body['schema_json'] ?? '{}');
        if (!is_array($schema)) {
            $schema = [];
        }
        $schemaJson = Json::encode($schema);
        $category = trim((string) ($body['category'] ?? 'content')) ?: 'content';
        $icon = trim((string) ($body['icon'] ?? '■')) ?: '■';

        if (!empty($body['id'])) {
            $id = (int) $body['id'];
            Database::pdo()->prepare(
                'UPDATE block_types SET name = ?, slug = ?, category = ?, icon = ?, schema_json = ?, html = ?, css = ?, js = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([
                $name,
                $slug,
                $category,
                $icon,
                $schemaJson,
                (string) ($body['html'] ?? ''),
                (string) ($body['css'] ?? ''),
                (string) ($body['js'] ?? ''),
                $id,
            ]);
            return self::get($id);
        }

        try {
            Database::pdo()->prepare(
                'INSERT INTO block_types (name, slug, category, icon, schema_json, html, css, js, is_system) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
            )->execute([
                $name,
                $slug,
                $category,
                $icon,
                $schemaJson,
                (string) ($body['html'] ?? ''),
                (string) ($body['css'] ?? ''),
                (string) ($body['js'] ?? ''),
            ]);
        } catch (PDOException $e) {
            Response::error('Block type slug already exists', 409);
        }
        return self::get((int) Database::pdo()->lastInsertId());
    }

    public static function delete(int $id): void
    {
        $type = self::get($id);
        if (!empty($type['is_system'])) {
            Response::error('Cannot delete a system block type');
        }
        $used = Database::pdo()->prepare('SELECT COUNT(*) FROM page_blocks WHERE block_type_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            Response::error('Block type is used on pages');
        }
        Database::pdo()->prepare('DELETE FROM block_types WHERE id = ?')->execute([$id]);
    }
}
