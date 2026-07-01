<?php

declare(strict_types=1);

$installDir = __DIR__;

spl_autoload_register(function (string $class) use ($installDir): void {
    $file = $installDir . '/lib/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::init($installDir);
Database::connect(Config::dataDir());
Auth::startSession($installDir);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($basePath !== '' && str_starts_with($uri, $basePath)) {
    $uri = substr($uri, strlen($basePath)) ?: '/';
}
$uri = '/' . trim($uri, '/');
if ($uri !== '/') {
    $uri = rtrim($uri, '/');
}

// Serve static assets
if (str_starts_with($uri, '/assets/')) {
    $assetPath = $installDir . $uri;
    if (is_file($assetPath)) {
        $ext = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'woff2' => 'font/woff2',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
        ];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($assetPath);
        exit;
    }
    http_response_code(404);
    exit;
}

// API routes
if (str_starts_with($uri, '/api/')) {
    handleApi($method, $uri);
    exit;
}

// Page routes
renderPage($uri);
exit;

function handleApi(string $method, string $uri): void
{
    $pathBrowser = new PathBrowser(Config::installDir());
    $files = new FileManager();
    $segmenter = new HtmlSegmenter();
    $preview = new Preview();
    $media = new MediaStore();
    $sanitizer = new Sanitizer();

    // Setup
    if ($uri === '/api/setup/browse' && $method === 'GET') {
        if (Config::isSetupComplete()) {
            Response::error('Setup already complete', 403);
        }
        $path = $_GET['path'] ?? null;
        Response::json($pathBrowser->browse($path));
    }

    if ($uri === '/api/setup/validate' && $method === 'POST') {
        if (Config::isSetupComplete()) {
            Response::error('Setup already complete', 403);
        }
        $body = jsonBody();
        Response::json($pathBrowser->validateSiteRoot($body['path'] ?? ''));
    }

    if ($uri === '/api/setup/complete' && $method === 'POST') {
        if (Config::isSetupComplete()) {
            Response::error('Setup already complete', 403);
        }
        if (Auth::userCount() > 0) {
            Response::error('Setup already complete', 403);
        }
        $body = jsonBody();
        $siteRoot = $body['site_root'] ?? '';
        $pathBrowser->validateSiteRoot($siteRoot);

        Config::set('site_root', realpath($siteRoot));
        Config::set('site_web_base', $body['site_web_base'] ?? Config::detectWebBase());
        Config::set('upload_dir', $body['upload_dir'] ?? 'uploads');
        Config::set('site_title', $body['site_title'] ?? 'Sneeze Admin');
        Config::set('setup_complete', true);
        Config::save();

        $user = Auth::createUser(
            $body['username'] ?? '',
            $body['password'] ?? '',
            'admin'
        );
        Auth::login($body['username'] ?? '', $body['password'] ?? '');

        Response::json(['ok' => true, 'user' => $user]);
    }

    if ($uri === '/api/auth/login' && $method === 'POST') {
        $body = jsonBody();
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
        Response::json(['user' => $user, 'csrf' => Auth::csrfToken(), 'config' => publicConfig()]);
    }

    if (!Config::isSetupComplete()) {
        Response::error('Setup required', 503);
    }

    $user = Auth::requireAuth();

    if ($uri === '/api/files' && $method === 'GET') {
        Response::json(['files' => $files->listHtmlFiles()]);
    }

    if ($uri === '/api/files/content' && $method === 'GET') {
        $path = $_GET['path'] ?? '';
        if ($path === '') {
            Response::error('path required');
        }
        $content = $files->read($path, true);
        $parsed = $segmenter->parse($content);
        Response::json([
            'path' => $path,
            'content' => $content,
            'segments' => $parsed['segments'],
            'live_url' => $preview->liveUrl($path),
            'preview_url' => 'api/preview?path=' . rawurlencode($path),
        ]);
    }

    if ($uri === '/api/files/content' && $method === 'POST') {
        Auth::verifyCsrf();
        $body = jsonBody();
        $path = $body['path'] ?? '';
        $content = $body['content'] ?? '';
        if ($path === '' || $content === '') {
            Response::error('path and content required');
        }
        if (!empty($body['sanitize'])) {
            $content = $sanitizer->clean($content);
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            Response::error('Invalid HTML');
        }
        libxml_clear_errors();
        $files->write($path, $content);
        Auth::audit((int) $user['id'], 'save', $path);
        Response::json(['ok' => true]);
    }

    if ($uri === '/api/files/segments' && $method === 'POST') {
        Auth::verifyCsrf();
        $body = jsonBody();
        $path = $body['path'] ?? '';
        $segments = $body['segments'] ?? [];
        if ($path === '' || !is_array($segments)) {
            Response::error('path and segments required');
        }
        $original = $files->read($path, false);
        $content = $segmenter->reassemble($segments, $original);
        if (!empty($body['sanitize'])) {
            foreach ($segments as &$seg) {
                if (($seg['type'] ?? '') === 'block') {
                    $seg['content'] = $sanitizer->clean($seg['content']);
                }
            }
            $content = $segmenter->reassemble($segments, $original);
        }
        $files->write($path, $content);
        Auth::audit((int) $user['id'], 'save_segments', $path);
        Response::json(['ok' => true, 'content' => $content]);
    }

    if ($uri === '/api/files/section' && $method === 'POST') {
        Auth::verifyCsrf();
        $body = jsonBody();
        $path = $body['path'] ?? '';
        $templateId = $body['template_id'] ?? 'blank';
        if ($path === '') {
            Response::error('path required');
        }
        $templates = $segmenter->sectionTemplates();
        $html = null;
        foreach ($templates as $t) {
            if ($t['id'] === $templateId) {
                $html = $t['html'];
                break;
            }
        }
        if (!$html) {
            Response::error('Unknown template');
        }
        $original = $files->read($path, false);
        $content = $segmenter->insertSection($original, $html);
        $files->write($path, $content);
        Auth::audit((int) $user['id'], 'add_section', $path);
        $parsed = $segmenter->parse($content);
        Response::json(['ok' => true, 'content' => $content, 'segments' => $parsed['segments']]);
    }

    if ($uri === '/api/files/templates' && $method === 'GET') {
        Response::json(['templates' => $segmenter->sectionTemplates()]);
    }

    if ($uri === '/api/preview' && $method === 'GET') {
        $path = $_GET['path'] ?? '';
        if ($path === '') {
            Response::error('path required');
        }
        $content = $files->read($path, false);
        Response::html($preview->render($content, $path));
    }

    if ($uri === '/api/preview' && $method === 'POST') {
        Auth::verifyCsrf();
        $body = jsonBody();
        $path = $body['path'] ?? '';
        $content = $body['content'] ?? '';
        if ($path === '' || $content === '') {
            Response::error('path and content required');
        }
        Response::html($preview->render($content, $path));
    }

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

    if ($uri === '/api/media/relative' && $method === 'POST') {
        $body = jsonBody();
        $from = $body['from_file'] ?? '';
        $to = $body['to_path'] ?? '';
        Response::json(['url' => $files->relativeUrlTo($from, $to)]);
    }

    if ($uri === '/api/users' && $method === 'GET') {
        Auth::requireAdmin();
        $stmt = Database::pdo()->query('SELECT id, username, role, created_at FROM users ORDER BY username');
        Response::json(['users' => $stmt->fetchAll()]);
    }

    if ($uri === '/api/users' && $method === 'POST') {
        Auth::verifyCsrf();
        Auth::requireAdmin();
        $body = jsonBody();
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
        $del = Database::pdo()->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$id]);
        Response::json(['ok' => true]);
    }

    if ($uri === '/api/settings' && $method === 'GET') {
        Auth::requireAdmin();
        Response::json(['settings' => Config::all()]);
    }

    if ($uri === '/api/settings/browse' && $method === 'GET') {
        Auth::requireAdmin();
        $path = $_GET['path'] ?? null;
        Response::json($pathBrowser->browse($path));
    }

    if ($uri === '/api/settings' && $method === 'POST') {
        Auth::verifyCsrf();
        Auth::requireAdmin();
        $body = jsonBody();
        if (isset($body['site_root'])) {
            $pathBrowser->validateSiteRoot($body['site_root']);
            Config::set('site_root', realpath($body['site_root']));
        }
        if (isset($body['site_web_base'])) {
            Config::set('site_web_base', $body['site_web_base']);
        }
        if (isset($body['upload_dir'])) {
            Config::set('upload_dir', trim($body['upload_dir'], '/'));
        }
        if (isset($body['backup_retention'])) {
            Config::set('backup_retention', max(1, (int) $body['backup_retention']));
        }
        if (isset($body['site_title'])) {
            Config::set('site_title', $body['site_title']);
        }
        Config::save();
        Response::json(['settings' => Config::all()]);
    }

    Response::error('Not found', 404);
}

function renderPage(string $uri): void
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $assetBase = ($base === '' ? '' : $base) . '/assets';
    $title = Config::get('site_title', 'Sneeze Admin');

    if (!Config::isSetupComplete()) {
        require __DIR__ . '/views/setup.php';
        return;
    }

    if ($uri === '/login') {
        if (Auth::user()) {
            header('Location: ' . ($base ?: '/') . '/');
            exit;
        }
        require __DIR__ . '/views/login.php';
        return;
    }

    if (!Auth::user()) {
        header('Location: ' . ($base ?: '/') . '/login');
        exit;
    }

    if ($uri === '/' || $uri === '') {
        require __DIR__ . '/views/admin.php';
        return;
    }

    http_response_code(404);
    echo 'Not found';
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function publicConfig(): array
{
    return [
        'site_title' => Config::get('site_title'),
        'site_web_base' => Config::siteWebBase(),
        'upload_dir' => Config::uploadDir(),
    ];
}
