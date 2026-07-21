<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

class CatalogContentPriority extends Widget
{
    protected string $view = 'filament.widgets.catalog-content-priority';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected function getViewData(): array
    {
        return [
            'items' => [
                [
                    'label' => 'В наличии без фото',
                    'count' => Product::query()->where('availability', true)->where('quantity', '>', 0)->whereNull('primary_image')->whereDoesntHave('images')->count(),
                    'url' => ProductResource::getUrl('index', ['activeTab' => 'needs_image']),
                ],
                [
                    'label' => 'В наличии без описания',
                    'count' => Product::query()->where('availability', true)->where('quantity', '>', 0)->where(fn (Builder $query) => $query->whereNull('description')->orWhere('description', ''))->count(),
                    'url' => ProductResource::getUrl('index', ['activeTab' => 'needs_description']),
                ],
                [
                    'label' => 'В наличии без SEO',
                    'count' => Product::query()->where('availability', true)->where('quantity', '>', 0)->where(fn (Builder $query) => $query->whereNull('meta_title')->orWhere('meta_title', '')->orWhereNull('meta_description')->orWhere('meta_description', ''))->count(),
                    'url' => ProductResource::getUrl('index', ['activeTab' => 'needs_seo']),
                ],
                [
                    'label' => 'В наличии без бренда',
                    'count' => Product::query()->where('availability', true)->where('quantity', '>', 0)->whereNull('brand_id')->count(),
                    'url' => ProductResource::getUrl('index', ['activeTab' => 'needs_brand']),
                ],
            ],
        ];
    }
}
