<?php

declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    public static function connect(string $dataDir): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0750, true);
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException(
                'PHP PDO SQLite driver is not installed. See README — apt install php8.3-sqlite3'
            );
        }

        $path = $dataDir . '/sneeze.db';
        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::migrate(self::$pdo);
        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'editor' CHECK(role IN ('admin', 'editor')),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    target_path TEXT,
    ip TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    path TEXT NOT NULL,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    uploaded_by INTEGER REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    ip TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    html TEXT NOT NULL DEFAULT '',
    css TEXT NOT NULL DEFAULT '',
    js TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS block_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    category TEXT NOT NULL DEFAULT 'content',
    icon TEXT NOT NULL DEFAULT '■',
    schema_json TEXT NOT NULL DEFAULT '{}',
    html TEXT NOT NULL DEFAULT '',
    css TEXT NOT NULL DEFAULT '',
    js TEXT NOT NULL DEFAULT '',
    is_system INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS stylesheets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    css TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS scripts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    js TEXT NOT NULL DEFAULT '',
    placement TEXT NOT NULL DEFAULT 'footer' CHECK(placement IN ('head', 'footer')),
    sort_order INTEGER NOT NULL DEFAULT 0,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS design_tokens (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    tokens_json TEXT NOT NULL DEFAULT '{}'
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cms_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    adapter TEXT NOT NULL DEFAULT 'http_json',
    config_json TEXT NOT NULL DEFAULT '{}',
    last_synced_at TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    template_id INTEGER REFERENCES templates(id),
    seo_title TEXT,
    seo_description TEXT NOT NULL DEFAULT '',
    custom_css TEXT NOT NULL DEFAULT '',
    custom_js TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'published')),
    published_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS page_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    block_type_id INTEGER NOT NULL REFERENCES block_types(id),
    parent_id INTEGER REFERENCES page_blocks(id) ON DELETE CASCADE,
    slot TEXT NOT NULL DEFAULT 'content',
    sort_order INTEGER NOT NULL DEFAULT 0,
    data_json TEXT NOT NULL DEFAULT '{}',
    cms_source_id INTEGER REFERENCES cms_sources(id) ON DELETE SET NULL,
    cms_entry_key TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS page_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    html TEXT NOT NULL,
    created_by INTEGER REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cms_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES cms_sources(id) ON DELETE CASCADE,
    external_id TEXT NOT NULL,
    data_json TEXT NOT NULL DEFAULT '{}',
    synced_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(source_id, external_id)
)
SQL);

        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 3) {
            $pdo->exec('PRAGMA user_version = 3');
            $version = 3;
        }
        if ($version < 4) {
            self::migrateToV4($pdo);
            $pdo->exec('PRAGMA user_version = 4');
            $version = 4;
        }
        if ($version < 5) {
            self::migrateToV5($pdo);
            $pdo->exec('PRAGMA user_version = 5');
            $version = 5;
        }
        if ($version < 6) {
            self::migrateToV6($pdo);
            $pdo->exec('PRAGMA user_version = 6');
            $version = 6;
        }
        if ($version < 7) {
            self::migrateToV7($pdo);
            $pdo->exec('PRAGMA user_version = 7');
            $version = 7;
        }
        if ($version < 8) {
            self::migrateToV8($pdo);
            $pdo->exec('PRAGMA user_version = 8');
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM templates')->fetchColumn();
        if ($count === 0) {
            Seed::run($pdo);
        }

        DesignRepository::ensureChrome();
        ToolchainRepository::ensure();
    }

    private static function migrateToV4(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS site_chrome (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    header_html TEXT NOT NULL DEFAULT '',
    footer_html TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);

        $cols = [];
        foreach ($pdo->query('PRAGMA table_info(pages)')->fetchAll() as $col) {
            $cols[$col['name']] = true;
        }
        $alters = [
            'header_mode' => "ALTER TABLE pages ADD COLUMN header_mode TEXT NOT NULL DEFAULT 'inherit'",
            'footer_mode' => "ALTER TABLE pages ADD COLUMN footer_mode TEXT NOT NULL DEFAULT 'inherit'",
            'header_html' => "ALTER TABLE pages ADD COLUMN header_html TEXT NOT NULL DEFAULT ''",
            'footer_html' => "ALTER TABLE pages ADD COLUMN footer_html TEXT NOT NULL DEFAULT ''",
            'disabled_stylesheet_ids' => "ALTER TABLE pages ADD COLUMN disabled_stylesheet_ids TEXT NOT NULL DEFAULT '[]'",
            'disabled_script_ids' => "ALTER TABLE pages ADD COLUMN disabled_script_ids TEXT NOT NULL DEFAULT '[]'",
        ];
        foreach ($alters as $name => $sql) {
            if (!isset($cols[$name])) {
                $pdo->exec($sql);
            }
        }
    }

    private static function migrateToV5(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cms_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    body_markdown TEXT NOT NULL DEFAULT '',
    body_html TEXT NOT NULL DEFAULT '',
    excerpt TEXT NOT NULL DEFAULT '',
    featured_image TEXT NOT NULL DEFAULT '',
    author_name TEXT NOT NULL DEFAULT '',
    author_url TEXT NOT NULL DEFAULT '',
    categories_json TEXT NOT NULL DEFAULT '[]',
    tags_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft', 'published')),
    published_at TEXT,
    seo_title TEXT NOT NULL DEFAULT '',
    seo_description TEXT NOT NULL DEFAULT '',
    og_image TEXT NOT NULL DEFAULT '',
    canonical_url TEXT NOT NULL DEFAULT '',
    robots TEXT NOT NULL DEFAULT 'index,follow',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);
    }

    private static function migrateToV6(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS plugins (
    id TEXT PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 0,
    installed_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
)
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS plugin_options (
    plugin_id TEXT NOT NULL,
    option_key TEXT NOT NULL,
    value_json TEXT NOT NULL,
    PRIMARY KEY (plugin_id, option_key)
)
SQL);
    }

    /**
     * Copy leftover built-in CMS rows into plugin_options for plugin id "cms".
     * Tables and page_blocks columns stay. Skip when that plugin already has posts.
     */
    private static function migrateToV7(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'plugin_options')) {
            return;
        }
        $exists = $pdo->query(
            "SELECT 1 FROM plugin_options WHERE plugin_id = 'cms' AND option_key = 'posts' LIMIT 1"
        )->fetch();
        if ($exists) {
            return;
        }

        $postCount = self::tableCount($pdo, 'cms_posts');
        $sourceCount = self::tableCount($pdo, 'cms_sources');
        $entryCount = self::tableCount($pdo, 'cms_entries');
        if ($postCount === 0 && $sourceCount === 0 && $entryCount === 0) {
            return;
        }

        $posts = [];
        $maxPostId = 0;
        if ($postCount > 0) {
            foreach ($pdo->query('SELECT * FROM cms_posts ORDER BY id') as $row) {
                $id = (int) $row['id'];
                $maxPostId = max($maxPostId, $id);
                $posts[] = [
                    'id' => $id,
                    'title' => (string) $row['title'],
                    'slug' => (string) $row['slug'],
                    'body_markdown' => (string) ($row['body_markdown'] ?? ''),
                    'body_html' => (string) ($row['body_html'] ?? ''),
                    'excerpt' => (string) ($row['excerpt'] ?? ''),
                    'featured_image' => (string) ($row['featured_image'] ?? ''),
                    'author_name' => (string) ($row['author_name'] ?? ''),
                    'author_url' => (string) ($row['author_url'] ?? ''),
                    'categories' => Json::decodeArray($row['categories_json'] ?? '[]'),
                    'tags' => Json::decodeArray($row['tags_json'] ?? '[]'),
                    'status' => (string) ($row['status'] ?? 'draft'),
                    'published_at' => $row['published_at'],
                    'seo_title' => (string) ($row['seo_title'] ?? ''),
                    'seo_description' => (string) ($row['seo_description'] ?? ''),
                    'og_image' => (string) ($row['og_image'] ?? ''),
                    'canonical_url' => (string) ($row['canonical_url'] ?? ''),
                    'robots' => (string) ($row['robots'] ?? ''),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }
        }

        $sources = [];
        $maxSourceId = 0;
        if ($sourceCount > 0) {
            foreach ($pdo->query('SELECT * FROM cms_sources ORDER BY id') as $row) {
                $id = (int) $row['id'];
                $maxSourceId = max($maxSourceId, $id);
                $sources[] = [
                    'id' => $id,
                    'name' => (string) $row['name'],
                    'adapter' => (string) $row['adapter'],
                    'config' => Json::object($row['config_json'] ?? '{}'),
                    'last_synced_at' => $row['last_synced_at'],
                    'last_error' => $row['last_error'],
                ];
            }
        }

        $entries = [];
        if ($entryCount > 0) {
            foreach ($pdo->query('SELECT * FROM cms_entries ORDER BY source_id, external_id') as $row) {
                $entries[] = [
                    'source_id' => (int) $row['source_id'],
                    'external_id' => (string) $row['external_id'],
                    'data' => Json::object($row['data_json'] ?? '{}'),
                    'synced_at' => (string) ($row['synced_at'] ?? ''),
                ];
            }
        }

        if (self::tableExists($pdo, 'page_blocks')) {
            $blocks = $pdo->query(
                'SELECT id, data_json, cms_source_id, cms_entry_key FROM page_blocks WHERE cms_source_id IS NOT NULL'
            );
            $update = $pdo->prepare('UPDATE page_blocks SET data_json = ? WHERE id = ?');
            foreach ($blocks as $row) {
                $data = Json::object($row['data_json'] ?? '{}');
                $data['_cms'] = [
                    'source_id' => (int) $row['cms_source_id'],
                    'entry_key' => $row['cms_entry_key'] !== null ? (string) $row['cms_entry_key'] : null,
                ];
                $update->execute([Json::encode($data), (int) $row['id']]);
            }
        }

        self::putPluginOption($pdo, 'cms', 'posts', $posts);
        self::putPluginOption($pdo, 'cms', 'sources', $sources);
        self::putPluginOption($pdo, 'cms', 'entries', $entries);
        self::putPluginOption($pdo, 'cms', 'next_post_id', $maxPostId > 0 ? $maxPostId + 1 : 1);
        self::putPluginOption($pdo, 'cms', 'next_source_id', $maxSourceId > 0 ? $maxSourceId + 1 : 1);
    }

    private static function migrateToV8(PDO $pdo): void
    {
        $cols = [];
        foreach ($pdo->query('PRAGMA table_info(pages)')->fetchAll() as $col) {
            $cols[$col['name']] = true;
        }
        if (!isset($cols['archived_at'])) {
            $pdo->exec('ALTER TABLE pages ADD COLUMN archived_at TEXT');
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetch();
    }

    private static function tableCount(PDO $pdo, string $table): int
    {
        if (!self::tableExists($pdo, $table)) {
            return 0;
        }
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private static function putPluginOption(PDO $pdo, string $pluginId, string $key, mixed $value): void
    {
        $pdo->prepare(
            'INSERT INTO plugin_options (plugin_id, option_key, value_json) VALUES (?, ?, ?)
             ON CONFLICT(plugin_id, option_key) DO UPDATE SET value_json = excluded.value_json'
        )->execute([$pluginId, $key, Json::encode($value)]);
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database not connected');
        }
        return self::$pdo;
    }
}
