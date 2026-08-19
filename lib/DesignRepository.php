<?php

declare(strict_types=1);

class DesignRepository
{
    public static function tokens(): array
    {
        $row = Database::pdo()->query('SELECT tokens_json FROM design_tokens WHERE id = 1')->fetch();
        if (!$row) {
            $tokens = Seed::tokens();
            Database::pdo()->prepare('INSERT INTO design_tokens (id, tokens_json) VALUES (1, ?)')
                ->execute([Json::encode($tokens)]);
            return $tokens;
        }
        return Json::object($row['tokens_json']);
    }

    public static function saveTokens(array $tokens): array
    {
        Database::pdo()->prepare('INSERT INTO design_tokens (id, tokens_json) VALUES (1, ?)
            ON CONFLICT(id) DO UPDATE SET tokens_json = excluded.tokens_json')
            ->execute([Json::encode($tokens)]);
        return $tokens;
    }

    public static function stylesheets(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM stylesheets ORDER BY sort_order, id'
        )->fetchAll();
    }

    public static function saveStylesheet(array $row): array
    {
        if (!empty($row['id'])) {
            Database::pdo()->prepare(
                'UPDATE stylesheets SET name = ?, css = ?, sort_order = ?, enabled = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([
                $row['name'] ?? 'Untitled',
                $row['css'] ?? '',
                (int) ($row['sort_order'] ?? 0),
                !empty($row['enabled']) ? 1 : 0,
                (int) $row['id'],
            ]);
            return self::stylesheet((int) $row['id']);
        }
        Database::pdo()->prepare(
            'INSERT INTO stylesheets (name, css, sort_order, enabled) VALUES (?, ?, ?, ?)'
        )->execute([
            $row['name'] ?? 'Untitled',
            $row['css'] ?? '',
            (int) ($row['sort_order'] ?? 0),
            !empty($row['enabled']) ? 1 : 0,
        ]);
        return self::stylesheet((int) Database::pdo()->lastInsertId());
    }

    public static function stylesheet(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM stylesheets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Stylesheet not found', 404);
        }
        return $row;
    }

    public static function deleteStylesheet(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM stylesheets WHERE id = ?')->execute([$id]);
    }

    public static function scripts(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM scripts ORDER BY sort_order, id'
        )->fetchAll();
    }

    public static function saveScript(array $row): array
    {
        $placement = ($row['placement'] ?? 'footer') === 'head' ? 'head' : 'footer';
        if (!empty($row['id'])) {
            Database::pdo()->prepare(
                'UPDATE scripts SET name = ?, js = ?, placement = ?, sort_order = ?, enabled = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([
                $row['name'] ?? 'Untitled',
                $row['js'] ?? '',
                $placement,
                (int) ($row['sort_order'] ?? 0),
                !empty($row['enabled']) ? 1 : 0,
                (int) $row['id'],
            ]);
            return self::script((int) $row['id']);
        }
        Database::pdo()->prepare(
            'INSERT INTO scripts (name, js, placement, sort_order, enabled) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $row['name'] ?? 'Untitled',
            $row['js'] ?? '',
            $placement,
            (int) ($row['sort_order'] ?? 0),
            !empty($row['enabled']) ? 1 : 0,
        ]);
        return self::script((int) Database::pdo()->lastInsertId());
    }

    public static function script(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM scripts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Script not found', 404);
        }
        return $row;
    }

    public static function deleteScript(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM scripts WHERE id = ?')->execute([$id]);
    }

    public static function tokensToCss(array $tokens, string $prefix = ''): string
    {
        $lines = [];
        self::flattenTokens($tokens, $prefix, $lines);
        if ($lines === []) {
            return '';
        }
        return ":root {\n  " . implode("\n  ", $lines) . "\n}\n";
    }

    private static function flattenTokens(array $tokens, string $prefix, array &$lines): void
    {
        foreach ($tokens as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '-' . $key;
            if (is_array($value)) {
                self::flattenTokens($value, $name, $lines);
                continue;
            }
            $lines[] = '--' . $name . ': ' . $value . ';';
        }
    }
}
