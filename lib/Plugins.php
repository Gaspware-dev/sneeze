<?php

declare(strict_types=1);

/**
 * Discovers, boots, installs, and dispatches plugins.
 *
 * User plugins live in data/plugins/{id} and survive self-update.
 * Bundled plugins live in plugins/{id}. A user copy with the same id wins.
 * Disabled plugins are not loaded. Boot errors are stored on the record
 * and do not stop other plugins.
 */
class Plugins
{
    /** @var list<string> */
    public const ASSET_EXTENSIONS = ['css', 'js', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'woff2', 'json'];

    private const MAX_ZIP_BYTES = 20971520;

    private const MAX_UNPACKED_BYTES = 31457280;

    private static bool $booted = false;

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $records = [];

    /**
     * @var array<string, array{plugin: string, handler: callable, admin: bool, stream: bool}>
     */
    private static array $routes = [];

    /**
     * Strip the front-controller directory from a request path.
     * Paths such as /plugin-assets/example/panel.js keep their file name:
     * the built-in server reports that URL as SCRIPT_NAME.
     */
    public static function normalizeUri(string $uri, string $scriptName): string
    {
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
        $scriptName = str_replace('\\', '/', $scriptName);
        $basePath = '';
        if (str_ends_with($scriptName, '/index.php') || str_ends_with($scriptName, '/router.php')) {
            $basePath = rtrim(dirname($scriptName), '/');
        }
        if ($basePath !== '' && $basePath !== '/' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath)) ?: '/';
        }
        $uri = '/' . trim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }
        return $uri;
    }

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::discoverAndBoot();
    }

    public static function reboot(): void
    {
        self::$booted = false;
        self::$records = [];
        self::$routes = [];
        PluginHooks::reset();
        self::boot();
    }

    public static function reset(): void
    {
        self::$booted = false;
        self::$records = [];
        self::$routes = [];
        PluginHooks::reset();
    }

    /**
     * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $file
     * @return array<string, mixed>
     */
    public static function installZip(array $file): array
    {
        if (!class_exists('ZipArchive')) {
            throw new PluginException('PHP zip extension is required to install a plugin');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new PluginException('Upload failed');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new PluginException('Upload missing');
        }
        if (!str_ends_with(strtolower($name), '.zip')) {
            throw new PluginException('Only .zip files are accepted');
        }
        $size = (int) ($file['size'] ?? filesize($tmp) ?: 0);
        if ($size < 30 || $size > self::MAX_ZIP_BYTES) {
            throw new PluginException('Zip size out of range');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new PluginException('Could not open zip archive');
        }
        $unpacked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (!is_string($entry) || self::unsafeZipName($entry)) {
                $zip->close();
                throw new PluginException('Zip contains an unsafe path');
            }
            $stat = $zip->statIndex($i);
            $unpacked += (int) ($stat['size'] ?? 0);
            if ($unpacked > self::MAX_UNPACKED_BYTES || $i > 500) {
                $zip->close();
                throw new PluginException('Zip is too large to unpack');
            }
        }

        $extractRoot = Config::dataDir() . '/plugin-install-' . bin2hex(random_bytes(6));
        if (!mkdir($extractRoot, 0750, true) && !is_dir($extractRoot)) {
            $zip->close();
            throw new PluginException('Could not create extract directory');
        }
        try {
            if (!$zip->extractTo($extractRoot)) {
                $zip->close();
                throw new PluginException('Could not extract zip archive');
            }
            $zip->close();
            $packageRoot = self::detectPackageRoot($extractRoot);
            $manifest = PluginManifest::validatePackage($packageRoot);
            $dest = self::userDir() . '/' . $manifest['id'];
            self::replaceTree($packageRoot, $dest);
            $manifest = PluginManifest::parse($dest);
            self::touchInstalled($manifest['id']);
            self::reboot();
            return self::onePayload($manifest['id'], true);
        } finally {
            self::rmTree($extractRoot);
        }
    }

    public static function enable(string $id): array
    {
        self::boot();
        $record = self::record($id);
        if ($record['manifest'] === null) {
            throw new PluginException($record['error'] ?: 'Plugin cannot be enabled');
        }
        self::remember($id, true);
        self::reboot();
        return self::onePayload($id, true);
    }

    public static function disable(string $id): array
    {
        self::boot();
        self::record($id);
        self::remember($id, false);
        self::reboot();
        return self::onePayload($id, true);
    }

    public static function delete(string $id): void
    {
        self::boot();
        $record = self::record($id);
        if (($record['origin'] ?? '') !== 'user') {
            throw new PluginException('Cannot delete a bundled plugin');
        }
        $dir = self::userDir() . '/' . $id;
        $root = realpath(self::userDir());
        $real = realpath($dir);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new PluginException('Plugin directory is missing');
        }
        self::rmTree($real);
        Database::pdo()->prepare('DELETE FROM plugins WHERE id = ?')->execute([$id]);
        Database::pdo()->prepare('DELETE FROM plugin_options WHERE plugin_id = ?')->execute([$id]);
        self::reboot();
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    public static function saveSettings(string $id, array $body): array
    {
        self::boot();
        $record = self::record($id);
        $manifest = $record['manifest'];
        if (!is_array($manifest)) {
            throw new PluginException('Plugin has no valid manifest');
        }
        $api = PluginApi::forPlugin($manifest, (string) $record['directory']);
        foreach ($manifest['settings'] as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if ($field['type'] === 'secret' && ($body[$key] === '' || $body[$key] === null)) {
                continue;
            }
            $api->setOption($key, self::coerceSetting($field, $body[$key]));
        }
        return self::settingsFor($manifest);
    }

    /**
     * @return array{api_version: int, plugins: list<array<string, mixed>>}
     */
    public static function listPayload(bool $includeSettings): array
    {
        self::boot();
        $plugins = [];
        foreach (self::$records as $id => $_record) {
            $plugins[] = self::onePayload((string) $id, $includeSettings);
        }
        return [
            'api_version' => PluginApi::VERSION,
            'plugins' => $plugins,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function referencePayload(): array
    {
        return [
            'api_version' => PluginApi::VERSION,
            'manifest' => PluginManifest::describe(),
            'hooks' => PluginHooks::catalog(),
            'methods' => PluginApi::reference(),
            'client' => PluginClient::reference(),
            'notes' => [
                'An enabled plugin is trusted PHP running inside Sneeze. Capabilities guard PluginApi. They are not a sandbox.',
                'Install plugins in data/plugins/{id} or upload a zip. That directory survives Update Sneeze. A copy there overrides plugins/{id}.',
                'Studio scripts are ES modules served from plugin-assets/{id}/. The default export is boot(sneeze).',
                'Plugin routes are /api/plugin/{id}/{path}. They require a signed-in user. Requests that are not GET require the studio CSRF header. The handler receives the JSON body, the query, and $_FILES.',
                'A JSON plugin route ends when PHP max_execution_time does, often 30 seconds. sneeze.api has no client timeout. A route with stream: true answers text/event-stream, lifts that limit for the request, and holds one PHP worker until the generator finishes. A reverse proxy can still close the connection. Read it with sneeze.stream.',
                'GET /api/plugins/reference returns this document. Render it for Plugin API documentation.',
            ],
        ];
    }

    public static function addRoute(string $pluginId, string $method, string $path, callable $handler, bool $admin, bool $stream = false): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new PluginException('Route method is invalid');
        }
        $key = $method . ' ' . $pluginId . ' ' . $path;
        if (isset(self::$routes[$key])) {
            throw new PluginException('Duplicate route ' . $method . ' /' . $path);
        }
        self::$routes[$key] = [
            'plugin' => $pluginId,
            'handler' => $handler,
            'admin' => $admin,
            'stream' => $stream,
        ];
    }

    /**
     * One Server-Sent Event. json_encode keeps the payload on a single data line.
     *
     * @param array<string, mixed> $event
     */
    public static function encodeEvent(array $event): string
    {
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        return 'data: ' . $json . "\n\n";
    }

    /**
     * @return array{auth: bool, csrf: bool, admin: bool, plugin: string}|null
     */
    public static function accessFor(string $method, string $uri): ?array
    {
        $route = self::findRoute($method, $uri);
        if ($route === null) {
            return null;
        }
        $verb = strtoupper($method);
        return [
            'auth' => true,
            'csrf' => !in_array($verb, ['GET', 'HEAD'], true),
            'admin' => $route['admin'],
            'plugin' => $route['plugin'],
        ];
    }

    /**
     * Call a matched route handler. The HTTP layer checks auth and CSRF first.
     * A stream route returns its generator. dispatch() is what sends the events.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, mixed> $files
     */
    public static function invoke(string $method, string $uri, array $body = [], array $query = [], array $files = []): mixed
    {
        $route = self::findRoute($method, $uri);
        if ($route === null) {
            throw new PluginException('Plugin route not found');
        }
        return ($route['handler'])($body, $query, $files);
    }

    public static function routeStreams(string $method, string $uri): bool
    {
        $route = self::findRoute($method, $uri);
        return $route !== null && !empty($route['stream']);
    }

    public static function dispatch(string $method, string $uri): void
    {
        $route = self::findRoute($method, $uri);
        if ($route === null) {
            Response::error('Not found', 404);
        }
        if ($route['admin']) {
            Auth::requireAdmin();
        }
        $files = isset($_FILES) && is_array($_FILES) ? $_FILES : [];
        try {
            $result = ($route['handler'])(self::jsonBody(), $_GET, $files);
        } catch (PluginException $e) {
            Response::error($e->getMessage());
        }
        if (!empty($route['stream'])) {
            if ($result instanceof Traversable || (is_array($result) && array_is_list($result))) {
                self::sendStream($result);
            }
            Response::error('Plugin stream must return events', 500);
        }
        if (is_string($result)) {
            Response::html($result);
        }
        if (is_array($result)) {
            Response::json($result);
        }
        Response::error('Plugin route must return an array or a string', 500);
    }

    /**
     * @param iterable<mixed> $events
     */
    public static function sendStream(iterable $events): void
    {
        set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        http_response_code(200);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        try {
            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }
                echo self::encodeEvent($event);
                flush();
            }
        } catch (PluginException $e) {
            echo self::encodeEvent(['error' => $e->getMessage()]);
            flush();
        }
        exit;
    }

    public static function serveAsset(string $uri): void
    {
        if (!preg_match('#^/plugin-assets/([a-z][a-z0-9-]{0,40})/(.+)$#', $uri, $m)) {
            Response::error('Not found', 404);
        }
        $path = self::resolveAsset($m[1], $m[2]);
        if ($path === null) {
            Response::error('Not found', 404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css',
            'js' => 'text/javascript',
            'map' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'json' => 'application/json',
        ];
        Response::file($path, $types[$ext] ?? 'application/octet-stream');
    }

    public static function resolveAsset(string $id, string $relative): ?string
    {
        self::boot();
        $record = self::$records[$id] ?? null;
        if ($record === null || empty($record['enabled']) || empty($record['booted'])) {
            return null;
        }
        $relative = str_replace('\\', '/', $relative);
        if (
            $relative === ''
            || str_starts_with($relative, '/')
            || str_contains($relative, "\0")
            || preg_match('#(^|/)\.\.(/|$)#', $relative)
        ) {
            return null;
        }
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ASSET_EXTENSIONS, true)) {
            return null;
        }
        $root = realpath((string) $record['directory'] . '/assets');
        if ($root === false) {
            return null;
        }
        $full = $root . '/' . $relative;
        $real = realpath($full);
        if ($real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

    /**
     * @return array<string, mixed>
     */
    private static function onePayload(string $id, bool $includeSettings): array
    {
        $record = self::$records[$id] ?? self::missingRecord($id);
        $manifest = is_array($record['manifest']) ? $record['manifest'] : [];
        $enabled = !empty($record['enabled']);
        $booted = !empty($record['booted']);
        $contributions = $enabled && $booted && is_array($record['contributions'] ?? null)
            ? $record['contributions']
            : ['workspaces' => [], 'side_tabs' => []];
        $hookError = PluginHooks::errors()[$id] ?? null;
        $error = $record['error'] ?? null;
        if ($hookError) {
            $error = $error ? $error . ' ' . $hookError : $hookError;
        }
        $item = [
            'id' => $id,
            'name' => (string) ($manifest['name'] ?? $id),
            'version' => (string) ($manifest['version'] ?? ''),
            'description' => (string) ($manifest['description'] ?? ''),
            'api' => $manifest['api'] ?? null,
            'enabled' => $enabled,
            'origin' => (string) ($record['origin'] ?? 'user'),
            'error' => $error,
            'capabilities' => $manifest['capabilities'] ?? [],
            'contributions' => $contributions,
        ];
        if ($includeSettings) {
            $item['settings'] = is_array($manifest['settings'] ?? null) ? self::settingsFor($manifest) : [];
        }
        return $item;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    private static function settingsFor(array $manifest): array
    {
        $api = PluginApi::forPlugin($manifest, (string) (self::$records[$manifest['id']]['directory'] ?? ''));
        $out = [];
        foreach ($manifest['settings'] ?? [] as $field) {
            $value = $api->option($field['key'], null);
            $row = [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
            ];
            if ($field['type'] === 'secret') {
                $row['set'] = $value !== null && $value !== '';
            } else {
                $row['value'] = $value;
            }
            if ($field['type'] === 'select') {
                $row['options'] = $field['options'] ?? [];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function coerceSetting(array $field, mixed $value): mixed
    {
        $type = (string) ($field['type'] ?? '');
        return match ($type) {
            'number' => is_numeric($value) ? 0 + $value : throw new PluginException('Setting must be a number'),
            'checkbox' => (bool) $value,
            'secret', 'text' => is_scalar($value) ? (string) $value : throw new PluginException('Setting must be text'),
            'select' => self::coerceSelect(is_array($field['options'] ?? null) ? $field['options'] : [], $value),
            default => throw new PluginException('Setting type is invalid'),
        };
    }

    /**
     * @param list<array{value?: string, label?: string}> $options
     */
    private static function coerceSelect(array $options, mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new PluginException('Setting must be one of the listed options');
        }
        $value = (string) $value;
        foreach ($options as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === $value) {
                return $value;
            }
        }
        throw new PluginException('Setting must be one of the listed options');
    }

    private static function discoverAndBoot(): void
    {
        $found = self::scan(self::bundledDir(), 'bundled');
        foreach (self::scan(self::userDir(), 'user') as $id => $hit) {
            $found[$id] = $hit;
        }
        ksort($found);
        foreach ($found as $id => $hit) {
            self::bootOne((string) $id, $hit['directory'], $hit['origin']);
        }
    }

    /**
     * @return array<string, array{directory: string, origin: string}>
     */
    private static function scan(string $root, string $origin): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $found = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            if (!preg_match(PluginManifest::ID_PATTERN, $name)) {
                continue;
            }
            $dir = $root . '/' . $name;
            if (!is_dir($dir)) {
                continue;
            }
            $found[$name] = ['directory' => $dir, 'origin' => $origin];
        }
        return $found;
    }

    private static function bootOne(string $id, string $directory, string $origin): void
    {
        $record = [
            'id' => $id,
            'directory' => $directory,
            'origin' => $origin,
            'enabled' => self::isEnabled($id),
            'manifest' => null,
            'booted' => false,
            'error' => null,
            'contributions' => ['workspaces' => [], 'side_tabs' => []],
        ];
        try {
            $manifest = PluginManifest::parse($directory);
            $record['manifest'] = $manifest;
            if ((int) $manifest['api'] !== PluginApi::VERSION) {
                $record['error'] = 'Plugin API ' . $manifest['api'] . ' is not supported (this Sneeze speaks API ' . PluginApi::VERSION . ')';
                self::$records[$id] = $record;
                return;
            }
            if (!$record['enabled']) {
                self::$records[$id] = $record;
                return;
            }
            $entry = $directory . '/' . $manifest['entry'];
            $class = $manifest['class'];
            $existed = class_exists($class, false);
            require_once $entry;
            if (!class_exists($class, false)) {
                throw new PluginException('Plugin class ' . $class . ' was not defined');
            }
            $defined = (new ReflectionClass($class))->getFileName();
            if ($existed && $defined !== realpath($entry)) {
                throw new PluginException('Plugin class ' . $class . ' is already defined');
            }
            $instance = new $class();
            if (!$instance instanceof SneezePlugin) {
                throw new PluginException('Plugin class must implement SneezePlugin');
            }
            $api = PluginApi::forPlugin($manifest, $directory);
            $instance->boot($api);
            $record['contributions'] = $api->contributions();
            $record['booted'] = true;
        } catch (Throwable $e) {
            PluginHooks::forget($id);
            self::forgetRoutes($id);
            $record['booted'] = false;
            $record['error'] = $e->getMessage();
            $record['contributions'] = ['workspaces' => [], 'side_tabs' => []];
        }
        self::$records[$id] = $record;
    }

    private static function forgetRoutes(string $pluginId): void
    {
        foreach (self::$routes as $key => $route) {
            if ($route['plugin'] === $pluginId) {
                unset(self::$routes[$key]);
            }
        }
    }

    /**
     * @return array{plugin: string, handler: callable, admin: bool, stream: bool}|null
     */
    private static function findRoute(string $method, string $uri): ?array
    {
        if (!preg_match('#^/api/plugin/([a-z][a-z0-9-]{0,40})(?:/(.*))?$#', $uri, $m)) {
            return null;
        }
        $path = trim((string) ($m[2] ?? ''), '/');
        $key = strtoupper($method) . ' ' . $m[1] . ' ' . $path;
        return self::$routes[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(string $id): array
    {
        if (!isset(self::$records[$id])) {
            throw new PluginException('Plugin not found');
        }
        return self::$records[$id];
    }

    /**
     * @return array<string, mixed>
     */
    private static function missingRecord(string $id): array
    {
        return [
            'id' => $id,
            'origin' => 'user',
            'enabled' => false,
            'manifest' => null,
            'error' => 'Plugin not found',
            'contributions' => ['workspaces' => [], 'side_tabs' => []],
        ];
    }

    private static function isEnabled(string $id): bool
    {
        $stmt = Database::pdo()->prepare('SELECT enabled FROM plugins WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row && (int) $row['enabled'] === 1;
    }

    private static function touchInstalled(string $id): void
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM plugins WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            self::remember($id, false);
            return;
        }
        Database::pdo()->prepare(
            'UPDATE plugins SET updated_at = datetime(\'now\') WHERE id = ?'
        )->execute([$id]);
    }

    private static function remember(string $id, bool $enabled): void
    {
        Database::pdo()->prepare(
            'INSERT INTO plugins (id, enabled, installed_at, updated_at) VALUES (?, ?, datetime(\'now\'), datetime(\'now\'))
             ON CONFLICT(id) DO UPDATE SET enabled = excluded.enabled, updated_at = datetime(\'now\')'
        )->execute([$id, $enabled ? 1 : 0]);
    }

    private static function bundledDir(): string
    {
        return Config::installDir() . '/plugins';
    }

    private static function userDir(): string
    {
        $dir = Config::dataDir() . '/plugins';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    private static function detectPackageRoot(string $extractRoot): string
    {
        if (is_file($extractRoot . '/plugin.json')) {
            return $extractRoot;
        }
        $entries = array_values(array_filter(
            scandir($extractRoot) ?: [],
            static fn ($entry) => $entry !== '.' && $entry !== '..'
        ));
        if (count($entries) === 1) {
            $only = $extractRoot . '/' . $entries[0];
            if (is_dir($only) && is_file($only . '/plugin.json')) {
                return $only;
            }
        }
        foreach ($entries as $entry) {
            $path = $extractRoot . '/' . $entry;
            if (is_dir($path) && is_file($path . '/plugin.json')) {
                return $path;
            }
        }
        throw new PluginException('Zip is missing plugin.json');
    }

    private static function replaceTree(string $from, string $to): void
    {
        $parent = dirname($to);
        if (!is_dir($parent)) {
            mkdir($parent, 0750, true);
        }
        $staging = $parent . '/.staging-' . basename($to) . '-' . bin2hex(random_bytes(4));
        try {
            self::copyTree($from, $staging);
        } catch (Throwable $e) {
            self::rmTree($staging);
            throw $e;
        }
        if (is_dir($to)) {
            self::rmTree($to);
        }
        if (!rename($staging, $to)) {
            self::rmTree($staging);
            throw new PluginException('Could not install plugin files');
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!mkdir($to, 0750, true) && !is_dir($to)) {
            throw new PluginException('Could not create plugin directory');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isLink()) {
                throw new PluginException('Zip contains a link');
            }
            $rel = str_replace('\\', '/', $iterator->getSubPathname());
            if ($rel === '' || self::unsafeZipName($rel)) {
                throw new PluginException('Zip contains an unsafe path');
            }
            $dest = $to . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest) && !mkdir($dest, 0750, true) && !is_dir($dest)) {
                    throw new PluginException('Could not create plugin directory');
                }
                continue;
            }
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0750, true);
            }
            if (!copy($item->getPathname(), $dest)) {
                throw new PluginException('Could not copy plugin file');
            }
        }
    }

    private static function unsafeZipName(string $name): bool
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_contains($name, "\0")) {
            return true;
        }
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
            return true;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                return true;
            }
        }
        return false;
    }

    private static function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
