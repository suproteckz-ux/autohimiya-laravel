<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;

class CategoryTree
{
    private static ?array $snapshot = null;

    public static function snapshot(bool $fresh = false): array
    {
        if (($fresh === false) && self::$snapshot !== null) {
            return self::$snapshot;
        }

        $categories = Category::query()
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'sort_order']);

        return self::$snapshot = self::build($categories);
    }

    public static function orderedIds(bool $fresh = false): array
    {
        return self::snapshot($fresh)['ordered_ids'];
    }

    public static function visibleIds(array $expandedIds, bool $fresh = false): array
    {
        $snapshot = self::snapshot($fresh);
        $expanded = array_fill_keys(array_map('intval', $expandedIds), true);
        $visible = [];

        $walk = function (?int $parentId) use (&$walk, &$visible, $snapshot, $expanded): void {
            foreach ($snapshot['children_by_parent'][$parentId] ?? [] as $id) {
                $visible[] = $id;

                if (isset($expanded[$id])) {
                    $walk($id);
                }
            }
        };

        $walk(null);

        foreach ($snapshot['unreachable_ids'] as $id) {
            if (! in_array($id, $visible, true)) {
                $visible[] = $id;
            }
        }

        return $visible;
    }

    public static function depthFor(int $id): int
    {
        return (int) (self::snapshot()['depths'][$id] ?? 0);
    }

    public static function hasChildren(int $id): bool
    {
        return ! empty(self::snapshot()['children_by_parent'][$id] ?? []);
    }

    public static function ancestorsFor(int $id): array
    {
        return self::snapshot()['ancestors'][$id] ?? [];
    }

    public static function build(Collection $categories): array
    {
        $ids = $categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $idSet = array_fill_keys($ids, true);
        $childrenByParent = [];
        $invalidParentIds = [];

        foreach ($categories as $category) {
            $parentId = $category->parent_id !== null ? (int) $category->parent_id : null;

            if ($parentId !== null && ! isset($idSet[$parentId])) {
                $invalidParentIds[] = (int) $category->id;
                $parentId = null;
            }

            $childrenByParent[$parentId][] = (int) $category->id;
        }

        $sorter = function (array &$items) use ($categories): void {
            usort($items, function (int $left, int $right) use ($categories): int {
                $a = $categories->firstWhere('id', $left);
                $b = $categories->firstWhere('id', $right);

                return [$a?->sort_order ?? 0, mb_strtolower((string) $a?->name), $left]
                    <=> [$b?->sort_order ?? 0, mb_strtolower((string) $b?->name), $right];
            });
        };

        foreach ($childrenByParent as &$items) {
            $sorter($items);
        }
        unset($items);

        $orderedIds = [];
        $depths = [];
        $ancestors = [];
        $reachable = [];
        $maxDepth = 0;

        $walk = function (?int $parentId, int $depth, array $parentAncestors = []) use (&$walk, &$orderedIds, &$depths, &$ancestors, &$reachable, &$maxDepth, $childrenByParent): void {
            foreach ($childrenByParent[$parentId] ?? [] as $id) {
                if (isset($reachable[$id])) {
                    continue;
                }

                $reachable[$id] = true;
                $orderedIds[] = $id;
                $depths[$id] = $depth;
                $ancestors[$id] = $parentAncestors;
                $maxDepth = max($maxDepth, $depth + 1);

                $walk($id, $depth + 1, [...$parentAncestors, $id]);
            }
        };

        $walk(null, 0);

        $unreachableIds = array_values(array_filter($ids, fn (int $id): bool => ! isset($reachable[$id])));

        foreach ($unreachableIds as $id) {
            $orderedIds[] = $id;
            $depths[$id] = 0;
            $ancestors[$id] = [];
        }

        return [
            'total' => $categories->count(),
            'root_count' => count($childrenByParent[null] ?? []),
            'max_depth' => $maxDepth,
            'ordered_ids' => $orderedIds,
            'depths' => $depths,
            'ancestors' => $ancestors,
            'children_by_parent' => $childrenByParent,
            'invalid_parent_ids' => array_values(array_unique($invalidParentIds)),
            'unreachable_ids' => $unreachableIds,
        ];
    }
}
