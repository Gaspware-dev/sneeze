<?php

declare(strict_types=1);

/**
 * The only host object a plugin receives.
 *
 * PluginApi::reference() lists every public method. A future documentation
 * page should render that catalog, PluginManifest::describe(),
 * PluginHooks::catalog(), and PluginClient::reference().
 *
 * Capabilities declared in plugin.json are checked before a method runs.
 * They are a contract, not a sandbox: an enabled plugin is trusted PHP.
 * Repository validation failures still end the request through Response::error,
 * the same JSON shape the studio API returns.
 *
 * Do not call preview() or publish() from a compile hook. That re-enters the compiler.
 */
class PluginApi
{
    public const VERSION = 1;

    /** @var array<string, mixed> */
    private array $manifest;

    private string $pluginId;

    private string $pluginVersion;

    private string $directory;

    /** @var list<string> */
    private array $capabilities;

    /** @var list<array<string, mixed>> */
    private array $workspaces = [];

    /** @var list<array<string, mixed>> */
    private array $sideTabs = [];

    /**
     * @param array<string, mixed> $manifest parsed plugin.json
     */
    public static function forPlugin(array $manifest, string $directory): self
    {
        return new self($manifest, $directory);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function __construct(array $manifest, string $directory)
    {
        $this->manifest = $manifest;
        $this->pluginId = (string) $manifest['id'];
        $this->pluginVersion = (string) $manifest['version'];
        $this->directory = rtrim($directory, '/');
        $caps = $manifest['capabilities'] ?? [];
        $this->capabilities = is_array($caps) ? array_values($caps) : [];
    }

    /**
     * Machine-readable catalog of this class. Keys match the public methods.
     *
     * @return list<array{group: string, method: string, summary: string, params: string, returns: string, capability: string|null}>
     */
    public static function reference(): array
    {
        return [
            self::method('contract', 'reference', 'Catalog of every public method on PluginApi.', '', 'list of {group, method, summary, params, returns, capability}', null),
            self::method('contract', 'forPlugin', 'Build the object the loader passes to SneezePlugin::boot(). Plugins do not construct it.', 'manifest: array, directory: string', 'PluginApi', null),
            self::method('identity', 'id', 'Plugin id from plugin.json.', '', 'string', null),
            self::method('identity', 'version', 'Plugin version from plugin.json.', '', 'string', null),
            self::method('identity', 'path', 'Absolute path inside this plugin directory. Rejects paths that escape it.', 'relative?: string', 'string', null),
            self::method('storage', 'option', 'One value stored for this plugin, or the default when unset.', 'key: string, default?: mixed', 'mixed', null),
            self::method('storage', 'setOption', 'Store a JSON value for this plugin. Null deletes the key.', 'key: string, value: mixed', 'void', null),
            self::method('storage', 'options', 'Every stored value for this plugin, including secrets.', '', 'array<string, mixed>', null),
            self::method('hooks', 'filter', 'Listen on a filter. Core compile and publish hook names require the compile capability. The listener is function (mixed $value, array $context): mixed.', 'hook: string, listener: callable, priority?: int', 'void', 'compile when the hook is in PluginHooks::catalog()'),
            self::method('hooks', 'on', 'Listen on an action. The listener is function (array $context): void.', 'hook: string, listener: callable, priority?: int', 'void', 'compile when the hook is in PluginHooks::catalog()'),
            self::method('hooks', 'apply', 'Run a filter, including a name this plugin invented. Lower priority runs first.', 'hook: string, value: mixed, context?: array', 'mixed', null),
            self::method('hooks', 'do', 'Run an action, including a name this plugin invented.', 'hook: string, context?: array', 'void', null),
            self::method('routes', 'route', 'Handle /api/plugin/{id}/{path}. The handler is function (array $body, array $query): array|string. Auth is required. Non-GET requests require CSRF. opts.admin requires an admin.', 'method: string, path: string, handler: callable, opts?: {admin?: bool}', 'void', 'routes'),
            self::method('studio', 'workspace', 'Add a studio workspace. script and style are files under assets/. The script module default-exports boot(sneeze).', 'spec: {id: string, label: string, admin?: bool, html?: string, script?: string, style?: string}', 'void', 'studio'),
            self::method('studio', 'sideTab', 'Add a tab beside Blocks, Write, and Code. Same spec as workspace().', 'spec: {id: string, label: string, admin?: bool, html?: string, script?: string, style?: string}', 'void', 'studio'),
            self::method('studio', 'contributions', 'Workspaces and side tabs registered during boot(). The loader reads this.', '', '{workspaces: list, side_tabs: list}', null),
            self::method('site.read', 'pages', 'Pages without block trees. Same rows as GET /api/pages.', '', 'list', 'site.read'),
            self::method('site.read', 'page', 'One page, including its block tree. Same shape as GET /api/pages/{id}.', 'id: int', 'array', 'site.read'),
            self::method('site.read', 'templates', 'Every template.', '', 'list', 'site.read'),
            self::method('site.read', 'template', 'One template, including html, css, and js.', 'id: int', 'array', 'site.read'),
            self::method('site.read', 'blockTypes', 'Every block type, including schema, html, css, and js.', '', 'list', 'site.read'),
            self::method('site.read', 'blockType', 'One block type.', 'id: int', 'array', 'site.read'),
            self::method('site.read', 'design', 'Tokens, stylesheets, scripts, chrome, and toolchain. Same payload as GET /api/design.', '', 'array', 'site.read'),
            self::method('site.read', 'posts', 'Built-in posts. Pass a status of draft or published, or null for all.', 'status?: string|null', 'list', 'site.read'),
            self::method('site.read', 'post', 'One built-in post.', 'id: int', 'array', 'site.read'),
            self::method('site.read', 'media', 'Studio media library.', '', 'list', 'site.read'),
            self::method('site.read', 'user', 'The signed-in user (id, username, role), or null.', '', 'array|null', 'site.read'),
            self::method('site.read', 'preview', 'Preview HTML for a page. draft uses the same keys as POST /api/pages/{id}/preview, including blocks and template. Includes preview chrome and is not minified.', 'pageId: int, draft?: array|null', 'string', 'site.read'),
            self::method('site.write', 'createPage', 'Create a page. Same fields as POST /api/pages.', 'data: array', 'array', 'site.write'),
            self::method('site.write', 'updatePage', 'Update a page. Include blocks to replace the tree. Same fields as PUT /api/pages/{id}.', 'id: int, data: array', 'array', 'site.write'),
            self::method('site.write', 'deletePage', 'Delete a page. The home page cannot be deleted.', 'id: int', 'void', 'site.write'),
            self::method('site.write', 'saveBlocks', 'Replace a page block tree. Returns the stored tree.', 'pageId: int, tree: list', 'list', 'site.write'),
            self::method('site.write', 'saveTemplate', 'Create or update a template. Pass id to update.', 'data: array', 'array', 'site.write'),
            self::method('site.write', 'deleteTemplate', 'Delete a template that no page uses.', 'id: int', 'void', 'site.write'),
            self::method('site.write', 'saveBlockType', 'Create or update a block type. Pass id to update. schema is the field schema object.', 'data: array', 'array', 'site.write'),
            self::method('site.write', 'deleteBlockType', 'Delete a block type that is not in use.', 'id: int', 'void', 'site.write'),
            self::method('site.write', 'saveTokens', 'Replace design tokens.', 'tokens: array', 'array', 'site.write'),
            self::method('site.write', 'saveChrome', 'Replace sitewide header_html and footer_html.', 'data: array', 'array', 'site.write'),
            self::method('site.write', 'saveStylesheet', 'Create or update a global stylesheet.', 'data: array', 'array', 'site.write'),
            self::method('site.write', 'deleteStylesheet', 'Delete a global stylesheet.', 'id: int', 'void', 'site.write'),
            self::method('site.write', 'saveScript', 'Create or update a global script. placement is head or footer.', 'data: array', 'array', 'site.write'),
            self::method('site.write', 'deleteScript', 'Delete a global script.', 'id: int', 'void', 'site.write'),
            self::method('site.write', 'savePost', 'Create or update a built-in post. Same fields as POST /api/cms/posts.', 'data: array', 'array', 'site.write'),
            self::method('site.write', 'deletePost', 'Delete a built-in post.', 'id: int', 'void', 'site.write'),
            self::method('site.write', 'importMedia', 'Copy a file from this plugin directory into the studio media library.', 'relativePath: string', 'array', 'site.write'),
            self::method('site.write', 'publish', 'Publish one page. Same result as POST /api/pages/{id}/publish.', 'pageId: int', 'array', 'site.write'),
            self::method('site.write', 'publishPosts', 'Publish built-in posts, the post index, the feed, and the sitemap.', '', 'array', 'site.write'),
        ];
    }

    /**
     * @return array{group: string, method: string, summary: string, params: string, returns: string, capability: string|null}
     */
    private static function method(
        string $group,
        string $method,
        string $summary,
        string $params,
        string $returns,
        ?string $capability
    ): array {
        return [
            'group' => $group,
            'method' => $method,
            'summary' => $summary,
            'params' => $params,
            'returns' => $returns,
            'capability' => $capability,
        ];
    }

    /** Plugin id from plugin.json. */
    public function id(): string
    {
        return $this->pluginId;
    }

    /** Plugin version from plugin.json. */
    public function version(): string
    {
        return $this->pluginVersion;
    }

    /**
     * Absolute path confined to this plugin directory.
     */
    public function path(string $relative = ''): string
    {
        $root = realpath($this->directory);
        if ($root === false) {
            throw new PluginException('Plugin directory is missing');
        }
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || $relative === '.') {
            return $root;
        }
        if (
            str_starts_with($relative, '/')
            || str_contains($relative, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $relative)
        ) {
            throw new PluginException('Path escapes the plugin directory');
        }
        $full = $root . '/' . $relative;
        if (file_exists($full)) {
            $real = realpath($full);
            if ($real === false || ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR))) {
                throw new PluginException('Path escapes the plugin directory');
            }
            return $real;
        }
        $parent = realpath(dirname($full));
        if ($parent === false || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
            throw new PluginException('Path escapes the plugin directory');
        }
        return $full;
    }

    /** One stored value, or $default when the key is unset. */
    public function option(string $key, mixed $default = null): mixed
    {
        $this->assertOptionKey($key);
        $stmt = Database::pdo()->prepare(
            'SELECT value_json FROM plugin_options WHERE plugin_id = ? AND option_key = ?'
        );
        $stmt->execute([$this->pluginId, $key]);
        $row = $stmt->fetch();
        if (!$row) {
            return $default;
        }
        return json_decode((string) $row['value_json'], true);
    }

    /** Store a JSON value. Null deletes the key. */
    public function setOption(string $key, mixed $value): void
    {
        $this->assertOptionKey($key);
        if ($value === null) {
            Database::pdo()->prepare(
                'DELETE FROM plugin_options WHERE plugin_id = ? AND option_key = ?'
            )->execute([$this->pluginId, $key]);
            return;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new PluginException('Option value cannot be stored as JSON');
        }
        Database::pdo()->prepare(
            'INSERT INTO plugin_options (plugin_id, option_key, value_json) VALUES (?, ?, ?)
             ON CONFLICT(plugin_id, option_key) DO UPDATE SET value_json = excluded.value_json'
        )->execute([$this->pluginId, $key, $json]);
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT option_key, value_json FROM plugin_options WHERE plugin_id = ? ORDER BY option_key'
        );
        $stmt->execute([$this->pluginId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['option_key']] = json_decode((string) $row['value_json'], true);
        }
        return $out;
    }

    /**
     * Listen on a filter. Core hooks require the compile capability.
     */
    public function filter(string $hook, callable $listener, int $priority = 10): void
    {
        $this->guardHook($hook);
        PluginHooks::listen($hook, $listener, $priority, $this->pluginId, 'filter');
    }

    /**
     * Listen on an action. Core hooks require the compile capability.
     */
    public function on(string $hook, callable $listener, int $priority = 10): void
    {
        $this->guardHook($hook);
        PluginHooks::listen($hook, $listener, $priority, $this->pluginId, 'action');
    }

    /**
     * Run a filter and return the resulting value.
     *
     * @param array<string, mixed> $context
     */
    public function apply(string $hook, mixed $value, array $context = []): mixed
    {
        $this->assertHookName($hook);
        return PluginHooks::filter($hook, $value, $context);
    }

    /**
     * Run an action.
     *
     * @param array<string, mixed> $context
     */
    public function do(string $hook, array $context = []): void
    {
        $this->assertHookName($hook);
        PluginHooks::action($hook, $context);
    }

    /**
     * Register an exact route under /api/plugin/{id}/.
     *
     * @param array{admin?: bool} $opts
     */
    public function route(string $method, string $path, callable $handler, array $opts = []): void
    {
        $this->requireCapability('routes');
        Plugins::addRoute(
            $this->pluginId,
            strtoupper($method),
            self::normalizeRoutePath($path),
            $handler,
            !empty($opts['admin'])
        );
    }

    /**
     * Add a workspace to the studio navigation.
     *
     * @param array<string, mixed> $spec
     */
    public function workspace(array $spec): void
    {
        $this->requireCapability('studio');
        $this->workspaces[] = $this->contribution($spec);
    }

    /**
     * Add a tab to the studio side pane.
     *
     * @param array<string, mixed> $spec
     */
    public function sideTab(array $spec): void
    {
        $this->requireCapability('studio');
        $this->sideTabs[] = $this->contribution($spec);
    }

    /**
     * @return array{workspaces: list<array<string, mixed>>, side_tabs: list<array<string, mixed>>}
     */
    public function contributions(): array
    {
        return [
            'workspaces' => $this->workspaces,
            'side_tabs' => $this->sideTabs,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function pages(): array
    {
        $this->requireCapability('site.read');
        return PageRepository::all();
    }

    /** @return array<string, mixed> */
    public function page(int $id): array
    {
        $this->requireCapability('site.read');
        return PageRepository::get($id);
    }

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        $this->requireCapability('site.read');
        return TemplateRepository::all();
    }

    /** @return array<string, mixed> */
    public function template(int $id): array
    {
        $this->requireCapability('site.read');
        return TemplateRepository::get($id);
    }

    /** @return list<array<string, mixed>> */
    public function blockTypes(): array
    {
        $this->requireCapability('site.read');
        return BlockTypeRepository::all();
    }

    /** @return array<string, mixed> */
    public function blockType(int $id): array
    {
        $this->requireCapability('site.read');
        return BlockTypeRepository::get($id);
    }

    /**
     * @return array{tokens: array<string, mixed>, stylesheets: list<array<string, mixed>>, scripts: list<array<string, mixed>>, chrome: array<string, mixed>, toolchain: array<string, mixed>}
     */
    public function design(): array
    {
        $this->requireCapability('site.read');
        return [
            'tokens' => DesignRepository::tokens(),
            'stylesheets' => DesignRepository::stylesheets(),
            'scripts' => DesignRepository::scripts(),
            'chrome' => DesignRepository::chrome(),
            'toolchain' => ToolchainRepository::settings(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function posts(?string $status = null): array
    {
        $this->requireCapability('site.read');
        return PostRepository::all($status);
    }

    /** @return array<string, mixed> */
    public function post(int $id): array
    {
        $this->requireCapability('site.read');
        return PostRepository::get($id);
    }

    /** @return list<array<string, mixed>> */
    public function media(): array
    {
        $this->requireCapability('site.read');
        return (new MediaStore())->list();
    }

    /**
     * @return array{id: int, username: string, role: string}|null
     */
    public function user(): ?array
    {
        $this->requireCapability('site.read');
        $user = Auth::user();
        if (!$user) {
            return null;
        }
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
        ];
    }

    /**
     * @param array<string, mixed>|null $draft
     */
    public function preview(int $pageId, ?array $draft = null): string
    {
        $this->requireCapability('site.read');
        if (Compiler::isCompiling()) {
            throw new PluginException('preview() cannot be called from a compile hook');
        }
        return (new Compiler())->compilePage($pageId, true, $draft);
    }

    /** @param array<string, mixed> $data */
    public function createPage(array $data): array
    {
        $this->requireCapability('site.write');
        return PageRepository::create($data);
    }

    /** @param array<string, mixed> $data */
    public function updatePage(int $id, array $data): array
    {
        $this->requireCapability('site.write');
        return PageRepository::update($id, $data);
    }

    public function deletePage(int $id): void
    {
        $this->requireCapability('site.write');
        PageRepository::delete($id);
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public function saveBlocks(int $pageId, array $tree): array
    {
        $this->requireCapability('site.write');
        return PageRepository::saveTree($pageId, $tree);
    }

    /** @param array<string, mixed> $data */
    public function saveTemplate(array $data): array
    {
        $this->requireCapability('site.write');
        return TemplateRepository::save($data);
    }

    public function deleteTemplate(int $id): void
    {
        $this->requireCapability('site.write');
        TemplateRepository::delete($id);
    }

    /** @param array<string, mixed> $data */
    public function saveBlockType(array $data): array
    {
        $this->requireCapability('site.write');
        return BlockTypeRepository::save($data);
    }

    public function deleteBlockType(int $id): void
    {
        $this->requireCapability('site.write');
        BlockTypeRepository::delete($id);
    }

    /** @param array<string, mixed> $tokens */
    public function saveTokens(array $tokens): array
    {
        $this->requireCapability('site.write');
        return DesignRepository::saveTokens($tokens);
    }

    /** @param array<string, mixed> $data */
    public function saveChrome(array $data): array
    {
        $this->requireCapability('site.write');
        return DesignRepository::saveChrome($data);
    }

    /** @param array<string, mixed> $data */
    public function saveStylesheet(array $data): array
    {
        $this->requireCapability('site.write');
        return DesignRepository::saveStylesheet($data);
    }

    public function deleteStylesheet(int $id): void
    {
        $this->requireCapability('site.write');
        DesignRepository::deleteStylesheet($id);
    }

    /** @param array<string, mixed> $data */
    public function saveScript(array $data): array
    {
        $this->requireCapability('site.write');
        return DesignRepository::saveScript($data);
    }

    public function deleteScript(int $id): void
    {
        $this->requireCapability('site.write');
        DesignRepository::deleteScript($id);
    }

    /** @param array<string, mixed> $data */
    public function savePost(array $data): array
    {
        $this->requireCapability('site.write');
        return PostRepository::save($data);
    }

    public function deletePost(int $id): void
    {
        $this->requireCapability('site.write');
        PostRepository::delete($id);
    }

    /** @return array<string, mixed> */
    public function importMedia(string $relativePath): array
    {
        $this->requireCapability('site.write');
        $abs = $this->path($relativePath);
        if (!is_file($abs)) {
            throw new PluginException('File not found');
        }
        $user = Auth::user();
        $item = (new MediaStore())->ingest($abs, basename($abs), $user ? (int) $user['id'] : 0);
        if ($item === null) {
            throw new PluginException('Could not import media');
        }
        return $item;
    }

    /** @return array<string, mixed> */
    public function publish(int $pageId): array
    {
        $this->requireCapability('site.write');
        if (Compiler::isCompiling()) {
            throw new PluginException('publish() cannot be called from a compile hook');
        }
        $user = Auth::user();
        return (new Publisher())->publishPage($pageId, $user ? (int) $user['id'] : null);
    }

    /** @return array<string, mixed> */
    public function publishPosts(): array
    {
        $this->requireCapability('site.write');
        if (Compiler::isCompiling()) {
            throw new PluginException('publishPosts() cannot be called from a compile hook');
        }
        $user = Auth::user();
        return (new Publisher())->publishPosts($user ? (int) $user['id'] : null);
    }

    private function requireCapability(string $capability): void
    {
        if (!in_array($capability, $this->capabilities, true)) {
            throw new PluginException('Plugin ' . $this->pluginId . ' is missing capability ' . $capability);
        }
    }

    private function guardHook(string $hook): void
    {
        $this->assertHookName($hook);
        if (PluginHooks::isCore($hook)) {
            $this->requireCapability('compile');
        }
    }

    private function assertHookName(string $hook): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{0,80}$/', $hook)) {
            throw new PluginException('Hook name is invalid');
        }
    }

    private function assertOptionKey(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key)) {
            throw new PluginException('Option key is invalid');
        }
    }

    private static function normalizeRoutePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) || str_contains($path, '//')) {
            throw new PluginException('Route path is invalid');
        }
        if (!preg_match('#^[a-zA-Z0-9._/-]+$#', $path)) {
            throw new PluginException('Route path is invalid');
        }
        return $path;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function contribution(array $spec): array
    {
        $id = $spec['id'] ?? '';
        $label = $spec['label'] ?? '';
        if (!is_string($id) || !preg_match(PluginManifest::ID_PATTERN, $id)) {
            throw new PluginException('Studio contribution id is invalid');
        }
        if (!is_string($label) || trim($label) === '') {
            throw new PluginException('Studio contribution label is required');
        }
        $html = (string) ($spec['html'] ?? '');
        if (strlen($html) > 200000) {
            throw new PluginException('Studio contribution HTML is too large');
        }
        return [
            'id' => $id,
            'label' => trim($label),
            'admin' => !empty($spec['admin']),
            'html' => $html,
            'script_url' => $this->assetUrl(isset($spec['script']) ? (string) $spec['script'] : ''),
            'style_url' => $this->assetUrl(isset($spec['style']) ? (string) $spec['style'] : ''),
            'plugin_id' => $this->pluginId,
            'plugin_name' => (string) ($this->manifest['name'] ?? $this->pluginId),
        ];
    }

    private function assetUrl(string $relative): ?string
    {
        if ($relative === '') {
            return null;
        }
        $relative = str_replace('\\', '/', $relative);
        if (!str_starts_with($relative, 'assets/')) {
            throw new PluginException('Studio assets must live under assets/');
        }
        $inside = substr($relative, strlen('assets/'));
        $abs = $this->path('assets/' . $inside);
        if (!is_file($abs)) {
            throw new PluginException('Studio asset not found: ' . $relative);
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if (!in_array($ext, Plugins::ASSET_EXTENSIONS, true)) {
            throw new PluginException('Studio asset type is not allowed');
        }
        return 'plugin-assets/' . $this->pluginId . '/' . $inside;
    }
}
