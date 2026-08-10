<?php

namespace App\Services\Ozon;

use App\Models\OzonProduct;
use Illuminate\Validation\ValidationException;

class OzonProductPayloadBuilder
{
    public function build(OzonProduct $product): array
    {
        $images = array_values(array_filter($product->prepared_images ?? [], fn ($url): bool => is_string($url) && $url !== ''));
        $errors = [];
        if ($product->offer_id !== 'aut_737') $errors[] = 'На тестовом этапе разрешена отправка только ER5 (aut_737).';
        if (! $product->account?->is_active) $errors[] = 'Аккаунт Ozon неактивен.';
        if (! in_array($product->status->value, ['draft', 'ready', 'failed', 'queued'], true)) $errors[] = 'Текущий статус товара не допускает отправку.';
        if (! filled($product->offer_id)) $errors[] = 'Не заполнен offer_id.';
        if (! filled($product->description_category_id) || ! filled($product->type_id)) $errors[] = 'Не заполнены категория или тип Ozon.';
        if (! filled($product->prepared_name)) $errors[] = 'Не заполнено название товара.';
        if ($images === []) $errors[] = 'Необходимо хотя бы одно изображение.';
        if ((float) $product->calculated_price <= 0) $errors[] = 'Цена должна быть больше нуля.';
        if (! $product->warehouse || $product->warehouse->ozon_account_id !== $product->ozon_account_id) $errors[] = 'Склад не относится к выбранному аккаунту.';
        if ($errors !== []) throw ValidationException::withMessages(['ozon_product' => $errors]);

        $item = [
            'attributes' => [],
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

        if (filled($product->prepared_description)) $item['description'] = (string) $product->prepared_description;
        if (filled($product->tnved_code)) $item['tnved_code'] = (string) $product->tnved_code;

        return ['items' => [$item]];
    }
}
