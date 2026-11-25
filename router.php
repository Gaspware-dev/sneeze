<?php
// Dev router for the PHP built-in server (repo root served as the Sneeze app).
// PHP's built-in server ignores .htaccess, so mimic it: serve real static files
// directly, route everything else through index.php.

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (preg_match('#^/(lib|data|views|plugins)(/|$)#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false; // let the built-in server serve the static file
}

require __DIR__ . '/index.php';
