<?php

namespace App\Services\Ozon;

use App\Models\Product;

class OzonDescriptionBuilder
{
    public function build(Product $product): string
    {
        $html = preg_replace('#<(script|style|iframe|form|button)[^>]*>.*?</\1>#is', ' ', (string) $product->description);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(['#https?://\S+#iu', '#\b[\w.+-]+@[\w.-]+\.[a-z]{2,}\b#iu', '#(?:\+?7|8)[\s()\-]*\d{3}[\s()\-]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}#u', '#\b(?:WhatsApp|Kaspi)\b[^\n]*#iu'], ' ', $text);
        $text = trim((string) preg_replace('/[ \t]+/u', ' ', preg_replace('/\R{2,}/u', "\n\n", $text)));
        $rows = [];
        if (filled($product->brand?->name)) $rows[] = 'Бренд: '.trim($product->brand->name);
        if (filled($product->sku)) $rows[] = 'Артикул: '.trim($product->sku);
        $blocked = ['цена', 'остаток', 'id', 'kaspi', 'sku', 'артикул', 'бренд'];
        foreach ($product->attributes->sortBy([['group_name', 'asc'], ['sort_order', 'asc'], ['name', 'asc']]) as $attribute) {
            $name = trim((string) $attribute->name); $value = trim((string) $attribute->value);
            if ($name === '' || $value === '' || collect($blocked)->contains(fn ($term) => str_contains(mb_strtolower($name), $term))) continue;
            $rows[] = $name.': '.$value.(filled($attribute->unit) ? ' '.trim($attribute->unit) : '');
        }
        $rows = array_values(array_unique($rows));
        return trim($text.($rows ? ($text !== '' ? "\n\n" : '')."Характеристики:\n\n".implode("\n", $rows) : ''));
    }
}
