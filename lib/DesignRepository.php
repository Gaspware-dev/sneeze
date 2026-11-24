<?php

declare(strict_types=1);

class DesignRepository
{
    public static function ensureChrome(): void
    {
        $row = Database::pdo()->query('SELECT id FROM site_chrome WHERE id = 1')->fetch();
        if ($row) {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO site_chrome (id, header_html, footer_html) VALUES (1, ?, ?)'
        )->execute([Seed::defaultHeader(), Seed::defaultFooter()]);
    }

    public static function chrome(): array
    {
        self::ensureChrome();
        $row = Database::pdo()->query('SELECT header_html, footer_html, updated_at FROM site_chrome WHERE id = 1')->fetch();
        return [
            'header_html' => (string) ($row['header_html'] ?? ''),
            'footer_html' => (string) ($row['footer_html'] ?? ''),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public static function saveChrome(array $body): array
    {
        self::ensureChrome();
        Database::pdo()->prepare(
            'UPDATE site_chrome SET header_html = ?, footer_html = ?, updated_at = datetime(\'now\') WHERE id = 1'
        )->execute([
            (string) ($body['header_html'] ?? ''),
            (string) ($body['footer_html'] ?? ''),
        ]);
        return self::chrome();
    }

    /**
     * Store chrome only when still empty (first import wins).
     */
    public static function fillChromeIfEmpty(string $header, string $footer): void
    {
        self::ensureChrome();
        $current = self::chrome();
        $header = trim($header);
        $footer = trim($footer);
        $nextHeader = trim($current['header_html']) === '' && $header !== '' ? $header : $current['header_html'];
        $nextFooter = trim($current['footer_html']) === '' && $footer !== '' ? $footer : $current['footer_html'];
        if ($nextHeader === $current['header_html'] && $nextFooter === $current['footer_html']) {
            return;
        }
        self::saveChrome([
            'header_html' => $nextHeader,
            'footer_html' => $nextFooter,
        ]);
    }

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
