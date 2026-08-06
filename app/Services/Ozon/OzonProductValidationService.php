<?php

namespace App\Services\Ozon;

use App\Models\OzonProduct;
use App\Models\Product;
use App\Services\Ozon\DTO\OzonImageResult;
use App\Services\Ozon\DTO\OzonValidationResult;

class OzonProductValidationService
{
    public function validate(Product $product, array $settings, OzonImageResult $images, string $description, int $stock): OzonValidationResult
    {
        $errors = $images->errors; $warnings = $images->warnings;
        if (! filled($product->sku)) $errors[] = 'Отсутствует SKU.';
        elseif (Product::query()->where('sku', $product->sku)->whereKeyNot($product->id)->exists()) $errors[] = 'SKU дублируется в локальном каталоге.';
        if (! filled($product->name)) $errors[] = 'Отсутствует название.';
        if (! is_numeric($product->price) || (float) $product->price <= 0) $errors[] = 'Цена отсутствует или не больше нуля.';
        foreach (['ozon_account_id' => 'аккаунт', 'description_category_id' => 'category ID', 'description_category_name' => 'категория Ozon', 'type_id' => 'type ID', 'type_name' => 'тип Ozon'] as $key => $label) if (! filled($settings[$key] ?? null)) $errors[] = 'Не указан '.$label.'.';
        if (! filled($settings['ozon_warehouse_id'] ?? null) && ! filled($settings['manual_warehouse_id'] ?? null)) $errors[] = 'Не указан склад.';
        if (filled($settings['ozon_account_id'] ?? null) && OzonProduct::query()->where('ozon_account_id', $settings['ozon_account_id'])->where('product_id', $product->id)->exists()) $errors[] = 'Товар уже подготовлен для этого аккаунта.';
        if ($description === '') $warnings[] = 'Пустое описание.';
        if ($product->attributes->isEmpty()) $warnings[] = 'Нет характеристик.';
        if (count($images->urls) === 1) $warnings[] = 'Только одно изображение.';
        if ($stock === 0) $warnings[] = 'Остаток равен нулю.';
        if (! filled($settings['tnved_code'] ?? null)) $warnings[] = 'Нет ТН ВЭД.';
        if ($settings['manual_warehouse'] ?? false) $warnings[] = 'Warehouse ID введён вручную и не подтверждён API.';
        if ($settings['manual_taxonomy_unverified'] ?? true) $warnings[] = 'Category/type ID введены вручную и не подтверждены API.';
        return new OzonValidationResult(array_values(array_unique($errors)), array_values(array_unique($warnings)));
    }
}
