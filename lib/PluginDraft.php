<?php

declare(strict_types=1);

/**
 * Pure edits of a page block tree.
 *
 * Nothing here writes the database. saveBlocks() and the studio draft
 * persist the tree these methods return. A new block uses a negative id,
 * which PageRepository::saveTree stores as an insert.
 */
class PluginDraft
{
    /**
     * @param list<array<string, mixed>> $tree
     * @param array<string, mixed> $spec type_id or slug, plus parent_id, slot, index, data
     * @return list<array<string, mixed>>
     */
    public static function insertBlock(array $tree, array $spec): array
    {
        self::assertList($tree);
        $type = self::resolveType($spec);
        $slot = trim((string) ($spec['slot'] ?? 'content')) ?: 'content';
        $parentId = $spec['parent_id'] ?? $spec['parentId'] ?? null;
        if ($parentId === '' || $parentId === 0 || $parentId === '0' || $parentId === 'null') {
            $parentId = null;
        }
        $data = self::defaultData(is_array($type['schema'] ?? null) ? $type['schema'] : []);
        if (is_array($spec['data'] ?? null)) {
            $data = array_merge($data, $spec['data']);
        }
        $node = [
            'id' => self::nextTempId($tree),
            'block_type_id' => (int) $type['id'],
            'block_type' => $type,
            'parent_id' => $parentId,
            'slot' => $slot,
            'data' => $data,
            'children' => [],
            'cms_source_id' => null,
            'cms_entry_key' => null,
        ];
        $index = array_key_exists('index', $spec) ? (int) $spec['index'] : null;
        $copy = self::copyTree($tree);
        if ($parentId === null) {
            self::insertIntoList($copy, $node, $slot, $index);
            return $copy;
        }
        $found = false;
        $copy = self::insertUnder($copy, $parentId, $node, $slot, $index, $found);
        if (!$found) {
            throw new PluginException('Parent block not found');
        }
        return $copy;
    }

    /**
     * Replace one block's data object. Other blocks stay as they were.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public static function updateBlockData(array $tree, int|string $id, array $data): array
    {
        return self::changeData($tree, $id, static fn (array $current): array => $data);
    }

    /**
     * Merge keys into one block's data. Unmentioned keys and other blocks stay.
     *
     * @param list<array<string, mixed>> $tree
     * @param array<string, mixed> $partial
     * @return list<array<string, mixed>>
     */
    public static function patchBlockData(array $tree, int|string $id, array $partial): array
    {
        return self::changeData($tree, $id, static function (array $current) use ($partial): array {
            return array_merge($current, $partial);
        });
    }

    /**
     * Drop a block and its children.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public static function removeBlock(array $tree, int|string $id): array
    {
        self::assertList($tree);
        $found = false;
        $next = self::mapBlocks($tree, static function (array $block) use ($id, &$found): ?array {
            if ((string) ($block['id'] ?? '') === (string) $id) {
                $found = true;
                return null;
            }
            return $block;
        });
        if (!$found) {
            throw new PluginException('Block not found');
        }
        return $next;
    }

    /**
     * @param list<array<string, mixed>> $tree
     * @param callable(array<string, mixed>): array $replace
     * @return list<array<string, mixed>>
     */
    private static function changeData(array $tree, int|string $id, callable $replace): array
    {
        self::assertList($tree);
        $found = false;
        $next = self::mapBlocks($tree, static function (array $block) use ($id, $replace, &$found): array {
            if ((string) ($block['id'] ?? '') === (string) $id) {
                $current = is_array($block['data'] ?? null) ? $block['data'] : [];
                $block['data'] = $replace($current);
                $found = true;
            }
            return $block;
        });
        if (!$found) {
            throw new PluginException('Block not found');
        }
        return $next;
    }

    /**
     * @param list<array<string, mixed>> $tree
     */
    private static function assertList(array $tree): void
    {
        if ($tree !== [] && !array_is_list($tree)) {
            throw new PluginException('Block tree must be a list');
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private static function resolveType(array $spec): array
    {
        $id = (int) ($spec['type_id'] ?? $spec['typeId'] ?? 0);
        $slug = trim((string) ($spec['slug'] ?? ''));
        if ($id < 1 && $slug === '') {
            throw new PluginException('Block type is required');
        }
        foreach (BlockTypeRepository::all() as $type) {
            if ($id > 0 && (int) ($type['id'] ?? 0) === $id) {
                return $type;
            }
            if ($id < 1 && $slug !== '' && (string) ($type['slug'] ?? '') === $slug) {
                return $type;
            }
        }
        throw new PluginException('Block type not found');
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function defaultData(array $schema): array
    {
        $data = [];
        $fields = $schema['fields'] ?? [];
        if (!is_array($fields)) {
            return $data;
        }
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $data[$name] = ($field['type'] ?? '') === 'number'
                ? ($name === 'height' ? 48 : 0)
                : '';
        }
        return $data;
    }

    /**
     * @param list<array<string, mixed>> $tree
     */
    private static function nextTempId(array $tree): int
    {
        $min = 0;
        $walk = static function (array $nodes) use (&$walk, &$min): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $id = $node['id'] ?? null;
                if (is_int($id) || (is_string($id) && is_numeric($id))) {
                    $min = min($min, (int) $id);
                }
                if (isset($node['children']) && is_array($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk($tree);
        return $min < 0 ? $min - 1 : -1;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private static function copyTree(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = self::copyTree(array_values($node['children']));
            }
            if (isset($node['data']) && is_array($node['data'])) {
                $node['data'] = array_merge([], $node['data']);
            }
            $out[] = $node;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @param array<string, mixed> $node
     */
    private static function insertIntoList(array &$list, array $node, string $slot, ?int $index): void
    {
        if ($index === null) {
            $list[] = $node;
            return;
        }
        $slotIndexes = [];
        foreach ($list as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['slot'] ?? 'content') === $slot) {
                $slotIndexes[] = $i;
            }
        }
        $clamped = max(0, min($index, count($slotIndexes)));
        if ($clamped >= count($slotIndexes)) {
            if ($slotIndexes === []) {
                $list[] = $node;
                return;
            }
            $lastPos = $slotIndexes[count($slotIndexes) - 1];
            array_splice($list, $lastPos + 1, 0, [$node]);
            return;
        }
        array_splice($list, $slotIndexes[$clamped], 0, [$node]);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private static function insertUnder(
        array $nodes,
        int|string $parentId,
        array $node,
        string $slot,
        ?int $index,
        bool &$found
    ): array {
        $out = [];
        foreach ($nodes as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (!$found && (string) ($block['id'] ?? '') === (string) $parentId) {
                $children = is_array($block['children'] ?? null) ? $block['children'] : [];
                self::insertIntoList($children, $node, $slot, $index);
                $block['children'] = $children;
                $found = true;
            } elseif (isset($block['children']) && is_array($block['children'])) {
                $block['children'] = self::insertUnder($block['children'], $parentId, $node, $slot, $index, $found);
            }
            $out[] = $block;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param callable(array<string, mixed>): ?array $fn
     * @return list<array<string, mixed>>
     */
    private static function mapBlocks(array $blocks, callable $fn): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $next = $fn($block);
            if ($next === null) {
                continue;
            }
            if (isset($next['children']) && is_array($next['children'])) {
                $next['children'] = self::mapBlocks(array_values($next['children']), $fn);
            }
            $out[] = $next;
        }
        return $out;
    }
}
