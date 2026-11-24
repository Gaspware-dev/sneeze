<?php

declare(strict_types=1);

class CmsRepository
{
    public static function sources(): array
    {
        $rows = Database::pdo()->query('SELECT * FROM cms_sources ORDER BY name')->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['config'] = Json::object($row['config_json'] ?? '{}');
            unset($row['config_json']);
        }
        unset($row);
        return $rows;
    }

    public static function get(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM cms_sources WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('CMS source not found', 404);
        }
        $row['id'] = (int) $row['id'];
        $row['config'] = Json::object($row['config_json'] ?? '{}');
        unset($row['config_json']);
        return $row;
    }

    public static function save(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            Response::error('name required');
        }
        $adapter = trim((string) ($body['adapter'] ?? 'http_json')) ?: 'http_json';
        $config = $body['config'] ?? [];
        if (!is_array($config)) {
            $config = [];
        }
        $json = Json::encode($config);

        if (!empty($body['id'])) {
            $id = (int) $body['id'];
            $existing = self::get($id);
            if (($existing['adapter'] ?? '') === 'builtin_posts') {
                Database::pdo()->prepare(
                    "UPDATE cms_sources SET name = ?, updated_at = datetime('now') WHERE id = ?"
                )->execute([$name, $id]);
                return self::get($id);
            }
            Database::pdo()->prepare(
                'UPDATE cms_sources SET name = ?, adapter = ?, config_json = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([$name, $adapter, $json, $id]);
            return self::get($id);
        }

        Database::pdo()->prepare(
            'INSERT INTO cms_sources (name, adapter, config_json) VALUES (?, ?, ?)'
        )->execute([$name, $adapter, $json]);
        return self::get((int) Database::pdo()->lastInsertId());
    }

    public static function delete(int $id): void
    {
        $source = self::get($id);
        if (($source['adapter'] ?? '') === 'builtin_posts') {
            Response::error('Cannot delete the built-in posts source');
        }
        Database::pdo()->prepare('DELETE FROM cms_sources WHERE id = ?')->execute([$id]);
    }

    public static function builtinSourceId(): ?int
    {
        $stmt = Database::pdo()->prepare("SELECT id FROM cms_sources WHERE adapter = 'builtin_posts' LIMIT 1");
        $stmt->execute();
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public static function ensureBuiltinSource(): int
    {
        $existing = self::builtinSourceId();
        if ($existing) {
            return $existing;
        }
        Database::pdo()->prepare(
            "INSERT INTO cms_sources (name, adapter, config_json) VALUES ('Built-in posts', 'builtin_posts', '{}')"
        )->execute();
        return (int) Database::pdo()->lastInsertId();
    }

    public static function entry(int $sourceId, string $externalId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM cms_entries WHERE source_id = ? AND external_id = ?'
        );
        $stmt->execute([$sourceId, $externalId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['data'] = Json::object($row['data_json'] ?? '{}');
        unset($row['data_json']);
        return $row;
    }

    public static function entries(int $sourceId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM cms_entries WHERE source_id = ? ORDER BY synced_at DESC'
        );
        $stmt->execute([$sourceId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['data'] = Json::object($row['data_json'] ?? '{}');
            unset($row['data_json']);
        }
        unset($row);
        return $rows;
    }

    public static function upsertEntry(int $sourceId, string $externalId, array $data): void
    {
        Database::pdo()->prepare(
            'INSERT INTO cms_entries (source_id, external_id, data_json, synced_at)
             VALUES (?, ?, ?, datetime(\'now\'))
             ON CONFLICT(source_id, external_id) DO UPDATE SET data_json = excluded.data_json, synced_at = excluded.synced_at'
        )->execute([$sourceId, $externalId, Json::encode($data)]);
    }

    public static function applyBindings(int $sourceId): int
    {
        $entries = [];
        foreach (self::entries($sourceId) as $entry) {
            $entries[$entry['external_id']] = $entry['data'];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, data_json, cms_entry_key FROM page_blocks WHERE cms_source_id = ? AND cms_entry_key IS NOT NULL'
        );
        $stmt->execute([$sourceId]);
        $updated = 0;
        foreach ($stmt->fetchAll() as $block) {
            $key = (string) $block['cms_entry_key'];
            if (!isset($entries[$key])) {
                continue;
            }
            $current = Json::object($block['data_json']);
            $merged = array_merge($current, $entries[$key]);
            Database::pdo()->prepare(
                'UPDATE page_blocks SET data_json = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([Json::encode($merged), $block['id']]);
            $updated++;
        }
        return $updated;
    }

    public static function markSynced(int $id, ?string $error = null): void
    {
        Database::pdo()->prepare(
            'UPDATE cms_sources SET last_synced_at = datetime(\'now\'), last_error = ?, updated_at = datetime(\'now\') WHERE id = ?'
        )->execute([$error, $id]);
    }
}
