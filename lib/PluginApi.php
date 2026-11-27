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
 * Do not call preview(), publish(), archivePage(), deletePage(), compileDocument(),
 * or writeSiteFile() from a compile hook. preview() and compileDocument() re-enter
 * the compiler. publish(), archivePage(), deletePage(), and writeSiteFile() write
 * the site while a compile is still on the stack.
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
            self::method('routes', 'route', 'Handle /api/plugin/{id}/{path}. The handler is function (array $body, array $query, array $files): array|string. opts.stream returns a generator of event arrays and is sent as text/event-stream. Auth is required. Non-GET requests require CSRF. opts.admin requires an admin.', 'method: string, path: string, handler: callable, opts?: {admin?: bool, stream?: bool}', 'void', 'routes'),
            self::method('studio', 'workspace', 'Add a studio workspace. script and style are files under assets/. The script module default-exports boot(sneeze).', 'spec: {id: string, label: string, admin?: bool, html?: string, script?: string, style?: string}', 'void', 'studio'),
            self::method('studio', 'sideTab', 'Add a tab beside Blocks, Write, and Code. Same spec as workspace().', 'spec: {id: string, label: string, admin?: bool, html?: string, script?: string, style?: string}', 'void', 'studio'),
            self::method('studio', 'contributions', 'Workspaces and side tabs registered during boot(). The loader reads this.', '', '{workspaces: list, side_tabs: list}', null),
            self::method('draft', 'insertBlock', 'Return a new block tree with one block inserted. Does not write the database. index counts blocks in the same slot. A missing index appends. The new block uses a negative id, which saveBlocks stores as an insert.', 'tree: list, spec: {type_id?: int, slug?: string, parent_id?: int|string|null, slot?: string, index?: int, data?: array}', 'list', null),
            self::method('draft', 'updateBlockData', 'Return a new tree with one block data object replaced. Siblings stay. Does not write the database.', 'tree: list, id: int|string, data: array', 'list', null),
            self::method('draft', 'patchBlockData', 'Return a new tree with keys merged into one block data object. Other keys and siblings stay. Does not write the database.', 'tree: list, id: int|string, partial: array', 'list', null),
            self::method('draft', 'removeBlock', 'Return a new tree without that block or its children. Does not write the database.', 'tree: list, id: int|string', 'list', null),
            self::method('html', 'cleanHtml', 'Strip disallowed tags and attributes. Same cleaner the studio uses for rich text.', 'html: string', 'string', null),
            self::method('site.read', 'pages', 'Pages without block trees, including archived pages. Same rows as GET /api/pages. archived_at is null unless the page is archived.', '', 'list', 'site.read'),
            self::method('site.read', 'page', 'One page, including its block tree. Same shape as GET /api/pages/{id}.', 'id: int', 'array', 'site.read'),
            self::method('site.read', 'templates', 'Every template.', '', 'list', 'site.read'),
            self::method('site.read', 'template', 'One template, including html, css, and js.', 'id: int', 'array', 'site.read'),
            self::method('site.read', 'blockTypes', 'Every block type, including schema, html, css, and js.', '', 'list', 'site.read'),
            self::method('site.read', 'blockType', 'One block type.', 'id: int', 'array', 'site.read'),
            self::method('site.read', 'design', 'Tokens, stylesheets, scripts, chrome, and toolchain. Same payload as GET /api/design.', '', 'array', 'site.read'),
            self::method('site.read', 'site', 'Site title, web base, and home URL. home_url uses the same shape as a compiled page.', '', '{title: string, web_base: string, home_url: string}', 'site.read'),
            self::method('site.read', 'publicPath', 'Public URL for a path relative to the site root.', 'relative: string', 'string', 'site.read'),
            self::method('site.read', 'pagePath', 'Public URL for a page slug. The index slug is index.html.', 'slug: string', 'string', 'site.read'),
            self::method('site.read', 'media', 'Studio media library.', '', 'list', 'site.read'),
            self::method('site.read', 'user', 'The signed-in user (id, username, role), or null.', '', 'array|null', 'site.read'),
            self::method('site.read', 'preview', 'Preview HTML for a page. draft uses the same keys as POST /api/pages/{id}/preview, including blocks and template. Includes preview chrome and is not minified.', 'pageId: int, draft?: array|null', 'string', 'site.read'),
            self::method('site.write', 'createPage', 'Create a page. Same fields as POST /api/pages.', 'data: array', 'array', 'site.write'),
            self::method('site.write', 'updatePage', 'Update a page. Include blocks to replace the tree. Same fields as PUT /api/pages/{id}.', 'id: int, data: array', 'array', 'site.write'),
            self::method('site.write', 'deletePage', 'Delete a page and remove its published HTML. The home page cannot be deleted. Throws if called while compiling.', 'id: int', 'void', 'site.write'),
            self::method('site.write', 'archivePage', 'Archive a page, set it back to draft, and remove its published HTML. The page stays in the database. The home page cannot be archived. Throws if called while compiling.', 'id: int', 'array', 'site.write'),
            self::method('site.write', 'restorePage', 'Restore an archived page as a draft. Does not publish it or write HTML.', 'id: int', 'array', 'site.write'),
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
            self::method('site.write', 'importMedia', 'Copy a file from this plugin directory into the studio media library.', 'relativePath: string', 'array', 'site.write'),
            self::method('site.write', 'importMediaBytes', 'Store file bytes in the media library. Same 10 MB and file-type limits as an upload.', 'filename: string, contents: string', 'array', 'site.write'),
            self::method('site.write', 'importMediaFromUrl', 'Download an http or https URL into the media library. Same 10 MB and file-type limits as an upload.', 'url: string, filename?: string', 'array', 'site.write'),
            self::method('site.write', 'saveToolchain', 'Save toolchain settings: typescript, tailwind, and tailwind_config_ts.', 'data: array', 'array', 'site.write'),
            self::method('compile', 'compileDocument', 'Compile one HTML document from a template and the HTML placed in {{slot:content}}. Runs compile.context, site chrome, design tokens, global CSS and JS, template CSS and JS, extra_css, TypeScript, Tailwind, media URL rewrite for page.slug, and minify when preview is false, then compile.html. No block tree and no preview selection chrome. template_id is an optional int. template ({html, css?, js?}) is required when template_id is omitted. page accepts title, slug, seo_title, seo_description, robots, canonical, og_image, json_ld, header_mode, footer_mode, header_html, and footer_html. preview defaults to false. Throws if called while compiling.', 'spec: array', 'string', 'compile'),
            self::method('site.write', 'writeSiteFile', 'Write a file under the site root. Backs up an existing file, then renames the new file into place. Rejects ".." and paths outside the site root. Throws if called while compiling.', 'relativePath: string, content: string', 'void', 'site.write'),
            self::method('site.write', 'publish', 'Publish one page. Same result as POST /api/pages/{id}/publish.', 'pageId: int', 'array', 'site.write'),
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
     * @param array{admin?: bool, stream?: bool} $opts
     */
    public function route(string $method, string $path, callable $handler, array $opts = []): void
    {
        $this->requireCapability('routes');
        Plugins::addRoute(
            $this->pluginId,
            strtoupper($method),
            self::normalizeRoutePath($path),
            $handler,
            !empty($opts['admin']),
            !empty($opts['stream'])
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

    /**
     * Insert a block into a tree. Does not write the database.
     *
     * @param list<array<string, mixed>> $tree
     * @param array<string, mixed> $spec
     * @return list<array<string, mixed>>
     */
    public function insertBlock(array $tree, array $spec): array
    {
        return PluginDraft::insertBlock($tree, $spec);
    }

    /**
     * Replace one block's data. Does not write the database.
     *
     * @param list<array<string, mixed>> $tree
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public function updateBlockData(array $tree, int|string $id, array $data): array
    {
        return PluginDraft::updateBlockData($tree, $id, $data);
    }

    /**
     * Merge keys into one block's data. Does not write the database.
     *
     * @param list<array<string, mixed>> $tree
     * @param array<string, mixed> $partial
     * @return list<array<string, mixed>>
     */
    public function patchBlockData(array $tree, int|string $id, array $partial): array
    {
        return PluginDraft::patchBlockData($tree, $id, $partial);
    }

    /**
     * Remove a block and its children. Does not write the database.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public function removeBlock(array $tree, int|string $id): array
    {
        return PluginDraft::removeBlock($tree, $id);
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

    /** Strip disallowed tags and attributes. */
    public function cleanHtml(string $html): string
    {
        return (new Sanitizer())->clean($html);
    }

    /**
     * @return array{title: string, web_base: string, home_url: string}
     */
    public function site(): array
    {
        $this->requireCapability('site.read');
        $webBase = Config::siteWebBase();
        $home = $webBase === '/' ? 'index.html' : $webBase . 'index.html';
        return [
            'title' => (string) Config::get('site_title', 'Sneeze'),
            'web_base' => $webBase,
            'home_url' => $home,
        ];
    }

    /** Public URL for a path relative to the site root. */
    public function publicPath(string $relative): string
    {
        $this->requireCapability('site.read');
        return Compiler::absoluteUrl($relative);
    }

    /** Public URL for a page slug. */
    public function pagePath(string $slug): string
    {
        $this->requireCapability('site.read');
        return Compiler::pageUrl($slug);
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
        if (Compiler::isCompiling()) {
            throw new PluginException('deletePage() cannot be called from a compile hook');
        }
        $page = PageRepository::get($id, false);
        PageRepository::delete($id);
        (new Publisher())->unpublishPage($page);
    }

    /** @return array<string, mixed> */
    public function archivePage(int $id): array
    {
        $this->requireCapability('site.write');
        if (Compiler::isCompiling()) {
            throw new PluginException('archivePage() cannot be called from a compile hook');
        }
        $page = PageRepository::archive($id);
        (new Publisher())->unpublishPage($page);
        return PageRepository::get($id, false);
    }

    /** @return array<string, mixed> */
    public function restorePage(int $id): array
    {
        $this->requireCapability('site.write');
        return PageRepository::restore($id);
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

    /** @return array<string, mixed> */
    public function importMedia(string $relativePath): array
    {
        $this->requireCapability('site.write');
        $abs = $this->path($relativePath);
        if (!is_file($abs)) {
            throw new PluginException('File not found');
        }
        return $this->ingestFile($abs, basename($abs));
    }

    /**
     * Store raw file bytes in the media library.
     *
     * @return array<string, mixed>
     */
    public function importMediaBytes(string $filename, string $contents): array
    {
        $this->requireCapability('site.write');
        if ($contents === '') {
            throw new PluginException('File is empty');
        }
        if (strlen($contents) > 10 * 1024 * 1024) {
            throw new PluginException('File too large (max 10 MB)');
        }
        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new PluginException('Filename is invalid');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sneeze-media-');
        if ($tmp === false) {
            throw new PluginException('Could not store media');
        }
        try {
            if (file_put_contents($tmp, $contents) === false) {
                throw new PluginException('Could not store media');
            }
            return $this->ingestFile($tmp, $filename);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Download an http or https URL into the media library.
     *
     * @return array<string, mixed>
     */
    public function importMediaFromUrl(string $url, ?string $filename = null): array
    {
        $this->requireCapability('site.write');
        $filename = $this->mediaNameFromUrl($url, $filename);
        $tmp = tempnam(sys_get_temp_dir(), 'sneeze-media-');
        if ($tmp === false) {
            throw new PluginException('Could not store media');
        }
        $in = null;
        $out = null;
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 15,
                    'follow_location' => 1,
                    'max_redirects' => 3,
                    'header' => "User-Agent: Sneeze\r\n",
                ],
            ]);
            $in = @fopen($url, 'rb', false, $context);
            if ($in === false) {
                throw new PluginException('Could not fetch media');
            }
            $meta = stream_get_meta_data($in);
            $final = (string) ($meta['uri'] ?? $url);
            $finalScheme = strtolower((string) (parse_url($final, PHP_URL_SCHEME) ?: ''));
            if (!in_array($finalScheme, ['http', 'https'], true)) {
                throw new PluginException('Media URL must be http or https');
            }
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                throw new PluginException('Could not store media');
            }
            $size = 0;
            $max = 10 * 1024 * 1024;
            while (!feof($in)) {
                $chunk = fread($in, 8192);
                if ($chunk === false) {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $max) {
                    throw new PluginException('File too large (max 10 MB)');
                }
                fwrite($out, $chunk);
            }
            fclose($out);
            $out = null;
            fclose($in);
            $in = null;
            if ($size < 1) {
                throw new PluginException('File is empty');
            }
            return $this->ingestFile($tmp, $filename);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveToolchain(array $data): array
    {
        $this->requireCapability('site.write');
        return ToolchainRepository::save($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function ingestFile(string $path, string $filename): array
    {
        $user = Auth::user();
        $item = (new MediaStore())->ingest($path, $filename, $user ? (int) $user['id'] : 0);
        if ($item === null) {
            throw new PluginException('Could not import media');
        }
        return $item;
    }

    private function mediaNameFromUrl(string $url, ?string $filename): string
    {
        if (preg_match('/[\r\n\0]/', $url)) {
            throw new PluginException('Media URL must be http or https');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new PluginException('Media URL must be http or https');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new PluginException('Media URL must be http or https');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new PluginException('Media URL must be http or https');
        }
        $name = $filename ?? '';
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            $pathName = basename((string) ($parts['path'] ?? ''));
            $name = ($pathName !== '' && $pathName !== '.' && $pathName !== '..') ? $pathName : 'download';
        }
        return $name;
    }

    /**
     * Compile one document from a template and slot HTML. No block tree.
     *
     * @param array<string, mixed> $spec
     */
    public function compileDocument(array $spec): string
    {
        $this->requireCapability('compile');
        if (Compiler::isCompiling()) {
            throw new PluginException('compileDocument() cannot be called from a compile hook');
        }
        if (!is_array($spec['page'] ?? null)) {
            throw new PluginException('compileDocument requires a page');
        }
        $templateId = (int) ($spec['template_id'] ?? 0);
        $inline = $spec['template'] ?? null;
        if ($templateId < 1 && !is_array($inline)) {
            throw new PluginException('compileDocument requires a template');
        }
        if ($templateId < 1 && !is_string($inline['html'] ?? null)) {
            throw new PluginException('compileDocument requires template html');
        }
        if (array_key_exists('content', $spec) && !is_string($spec['content'])) {
            throw new PluginException('compileDocument content must be a string');
        }
        if (array_key_exists('extra_css', $spec) && !is_string($spec['extra_css'])) {
            throw new PluginException('compileDocument extra_css must be a string');
        }
        return (new Compiler())->compileDocument($spec);
    }

    /** Write a file under the site root. */
    public function writeSiteFile(string $relativePath, string $content): void
    {
        $this->requireCapability('site.write');
        if (Compiler::isCompiling()) {
            throw new PluginException('writeSiteFile() cannot be called from a compile hook');
        }
        (new Publisher())->writeSiteFile($relativePath, $content);
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
