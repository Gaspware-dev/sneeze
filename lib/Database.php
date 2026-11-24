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

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new RuntimeException('Database not connected');
        }
        return self::$pdo;
    }
}
