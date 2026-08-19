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
Requirements::check();
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

if (str_starts_with($uri, '/api/')) {
    Api::handle($method, $uri);
    exit;
}

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$assetBase = ($base === '' ? '' : $base) . '/assets';
$title = Config::get('site_title', 'Sneeze Admin');

if (!Config::isSetupComplete()) {
    require __DIR__ . '/views/setup.php';
    exit;
}

if ($uri === '/login') {
    if (Auth::user()) {
        header('Location: ' . ($base ?: '/') . '/');
        exit;
    }
    require __DIR__ . '/views/login.php';
    exit;
}

if (!Auth::user()) {
    header('Location: ' . ($base ?: '/') . '/login');
    exit;
}

if ($uri === '/' || $uri === '') {
    require __DIR__ . '/views/admin.php';
    exit;
}

http_response_code(404);
echo 'Not found';
