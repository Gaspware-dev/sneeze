<?php

declare(strict_types=1);

class Api
{
    public static function handle(string $method, string $uri): void
    {
        $pathBrowser = new PathBrowser(Config::installDir());

        if ($uri === '/api/setup/browse' && $method === 'GET') {
            if (Config::isSetupComplete()) {
                Response::error('Setup already complete', 403);
            }
            Response::json($pathBrowser->browse($_GET['path'] ?? null));
        }

        if ($uri === '/api/setup/validate' && $method === 'POST') {
            if (Config::isSetupComplete()) {
                Response::error('Setup already complete', 403);
            }
            $body = self::jsonBody();
            Response::json($pathBrowser->validateSiteRoot($body['path'] ?? ''));
        }

        if ($uri === '/api/setup/complete' && $method === 'POST') {
            if (Config::isSetupComplete()) {
                Response::error('Setup already complete', 403);
            }
            if (Auth::userCount() > 0) {
                Response::error('Setup already complete', 403);
            }
            $body = self::jsonBody();
            $siteRoot = $body['site_root'] ?? '';
            $pathBrowser->validateSiteRoot($siteRoot);

            Config::set('site_root', realpath($siteRoot));
            Config::set('site_web_base', $body['site_web_base'] ?? Config::detectWebBase());
            Config::set('upload_dir', Config::sanitizeUploadDir((string) ($body['upload_dir'] ?? 'uploads')));
            Config::set('site_title', $body['site_title'] ?? 'Sneeze Admin');
            Config::set('setup_complete', true);
            Config::save();

            $user = Auth::createUser($body['username'] ?? '', $body['password'] ?? '', 'admin');
            Auth::login($body['username'] ?? '', $body['password'] ?? '');
            Response::json(['ok' => true, 'user' => $user]);
        }

        if ($uri === '/api/auth/login' && $method === 'POST') {
            $body = self::jsonBody();
            $user = Auth::login($body['username'] ?? '', $body['password'] ?? '');
            Response::json(['user' => $user, 'csrf' => Auth::csrfToken()]);
        }

        if ($uri === '/api/auth/logout' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::logout();
            Response::json(['ok' => true]);
        }

        if ($uri === '/api/auth/me' && $method === 'GET') {
            $user = Auth::user();
            if (!$user) {
                Response::json(['user' => null]);
            }
            Response::json(['user' => $user, 'csrf' => Auth::csrfToken(), 'config' => self::publicConfig()]);
        }

        if (!Config::isSetupComplete()) {
            Response::error('Setup required', 503);
        }

        $user = Auth::requireAuth();

        if ($uri === '/api/plugins' && $method === 'GET') {
            Response::json(Plugins::listPayload($user['role'] === 'admin'));
        }

        if ($uri === '/api/plugins/reference' && $method === 'GET') {
            Auth::requireAdmin();
            Response::json(Plugins::referencePayload());
        }

        if ($uri === '/api/plugins/upload' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            if (!isset($_FILES['file'])) {
                Response::error('file required');
            }
            try {
                $installed = Plugins::installZip($_FILES['file']);
            } catch (PluginException $e) {
                Response::error($e->getMessage());
            }
            Auth::audit((int) $user['id'], 'install_plugin', (string) ($installed['id'] ?? ''));
            Response::json(['plugin' => $installed]);
        }

        if (preg_match('#^/api/plugins/([a-z][a-z0-9-]{0,40})/(enable|disable)$#', $uri, $m) && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            try {
                $plugin = $m[2] === 'enable' ? Plugins::enable($m[1]) : Plugins::disable($m[1]);
            } catch (PluginException $e) {
                Response::error($e->getMessage());
            }
            Auth::audit((int) $user['id'], $m[2] . '_plugin', $m[1]);
            Response::json(['plugin' => $plugin]);
        }

        if (preg_match('#^/api/plugins/([a-z][a-z0-9-]{0,40})/settings$#', $uri, $m) && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            try {
                $settings = Plugins::saveSettings($m[1], self::jsonBody());
            } catch (PluginException $e) {
                Response::error($e->getMessage());
            }
            Response::json(['settings' => $settings]);
        }

        if (preg_match('#^/api/plugins/([a-z][a-z0-9-]{0,40})$#', $uri, $m) && $method === 'DELETE') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            try {
                Plugins::delete($m[1]);
            } catch (PluginException $e) {
                Response::error($e->getMessage());
            }
            Auth::audit((int) $user['id'], 'delete_plugin', $m[1]);
            Response::json(['ok' => true]);
        }

        if (str_starts_with($uri, '/api/plugin/')) {
            $verb = strtoupper($method);
            if (!in_array($verb, ['GET', 'HEAD'], true)) {
                Auth::verifyCsrf();
            }
            Plugins::dispatch($method, $uri);
        }

        if ($uri === '/api/pages' && $method === 'GET') {
            Response::json(['pages' => PageRepository::all()]);
        }

        if ($uri === '/api/pages' && $method === 'POST') {
            Auth::verifyCsrf();
            $page = PageRepository::create(self::jsonBody());
            Auth::audit((int) $user['id'], 'create_page', $page['slug']);
            Response::json(['page' => $page]);
        }

        if (preg_match('#^/api/pages/(\d+)/archive$#', $uri, $m) && $method === 'POST') {
            Auth::verifyCsrf();
            $id = (int) $m[1];
            $page = PageRepository::archive($id);
            (new Publisher())->unpublishPage($page);
            Auth::audit((int) $user['id'], 'archive_page', $page['slug']);
            Response::json(['page' => PageRepository::get($id, false)]);
        }

        if (preg_match('#^/api/pages/(\d+)/restore$#', $uri, $m) && $method === 'POST') {
            Auth::verifyCsrf();
            $id = (int) $m[1];
            $page = PageRepository::restore($id);
            Auth::audit((int) $user['id'], 'restore_page', $page['slug']);
            Response::json(['page' => $page]);
        }

        if (preg_match('#^/api/pages/(\d+)$#', $uri, $m)) {
            $id = (int) $m[1];
            if ($method === 'GET') {
                Response::json(['page' => PageRepository::get($id)]);
            }
            if ($method === 'PUT' || $method === 'POST') {
                Auth::verifyCsrf();
                $page = PageRepository::update($id, self::jsonBody());
                Auth::audit((int) $user['id'], 'save_page', $page['slug']);
                Response::json(['page' => $page]);
            }
            if ($method === 'DELETE') {
                Auth::verifyCsrf();
                Auth::requireAdmin();
                $page = PageRepository::get($id, false);
                PageRepository::delete($id);
                (new Publisher())->unpublishPage($page);
                Auth::audit((int) $user['id'], 'delete_page', $page['slug']);
                Response::json(['ok' => true]);
            }
        }

        if (preg_match('#^/api/pages/(\d+)/preview$#', $uri, $m) && $method === 'POST') {
            Auth::verifyCsrf();
            $html = (new Compiler())->compilePage((int) $m[1], true, self::jsonBody() ?: null);
            Response::json(['html' => $html]);
        }

        if (preg_match('#^/api/pages/(\d+)/preview$#', $uri, $m) && $method === 'GET') {
            $html = (new Compiler())->compilePage((int) $m[1], true);
            Response::html($html);
        }

        if (preg_match('#^/api/pages/(\d+)/publish$#', $uri, $m) && $method === 'POST') {
            Auth::verifyCsrf();
            Response::json((new Publisher())->publishPage((int) $m[1], (int) $user['id']));
        }

        if ($uri === '/api/publish-all' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            Response::json((new Publisher())->publishAll((int) $user['id']));
        }

        if ($uri === '/api/templates' && $method === 'GET') {
            Response::json(['templates' => TemplateRepository::all()]);
        }

        if ($uri === '/api/templates' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            Response::json(['template' => TemplateRepository::save(self::jsonBody())]);
        }

        if (preg_match('#^/api/templates/(\d+)$#', $uri, $m)) {
            Auth::requireAdmin();
            $id = (int) $m[1];
            if ($method === 'GET') {
                Response::json(['template' => TemplateRepository::get($id)]);
            }
            if ($method === 'PUT' || $method === 'POST') {
                Auth::verifyCsrf();
                $body = self::jsonBody();
                $body['id'] = $id;
                Response::json(['template' => TemplateRepository::save($body)]);
            }
            if ($method === 'DELETE') {
                Auth::verifyCsrf();
                TemplateRepository::delete($id);
                Response::json(['ok' => true]);
            }
        }

        if ($uri === '/api/block-types' && $method === 'GET') {
            Response::json(['block_types' => BlockTypeRepository::all()]);
        }

        if ($uri === '/api/block-types' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            Response::json(['block_type' => BlockTypeRepository::save(self::jsonBody())]);
        }

        if (preg_match('#^/api/block-types/(\d+)$#', $uri, $m)) {
            $id = (int) $m[1];
            if ($method === 'GET') {
                Response::json(['block_type' => BlockTypeRepository::get($id)]);
            }
            if ($method === 'PUT' || $method === 'POST') {
                Auth::verifyCsrf();
                Auth::requireAdmin();
                $body = self::jsonBody();
                $body['id'] = $id;
                Response::json(['block_type' => BlockTypeRepository::save($body)]);
            }
            if ($method === 'DELETE') {
                Auth::verifyCsrf();
                Auth::requireAdmin();
                BlockTypeRepository::delete($id);
                Response::json(['ok' => true]);
            }
        }

        if ($uri === '/api/design' && $method === 'GET') {
            Response::json([
                'tokens' => DesignRepository::tokens(),
                'stylesheets' => DesignRepository::stylesheets(),
                'scripts' => DesignRepository::scripts(),
                'chrome' => DesignRepository::chrome(),
                'toolchain' => ToolchainRepository::settings(),
            ]);
        }

        if ($uri === '/api/design/chrome' && $method === 'GET') {
            Auth::requireAdmin();
            Response::json(['chrome' => DesignRepository::chrome()]);
        }

        if ($uri === '/api/design/chrome' && $method === 'PUT') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            Response::json(['chrome' => DesignRepository::saveChrome(self::jsonBody())]);
        }

        if ($uri === '/api/design/tokens' && $method === 'PUT') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            $body = self::jsonBody();
            Response::json(['tokens' => DesignRepository::saveTokens($body['tokens'] ?? $body)]);
        }

        if ($uri === '/api/design/toolchain' && $method === 'PUT') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            Response::json(['toolchain' => ToolchainRepository::save(self::jsonBody())]);
        }

        if ($uri === '/api/design/stylesheets' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            Response::json(['stylesheet' => DesignRepository::saveStylesheet(self::jsonBody())]);
        }

        if (preg_match('#^/api/design/stylesheets/(\d+)$#', $uri, $m) && ($method === 'PUT' || $method === 'POST')) {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            $body = self::jsonBody();
            $body['id'] = (int) $m[1];
            Response::json(['stylesheet' => DesignRepository::saveStylesheet($body)]);
        }

        if (preg_match('#^/api/design/stylesheets/(\d+)$#', $uri, $m) && $method === 'DELETE') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            DesignRepository::deleteStylesheet((int) $m[1]);
            Response::json(['ok' => true]);
        }

        if ($uri === '/api/design/scripts' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            Response::json(['script' => DesignRepository::saveScript(self::jsonBody())]);
        }

        if (preg_match('#^/api/design/scripts/(\d+)$#', $uri, $m) && ($method === 'PUT' || $method === 'POST')) {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            $body = self::jsonBody();
            $body['id'] = (int) $m[1];
            Response::json(['script' => DesignRepository::saveScript($body)]);
        }

        if (preg_match('#^/api/design/scripts/(\d+)$#', $uri, $m) && $method === 'DELETE') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            DesignRepository::deleteScript((int) $m[1]);
            Response::json(['ok' => true]);
        }

        $media = new MediaStore();

        if ($uri === '/api/media' && $method === 'GET') {
            Response::json(['items' => $media->list()]);
        }

        if ($uri === '/api/media' && $method === 'POST') {
            Auth::verifyCsrf();
            if (!isset($_FILES['file'])) {
                Response::error('file required');
            }
            $item = $media->upload($_FILES['file'], (int) $user['id']);
            Auth::audit((int) $user['id'], 'upload', $item['path']);
            Response::json(['item' => $item]);
        }

        if (preg_match('#^/api/media/(\d+)$#', $uri, $m) && $method === 'DELETE') {
            Auth::verifyCsrf();
            $media->delete((int) $m[1]);
            Auth::audit((int) $user['id'], 'delete_media', $m[1]);
            Response::json(['ok' => true]);
        }

        if (preg_match('#^/api/media/file/(\d+)$#', $uri, $m) && $method === 'GET') {
            $media->stream((int) $m[1]);
        }

        if (preg_match('#^/api/media/thumb/(\d+)$#', $uri, $m) && $method === 'GET') {
            $thumb = Config::dataDir() . '/thumbs/' . $m[1] . '.jpg';
            if (!is_file($thumb)) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: image/jpeg');
            readfile($thumb);
            exit;
        }

        if ($uri === '/api/import/html' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            $html = '';
            $sourcePath = null;
            $name = null;
            $slug = null;
            $opts = [];
            if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $html = (string) file_get_contents($_FILES['file']['tmp_name']);
                $name = pathinfo((string) ($_FILES['file']['name'] ?? 'imported'), PATHINFO_FILENAME);
                $opts = [
                    'name' => $_POST['name'] ?? $name,
                    'slug' => $_POST['slug'] ?? null,
                    'create_template' => ($_POST['create_template'] ?? '1') !== '0',
                    'create_page' => ($_POST['create_page'] ?? '1') !== '0',
                    'copy_assets' => ($_POST['copy_assets'] ?? '1') !== '0',
                    'template_id' => isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0,
                    'user_id' => (int) $user['id'],
                ];
            } else {
                $body = self::jsonBody();
                if (!empty($body['path'])) {
                    $file = $pathBrowser->readHtmlFile((string) $body['path']);
                    $html = $file['html'];
                    $sourcePath = $file['path'];
                    $name = pathinfo($file['name'], PATHINFO_FILENAME);
                } else {
                    $html = (string) ($body['html'] ?? '');
                    $name = $body['name'] ?? 'Imported';
                    $sourcePath = isset($body['source_path']) ? (string) $body['source_path'] : null;
                }
                $opts = [
                    'name' => $body['name'] ?? $name,
                    'slug' => $body['slug'] ?? null,
                    'source_path' => $sourcePath,
                    'create_template' => ($body['create_template'] ?? true) !== false,
                    'create_page' => ($body['create_page'] ?? true) !== false,
                    'copy_assets' => ($body['copy_assets'] ?? true) !== false,
                    'template_id' => (int) ($body['template_id'] ?? 0),
                    'user_id' => (int) $user['id'],
                ];
            }
            $result = (new HtmlImporter())->import($html, $opts);
            Auth::audit((int) $user['id'], 'import_html', $result['page']['slug'] ?? $result['template']['slug'] ?? '');
            Response::json($result);
        }

        if ($uri === '/api/users' && $method === 'GET') {
            Auth::requireAdmin();
            $stmt = Database::pdo()->query('SELECT id, username, role, created_at FROM users ORDER BY username');
            Response::json(['users' => $stmt->fetchAll()]);
        }

        if ($uri === '/api/users' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            $body = self::jsonBody();
            $newUser = Auth::createUser($body['username'] ?? '', $body['password'] ?? '', $body['role'] ?? 'editor');
            Response::json(['user' => $newUser]);
        }

        if (preg_match('#^/api/users/(\d+)$#', $uri, $m) && $method === 'DELETE') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            $id = (int) $m[1];
            if ($id === (int) $user['id']) {
                Response::error('Cannot delete yourself');
            }
            $admins = (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            $stmt = Database::pdo()->prepare('SELECT role FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                Response::error('Not found', 404);
            }
            if ($target['role'] === 'admin' && $admins <= 1) {
                Response::error('Cannot delete last admin');
            }
            Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            Response::json(['ok' => true]);
        }

        if ($uri === '/api/settings' && $method === 'GET') {
            Auth::requireAdmin();
            $settings = Config::all();
            $settings['version'] = Version::current();
            Response::json(['settings' => $settings]);
        }

        if ($uri === '/api/settings/browse' && $method === 'GET') {
            Auth::requireAdmin();
            Response::json($pathBrowser->browse($_GET['path'] ?? null));
        }

        if ($uri === '/api/settings/update' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            if (!isset($_FILES['file'])) {
                Response::error('file required');
            }
            $result = Updater::applyUpload($_FILES['file']);
            Auth::audit((int) $user['id'], 'update_sneeze', $result['version']);
            Response::json($result);
        }

        if ($uri === '/api/settings' && $method === 'POST') {
            Auth::verifyCsrf();
            Auth::requireAdmin();
            $body = self::jsonBody();
            if (isset($body['site_root'])) {
                $pathBrowser->validateSiteRoot($body['site_root']);
                Config::set('site_root', realpath($body['site_root']));
            }
            if (isset($body['site_web_base'])) {
                Config::set('site_web_base', $body['site_web_base']);
            }
            if (isset($body['upload_dir'])) {
                Config::set('upload_dir', Config::sanitizeUploadDir((string) $body['upload_dir']));
            }
            if (isset($body['backup_retention'])) {
                Config::set('backup_retention', max(1, (int) $body['backup_retention']));
            }
            if (isset($body['site_title'])) {
                Config::set('site_title', $body['site_title']);
            }
            if (array_key_exists('visual_editors', $body)) {
                Config::set('visual_editors', (bool) $body['visual_editors']);
            }
            Config::save();
            $settings = Config::all();
            $settings['version'] = Version::current();
            Response::json(['settings' => $settings]);
        }

        Response::error('Not found', 404);
    }

    private static function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function publicConfig(): array
    {
        return [
            'site_title' => Config::get('site_title'),
            'site_web_base' => Config::siteWebBase(),
            'upload_dir' => Config::uploadDir(),
            'draft_upload_dir' => 'sneeze/data/uploads',
            'visual_editors' => Config::get('visual_editors', true) !== false,
            'version' => Version::current(),
        ];
    }
}
