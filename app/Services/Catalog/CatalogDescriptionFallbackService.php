<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Support\StorefrontText;
use App\Support\Utf8Sanitizer;
use Illuminate\Support\Collection;

class CatalogDescriptionFallbackService
{
    public function shouldGenerate(Product $product): bool
    {
        if ((bool) $product->description_is_manual || (bool) $product->auto_content_locked) {
            return false;
        }

        $description = StorefrontText::plain($product->description);

        return $description === ''
            || mb_strtolower($description) === mb_strtolower('Описание готовится');
    }

    public function generate(Product $product, array|Collection|null $attributes = null): ?string
    {
        if (! $this->shouldGenerate($product)) {
            return null;
        }

        $product->loadMissing(['brand', 'category', 'attributes']);

        $name = StorefrontText::plain($product->name, 'Товар');
        $brand = StorefrontText::plain($product->brand?->name);
        $category = StorefrontText::plain($product->category?->name, 'автохимии');
        $attributeText = $this->attributeText($attributes ?: $product->attributes);

        $parts = [];
        $lead = $name;
        if ($brand !== '' && ! str_contains(mb_strtolower($lead), mb_strtolower($brand))) {
            $lead .= ' '.$brand;
        }

        $parts[] = "{$lead} — товар из категории {$category}.";

        if ($attributeText !== '') {
            $parts[] = 'Основные характеристики: '.$attributeText.'.';
        }

        $parts[] = 'Подходит для подбора по назначению и характеристикам товара.';
        $parts[] = 'Перед использованием ознакомьтесь с инструкцией производителя.';

        return Utf8Sanitizer::forDb(implode(' ', $parts), 65000);
    }

    private function attributeText(array|Collection $attributes): string
    {
        $items = collect($attributes)
            ->map(function (mixed $attribute): ?string {
                $name = StorefrontText::plain(is_array($attribute) ? ($attribute['name'] ?? null) : ($attribute->name ?? null));
                $value = StorefrontText::plain(is_array($attribute) ? ($attribute['value'] ?? null) : ($attribute->value ?? null));

                if ($name === '' || $value === '') {
                    return null;
                }

                return "{$name}: {$value}";
            })
            ->filter()
            ->take(5)
            ->values();

        return $items->implode('; ');
    }
}
