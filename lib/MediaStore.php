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
        'application/pdf' => 'pdf',
    ];

    private const MAX_SIZE = 10 * 1024 * 1024;

    public function upload(array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Upload failed');
        }
        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            Response::error('File too large (max 10 MB)');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
        if (!isset(self::ALLOWED[$mime])) {
            Response::error('File type not allowed');
        }

        $ext = self::ALLOWED[$mime];
        $uploadDir = Config::uploadDir();
        $year = date('Y');
        $relativeDir = $uploadDir . '/' . $year;
        $siteRoot = Config::siteRoot();
        $absDir = $siteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        if (!is_dir($absDir)) {
            mkdir($absDir, 0755, true);
        }

        $basename = bin2hex(random_bytes(8)) . '.' . $ext;
        $relativePath = $relativeDir . '/' . $basename;
        $dest = $absDir . DIRECTORY_SEPARATOR . $basename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::error('Failed to store upload', 500);
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO media (filename, path, mime, size, uploaded_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $file['name'] ?? $basename,
            str_replace('\\', '/', $relativePath),
            $mime,
            (int) ($file['size'] ?? 0),
            $userId,
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        $this->maybeThumbnail($dest, $id, $mime);

        return $this->formatMedia([
            'id' => $id,
            'filename' => $file['name'] ?? $basename,
            'path' => str_replace('\\', '/', $relativePath),
            'mime' => $mime,
            'size' => (int) ($file['size'] ?? 0),
            'created_at' => date('c'),
        ]);
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

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM media WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Not found', 404);
        }

        $full = Config::siteRoot() . '/' . $row['path'];
        if (is_file($full)) {
            @unlink($full);
        }
        $thumb = Config::dataDir() . '/thumbs/' . $id . '.jpg';
        if (is_file($thumb)) {
            @unlink($thumb);
        }

        $del = Database::pdo()->prepare('DELETE FROM media WHERE id = ?');
        $del->execute([$id]);
    }

    private function formatMedia(array $row): array
    {
        $webBase = rtrim(Config::siteWebBase(), '/');
        $url = ($webBase === '' ? '' : $webBase) . '/' . ltrim($row['path'], '/');
        return [
            'id' => (int) $row['id'],
            'filename' => $row['filename'],
            'path' => $row['path'],
            'mime' => $row['mime'],
            'size' => (int) $row['size'],
            'url' => $url,
            'created_at' => $row['created_at'] ?? null,
            'thumb' => is_file(Config::dataDir() . '/thumbs/' . $row['id'] . '.jpg')
                ? 'api/media/thumb/' . $row['id']
                : null,
        ];
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
