<?php

declare(strict_types=1);

class Requirements
{
    public static function check(): void
    {
        $missing = [];

        if (!extension_loaded('pdo_sqlite')) {
            $missing[] = 'pdo_sqlite (package: php-sqlite3 or php8.3-sqlite3)';
        }
        if (!class_exists('DOMDocument')) {
            $missing[] = 'dom / DOMDocument (package: php-xml or php8.3-xml)';
        }
        if (!extension_loaded('fileinfo')) {
            $missing[] = 'fileinfo (package: php-fileinfo, usually included with php-cli)';
        }
        if (!function_exists('password_hash')) {
            $missing[] = 'password hashing (package: php, core extension)';
        }

        if ($missing === []) {
            return;
        }

        $list = implode("\n  - ", $missing);
        $message = "Missing required PHP extensions:\n  - {$list}\n\n"
            . "Ubuntu/Debian example:\n"
            . "  sudo apt install php8.3-sqlite3 php8.3-xml php8.3-mbstring\n\n"
            . "Verify with: php -m";

        if (php_sapi_name() === 'cli-server' || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            header('Content-Type: text/plain; charset=utf-8', true, 500);
        } else {
            header('Content-Type: text/html; charset=utf-8', true, 500);
            $message = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Sneeze — PHP requirements</title>'
                . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;line-height:1.5}'
                . 'pre{background:#f4f4f4;padding:1rem;border-radius:8px;overflow:auto}</style></head><body>'
                . '<h1>PHP requirements not met</h1><pre>' . htmlspecialchars($message) . '</pre></body></html>';
        }

        exit($message);
    }
}
