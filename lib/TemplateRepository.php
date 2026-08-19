<?php

declare(strict_types=1);

class TemplateRepository
{
    public static function all(): array
    {
        return Database::pdo()->query('SELECT * FROM templates ORDER BY name')->fetchAll();
    }

    public static function get(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM templates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Template not found', 404);
        }
        return $row;
    }

    public static function save(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $slug = self::slug((string) ($body['slug'] ?? $name));
        if ($name === '' || $slug === '') {
            Response::error('name required');
        }

        if (!empty($body['id'])) {
            $id = (int) $body['id'];
            Database::pdo()->prepare(
                'UPDATE templates SET name = ?, slug = ?, html = ?, css = ?, js = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([$name, $slug, (string) ($body['html'] ?? ''), (string) ($body['css'] ?? ''), (string) ($body['js'] ?? ''), $id]);
            return self::get($id);
        }

        try {
            Database::pdo()->prepare(
                'INSERT INTO templates (name, slug, html, css, js) VALUES (?, ?, ?, ?, ?)'
            )->execute([$name, $slug, (string) ($body['html'] ?? ''), (string) ($body['css'] ?? ''), (string) ($body['js'] ?? '')]);
        } catch (PDOException $e) {
            Response::error('Template slug already exists', 409);
        }
        return self::get((int) Database::pdo()->lastInsertId());
    }

    public static function delete(int $id): void
    {
        $used = Database::pdo()->prepare('SELECT COUNT(*) FROM pages WHERE template_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            Response::error('Template is used by pages');
        }
        Database::pdo()->prepare('DELETE FROM templates WHERE id = ?')->execute([$id]);
    }

    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
