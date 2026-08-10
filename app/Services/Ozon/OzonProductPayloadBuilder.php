<?php

namespace App\Services\Ozon;

use App\Models\OzonProduct;
use App\Models\OzonTaxonomyAttribute;
use Illuminate\Validation\ValidationException;

class OzonProductPayloadBuilder
{
    public function build(OzonProduct $product): array
    {
        $images = array_values(array_filter($product->prepared_images ?? [], fn ($url): bool => is_string($url) && $url !== ''));
        $errors = [];
        if (! $product->account?->is_active) $errors[] = 'Аккаунт Ozon неактивен.';
        if (! in_array($product->status->value, ['draft', 'ready', 'failed', 'queued'], true)) $errors[] = 'Текущий статус товара не допускает отправку.';
        if (! filled($product->offer_id)) $errors[] = 'Не заполнен offer_id.';
        if (! filled($product->description_category_id) || ! filled($product->type_id)) $errors[] = 'Не заполнены категория или тип Ozon.';
        if (! filled($product->prepared_name)) $errors[] = 'Не заполнено название товара.';
        if ($images === []) $errors[] = 'Необходимо хотя бы одно изображение.';
        if ((float) $product->calculated_price <= 0) $errors[] = 'Цена должна быть больше нуля.';
        if (! $product->warehouse || $product->warehouse->ozon_account_id !== $product->ozon_account_id) $errors[] = 'Склад не относится к выбранному аккаунту.';
        if ($errors !== []) throw ValidationException::withMessages(['ozon_product' => $errors]);

        $attributes = array_values(array_filter(
            $product->prepared_attributes ?? [],
            fn ($attribute): bool => is_array($attribute)
                && is_numeric($attribute['id'] ?? null)
                && is_array($attribute['values'] ?? null),
        ));

        if (filled($product->prepared_description)) {
            $annotation = $this->annotationAttribute($product);

            if (! $annotation) {
                throw ValidationException::withMessages([
                    'ozon_product' => 'Для выбранной категории Ozon не удалось определить характеристику «Аннотация».',
                ]);
            }

            $attributes[] = [
                'id' => (int) $annotation->attribute_id,
                'complex_id' => 0,
                'values' => [[
                    'dictionary_value_id' => 0,
                    'value' => (string) $product->prepared_description,
                ]],
            ];
        }

        $item = [
            'attributes' => $attributes,
            'complex_attributes' => [],
            'currency_code' => 'KZT',
            'description_category_id' => (int) $product->description_category_id,
            'type_id' => (int) $product->type_id,
            'name' => (string) $product->prepared_name,
            'offer_id' => (string) $product->offer_id,
            'price' => number_format((float) $product->calculated_price, 2, '.', ''),
            'vat' => '0',
            'images' => $images,
            'depth' => (int) $product->depth_mm,
            'height' => (int) $product->height_mm,
            'width' => (int) $product->width_mm,
            'dimension_unit' => 'mm',
            'weight' => (int) $product->weight_g,
            'weight_unit' => 'g',
        ];

        if (filled($product->tnved_code)) $item['tnved_code'] = (string) $product->tnved_code;

        return ['items' => [$item]];
    }

    private function annotationAttribute(OzonProduct $product): ?OzonTaxonomyAttribute
    {
        return OzonTaxonomyAttribute::query()
            ->whereHas('node', fn ($query) => $query
                ->where('ozon_account_id', $product->ozon_account_id)
                ->where('description_category_id', (string) $product->description_category_id)
                ->where('type_id', (string) $product->type_id))
            ->whereIn('name', ['Аннотация', 'аннотация', 'Описание товара', 'описание товара'])
            ->orderByRaw("CASE WHEN name IN ('Аннотация', 'аннотация') THEN 0 ELSE 1 END")
            ->first();
    }
}
