<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Catalog;

use Core\Support\Str;

final class SeoTags
{
    /** @return list<string> */
    public static function parse(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && array_is_list($decoded)) {
                $value = $decoded;
            } else {
                return self::split($value);
            }
        }
        if (!is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $tag) {
            if (is_string($tag)) {
                array_push($tags, ...self::split($tag));
            }
        }

        return array_values(array_unique($tags));
    }

    public static function toString(mixed $value): string
    {
        return implode(', ', self::parse($value));
    }

    public static function slug(string $tag): string
    {
        $slug = Str::slug($tag);

        return ($slug !== '' ? $slug : 'tag') . '-' . substr(hash('sha256', $tag), 0, 16);
    }

    /** @return list<string> */
    private static function split(string $value): array
    {
        $tags = preg_split('/[,;\n]+/u', $value) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn(string $tag): string => trim($tag), $tags),
            static fn(string $tag): bool => $tag !== '',
        )));
    }
}
