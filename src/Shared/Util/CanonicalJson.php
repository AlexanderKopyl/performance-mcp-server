<?php

declare(strict_types=1);

namespace App\Shared\Util;

final class CanonicalJson
{
    /**
     * @param mixed $value
     */
    public static function encode(mixed $value): string
    {
        $normalized = self::normalize($value);

        return (string) json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
