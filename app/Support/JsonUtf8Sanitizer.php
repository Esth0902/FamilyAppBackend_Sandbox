<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

class JsonUtf8Sanitizer
{
    public static function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::sanitizeString($value);
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = self::sanitize($item);
            }

            return $sanitized;
        }

        if ($value instanceof Collection) {
            return $value
                ->map(static fn(mixed $item): mixed => self::sanitize($item))
                ->all();
        }

        if ($value instanceof Arrayable) {
            return self::sanitize($value->toArray());
        }

        if ($value instanceof JsonSerializable) {
            return self::sanitize($value->jsonSerialize());
        }

        return $value;
    }

    private static function sanitizeString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (!function_exists('mb_convert_encoding')) {
            return iconv('Windows-1252', 'UTF-8//IGNORE', $value) ?: $value;
        }

        $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true) ?: 'Windows-1252';

        return mb_convert_encoding($value, 'UTF-8', $encoding);
    }
}
