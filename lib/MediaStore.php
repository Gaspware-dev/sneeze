<?php

declare(strict_types=1);

class MediaStore
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/avif' => 'avif',
        'application/pdf' => 'pdf',
        'font/woff' => 'woff',
        'font/woff2' => 'woff2',
        'application/font-woff' => 'woff',
        'application/font-woff2' => 'woff2',
        'video/mp4' => 'mp4',
        'audio/mpeg' => 'mp3',
    ];

    private const MAX_SIZE = 10 * 1024 * 1024;

    public function upload(array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Upload failed');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            Response::error('Upload failed');
        }
        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            Response::error('File too large (max 10 MB)');
        }

        $item = $this->storeFromPath($tmp, (string) ($file['name'] ?? 'upload'), $userId, true, true);
        if ($item === null) {
            Response::error('Failed to store upload', 500);
        }
        if (is_file($tmp) && realpath($tmp) !== realpath(self::draftAbs($item['storage_key']))) {
            @unlink($tmp);
        }
        return $item;
    }

    public function ingest(string $sourcePath, string $filename, int $userId, bool $strict = true): ?array
    {
        if (!is_file($sourcePath)) {
            if ($strict) {
                Response::error('File not found');
            }
            return null;
        }
        if (filesize($sourcePath) > self::MAX_SIZE) {
            if ($strict) {
                Response::error('File too large (max 10 MB)');
            }
            return null;
        }
        return $this->storeFromPath($sourcePath, $filename, $userId, false, $strict);
    }

    public function list(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM media ORDER BY created_at DESC');
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->formatMedia($row);
        }
        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function allRows(): array
    {
        return Database::pdo()->query('SELECT * FROM media ORDER BY id')->fetchAll() ?: [];
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM media WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Not found', 404);
        }

        $key = self::storageKey((string) $row['path']);
        $draft = self::draftAbs($key);
        if (is_file($draft)) {
            @unlink($draft);
        }
        $published = self::publishedAbs($key);
        if (is_file($published)) {
            @unlink($published);
        }
        $thumb = Config::dataDir() . '/thumbs/' . $id . '.jpg';
        if (is_file($thumb)) {
            @unlink($thumb);
        }

        Database::pdo()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
    }

    public function stream(int $id): void
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM media WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Not found', 404);
        }
        $path = self::draftAbs(self::storageKey((string) $row['path']));
        if (!is_file($path)) {
            $published = self::publishedAbs(self::storageKey((string) $row['path']));
            $path = is_file($published) ? $published : $path;
        }
        Response::file($path, (string) $row['mime'], (string) $row['filename']);
    }

    public static function fileUrl(int $id): string
    {
        return Config::sneezeOrigin() . Config::sneezeBasePath() . '/api/media/file/' . $id;
    }

    public static function publicPath(string $storageKey): string
    {
        return Config::uploadDir() . '/' . ltrim(self::storageKey($storageKey), '/');
    }

    public static function storageKey(string $path): string
    {
        $path = str_replace('\\', '/', ltrim($path, '/'));
        $upload = Config::uploadDir();
        if ($upload !== '' && str_starts_with($path, $upload . '/')) {
            return substr($path, strlen($upload) + 1);
        }
        return $path;
    }

    public static function draftAbs(string $storageKey): string
    {
        $key = self::storageKey($storageKey);
        return Config::draftUploadDir() . '/' . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    public static function publishedAbs(string $storageKey): string
    {
        $rel = str_replace('/', DIRECTORY_SEPARATOR, self::publicPath($storageKey));
        return rtrim(Config::siteRoot(), '/\\') . DIRECTORY_SEPARATOR . $rel;
    }

    /**
     * Preview uses authenticated studio URLs. Publish copies draft files into the
     * site asset folder and rewrites to paths relative to the HTML file.
     */
    public static function applyToDocument(string $html, bool $preview, string $slug): string
    {
        $rows = self::allRows();
        usort($rows, static fn(array $a, array $b): int => strlen((string) $b['path']) <=> strlen((string) $a['path']));

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $key = self::storageKey((string) $row['path']);
            if ($key === '') {
                continue;
            }
            $used = str_contains($html, $key)
                || str_contains($html, '/api/media/file/' . $id)
                || str_contains($html, 'api/media/file/' . $id);
            if (!$used) {
                continue;
            }
            if (!$preview) {
                self::copyToSite($row);
            }
            $target = $preview
                ? self::fileUrl($id)
                : Publisher::relativeUrlTo(Compiler::outputPath($slug), self::publicPath($key));
            $html = self::replaceRefs($html, $id, $key, $target);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function copyToSite(array $row): void
    {
        $key = self::storageKey((string) $row['path']);
        $src = self::draftAbs($key);
        if (!is_file($src)) {
            return;
        }
        $dest = self::publishedAbs($key);
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_file($dest) || filemtime($src) > filemtime($dest) || filesize($src) !== filesize($dest)) {
            copy($src, $dest);
        }
    }

    private function storeFromPath(string $sourcePath, string $filename, int $userId, bool $isUpload, bool $strict = true): ?array
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($sourcePath) ?: 'application/octet-stream';
        if (!isset(self::ALLOWED[$mime])) {
            $extGuess = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $byExt = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'pdf' => 'application/pdf',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
            ];
            if (isset($byExt[$extGuess]) && ($mime === 'application/octet-stream' || $mime === 'text/plain')) {
                $mime = $byExt[$extGuess];
            }
        }
        if (!isset(self::ALLOWED[$mime])) {
            if (!$strict) {
                return null;
            }
            Response::error('File type not allowed');
        }

        $ext = self::ALLOWED[$mime];
        $year = date('Y');
        $basename = bin2hex(random_bytes(8)) . '.' . $ext;
        $storageKey = $year . '/' . $basename;

        $dest = self::draftAbs($storageKey);
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $ok = $isUpload ? @move_uploaded_file($sourcePath, $dest) : copy($sourcePath, $dest);
        if (!$ok && $isUpload) {
            $ok = copy($sourcePath, $dest);
        }
        if (!$ok || !is_file($dest)) {
            Response::error('Failed to store upload', 500);
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO media (filename, path, mime, size, uploaded_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $filename !== '' ? $filename : $basename,
            $storageKey,
            $mime,
            (int) filesize($dest),
            $userId > 0 ? $userId : null,
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        $this->maybeThumbnail($dest, $id, $mime);

        return $this->formatMedia([
            'id' => $id,
            'filename' => $filename !== '' ? $filename : $basename,
            'path' => $storageKey,
            'mime' => $mime,
            'size' => (int) filesize($dest),
            'created_at' => date('c'),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatMedia(array $row): array
    {
        $key = self::storageKey((string) $row['path']);
        $public = self::publicPath($key);
        $id = (int) $row['id'];
        return [
            'id' => $id,
            'filename' => $row['filename'],
            'path' => $public,
            'storage_key' => $key,
            'mime' => $row['mime'],
            'size' => (int) $row['size'],
            'url' => self::fileUrl($id),
            'published' => is_file(self::publishedAbs($key)),
            'created_at' => $row['created_at'] ?? null,
            'thumb' => is_file(Config::dataDir() . '/thumbs/' . $id . '.jpg')
                ? 'api/media/thumb/' . $id
                : null,
        ];
    }

    private static function replaceRefs(string $html, int $id, string $key, string $target): string
    {
        $upload = Config::uploadDir();
        $needles = [
            Config::sneezeOrigin() . Config::sneezeBasePath() . '/api/media/file/' . $id,
            Config::sneezeBasePath() . '/api/media/file/' . $id,
            '/api/media/file/' . $id,
            'api/media/file/' . $id,
            $upload . '/' . $key,
            '/' . $upload . '/' . $key,
        ];
        $web = rtrim(Config::siteWebBase(), '/');
        if ($web !== '' && $web !== '/') {
            $needles[] = $web . '/' . $upload . '/' . $key;
        }
        usort($needles, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($html, $needle)) {
                $html = str_replace($needle, $target, $html);
            }
        }
        return $html;
    }

    private function maybeThumbnail(string $path, int $id, string $mime): void
    {
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return;
        }
        if (!function_exists('imagecreatefromstring')) {
            return;
        }

        $data = file_get_contents($path);
        if ($data === false) {
            return;
        }
        $src = @imagecreatefromstring($data);
        if (!$src) {
            return;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $max = 200;
        $scale = min($max / max($w, 1), $max / max($h, 1), 1);
        $nw = (int) ($w * $scale);
        $nh = (int) ($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $thumbDir = Config::dataDir() . '/thumbs';
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0750, true);
        }
        imagejpeg($dst, $thumbDir . '/' . $id . '.jpg', 85);
        imagedestroy($src);
        imagedestroy($dst);
    }
}
