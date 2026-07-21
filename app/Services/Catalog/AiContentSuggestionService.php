<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Str;

class AiContentSuggestionService
{
    public function suggestDescription(Product $product): array
    {
        $name = $product->display_name;
        $brand = $product->brand?->display_name ?: 'проверенного бренда';
        $category = $product->category?->display_name ?: 'автохимии и автотоваров';

        $description = trim(sprintf(
            '%s — товар из категории «%s» для ухода, обслуживания или подготовки автомобиля. Бренд: %s. Черновик подготовлен на основе существующих данных каталога и требует проверки менеджером перед публикацией. Текст не добавляет неподтвержденные технические характеристики, обещания результата или совместимость с конкретными автомобилями. Перед размещением рекомендуется уточнить назначение, способ применения, объем, ограничения и рекомендации производителя. Такой подход помогает сохранить карточку понятной для клиента и безопасной для SEO: описание объясняет, что это за товар, где он применяется и почему его стоит проверить в ассортименте Autohimiya.kz.',
            $name,
            $category,
            $brand
        ));

        return [
            'source' => 'ai_stub',
            'confidence' => 55,
            'reason' => 'Safe draft generated from product name, brand and category without external API.',
            'description' => $description,
            'short_description' => Str::limit(strip_tags($description), 180, ''),
        ];
    }

    public function suggestSeo(Product $product): array
    {
        $name = Str::limit($product->display_name, 46, '');
        $brand = $product->brand?->display_name;
        $category = $product->category?->display_name ?: 'автохимия';
        $title = trim($name.($brand ? ' '.$brand : '').' купить в Алматы');

        return [
            'source' => 'ai_stub',
            'confidence' => 55,
            'reason' => 'Safe SEO draft generated without external API.',
            'seo_title' => Str::limit($title, 70, ''),
            'meta_description' => Str::limit("{$product->display_name}: {$category} в Autohimiya.kz. Актуальные цены и остатки, консультация по подбору и доставка по Алматы и Казахстану.", 250, ''),
            'image_alt' => Str::limit($product->display_name.($brand ? ' '.$brand : ''), 120, ''),
        ];
    }

    public function suggestAll(Product $product): array
    {
        return [
            'description' => $this->suggestDescription($product),
            'seo' => $this->suggestSeo($product),
        ];
    }
}
