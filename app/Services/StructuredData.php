<?php

declare(strict_types=1);

namespace App\Services;

final class StructuredData
{
    public const int MAX_LENGTH = 20000;

    /**
     * @return list<array<string, mixed>>|null
     */
    public static function nodes(string $json): ?array
    {
        $json = mb_trim($json);

        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $items = match (true) {
            array_is_list($decoded) => $decoded,
            is_array($decoded['@graph'] ?? null) => $decoded['@graph'],
            default => [$decoded],
        };

        $nodes = [];

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item) || ! self::hasType($item)) {
                return null;
            }

            unset($item['@context']);
            $nodes[] = $item;
        }

        return $nodes === [] ? null : $nodes;
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function hasType(array $node): bool
    {
        $type = $node['@type'] ?? null;

        return (is_string($type) && $type !== '')
            || (is_array($type) && $type !== [] && array_all($type, fn (mixed $part): bool => is_string($part) && $part !== ''));
    }
}
