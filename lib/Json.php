<?php

declare(strict_types=1);

class Json
{
    public static function decode(mixed $value, mixed $default = []): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) || is_scalar($decoded) ? $decoded : $default;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public static function object(mixed $value): array
    {
        $decoded = self::decode($value, []);
        return is_array($decoded) ? $decoded : [];
    }
}
