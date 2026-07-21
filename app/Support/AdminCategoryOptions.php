<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;

class AdminCategoryOptions
{
    public static function active(?int $excludeId = null): array
    {
        $categories = Category::query()
            ->where('status', 'active')
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'sort_order']);

        return self::tree(self::withoutBranch($categories, $excludeId));
    }

    public static function all(?int $excludeId = null): array
    {
        $categories = Category::query()
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'sort_order']);

        return self::tree(self::withoutBranch($categories, $excludeId));
    }

    private static function tree(Collection $categories, ?int $parentId = null, int $depth = 0): array
    {
        $options = [];

        foreach ($categories->where('parent_id', $parentId)->sortBy([['sort_order', 'asc'], ['name', 'asc']]) as $category) {
            $prefix = $depth > 0 ? str_repeat('—', $depth).' ' : '';
            $label = TextEncoding::clean($category->name);
            $label = StorefrontText::plain($label, 'Category '.$category->id);
            $label = TextEncoding::preview($label, 90);

            $options[$category->id] = $prefix.($label !== '[empty]' ? $label : 'Category '.$category->id);

            $options += self::tree($categories, $category->id, $depth + 1);
        }

        return $options;
    }

    private static function withoutBranch(Collection $categories, ?int $excludeId): Collection
    {
        if ($excludeId === null) {
            return $categories;
        }

        $excluded = [$excludeId];
        $queue = [$excludeId];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            foreach ($categories->where('parent_id', $parentId) as $child) {
                $excluded[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $categories->reject(fn (Category $category): bool => in_array($category->id, $excluded, true));
    }
}
