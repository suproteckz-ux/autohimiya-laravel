<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Concerns\HasTabs;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Product;
use App\Support\ProductStatus;

class ListProducts extends ListRecords
{
    use HasTabs;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Все')->badge(Product::query()->count()),
            'needs_image' => Tab::make('Без фото')->badge(Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('primary_image')->whereDoesntHave('images')),
            'needs_description' => Tab::make('Без описания')->badge(Product::query()->where(fn (Builder $inner) => $inner->whereNull('description')->orWhere('description', ''))->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner->whereNull('description')->orWhere('description', ''))),
            'needs_seo' => Tab::make('Без SEO')->badge(Product::query()->where(fn (Builder $inner) => $inner->whereNull('meta_title')->orWhere('meta_title', '')->orWhereNull('meta_description')->orWhere('meta_description', ''))->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner->whereNull('meta_title')->orWhere('meta_title', '')->orWhereNull('meta_description')->orWhere('meta_description', ''))),
            'needs_brand' => Tab::make('Без бренда')->badge(Product::query()->whereNull('brand_id')->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('brand_id')),
            'needs_category' => Tab::make('Без категории')->badge(Product::query()->whereNull('category_id')->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('category_id')),
            'needs_review' => Tab::make('На проверке')->badge(Product::query()->where('product_status', ProductStatus::NEEDS_REVIEW)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('product_status', ProductStatus::NEEDS_REVIEW)),
            'in_stock' => Tab::make('В наличии')->badge(Product::query()->where('availability', true)->where('quantity', '>', 0)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('availability', true)->where('quantity', '>', 0)),
            'out_of_stock' => Tab::make('Нет в наличии')->badge(Product::query()->where(fn (Builder $inner) => $inner->where('availability', false)->orWhere('quantity', '<=', 0))->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner->where('availability', false)->orWhere('quantity', '<=', 0))),
            'featured' => Tab::make('Хиты')->badge(Product::query()->where('is_featured', true)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_featured', true)),
        ];
    }
}
