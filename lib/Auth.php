<?php

declare(strict_types=1);

class Auth
{
    private const SESSION_KEY = 'sneeze_user_id';
    private const CSRF_KEY = 'sneeze_csrf';
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    public static function startSession(string $installDir): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $basePath = self::cookiePath();
        session_name('SNEEZESESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $basePath,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        if (!isset($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
    }

    private static function cookiePath(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '/' || $dir === '.') {
            return '/';
        }
        return rtrim($dir, '/') . '/';
    }

    public static function csrfToken(): string
    {
        return $_SESSION[self::CSRF_KEY] ?? '';
    }

    public static function verifyCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
        if (!is_string($token) || $token === '' || !hash_equals(self::csrfToken(), $token)) {
            Response::error('Invalid CSRF token', 403);
        }
    }

    public static function user(): ?array
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$id) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT id, username, role, created_at FROM users WHERE id = ?');
        $stmt->execute([(int) $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function requireAuth(): array
    {
        $user = self::user();
        if (!$user) {
            Response::error('Unauthorized', 401);
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireAuth();
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden', 403);
        }
        return $user;
    }

    public static function login(string $username, string $password): array
    {
        $ip = self::clientIp();
        self::checkLockout($ip);

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE');
        $stmt->execute([trim($username)]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            self::recordFailedAttempt($ip);
            Response::error('Invalid username or password', 401);
        }

        self::clearAttempts($ip);
        $_SESSION[self::SESSION_KEY] = (int) $row['id'];

        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'role' => $row['role'],
        ];
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function createUser(string $username, string $password, string $role = 'editor'): array
    {
        if (strlen($password) < 8) {
            Response::error('Password must be at least 8 characters');
        }
        if (!in_array($role, ['admin', 'editor'], true)) {
            Response::error('Invalid role');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)'
        );
        try {
            $stmt->execute([trim($username), $hash, $role]);
        } catch (PDOException $e) {
            Response::error('Username already exists', 409);
        }

        return [
            'id' => (int) Database::pdo()->lastInsertId(),
            'username' => trim($username),
            'role' => $role,
        ];
    }

    public static function audit(int $userId, string $action, ?string $targetPath = null): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_log (user_id, action, target_path, ip) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $action, $targetPath, self::clientIp()]);
    }

    private static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function checkLockout(string $ip): void
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM login_attempts WHERE ip = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
            Response::error('Too many login attempts. Try again later.', 429);
        }
    }

    private static function recordFailedAttempt(string $ip): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM login_attempts WHERE ip = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row) {
            $attempts = (int) $row['attempts'] + 1;
            $locked = null;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $locked = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
                $attempts = 0;
            }
            $upd = $pdo->prepare('UPDATE login_attempts SET attempts = ?, locked_until = ? WHERE ip = ?');
            $upd->execute([$attempts, $locked, $ip]);
        } else {
            $ins = $pdo->prepare('INSERT INTO login_attempts (ip, attempts) VALUES (?, 1)');
            $ins->execute([$ip]);
        }
    }

    private static function clearAttempts(string $ip): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM login_attempts WHERE ip = ?');
        $stmt->execute([$ip]);
    }

    public static function userCount(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
