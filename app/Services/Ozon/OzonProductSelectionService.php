<?php

namespace App\Services\Ozon;

use App\Models\Product;
use App\Services\CategoryTreeService;
use Illuminate\Database\Eloquent\Builder;

class OzonProductSelectionService
{
    public function __construct(private readonly CategoryTreeService $categories) {}

    public function query(array $filters): Builder
    {
        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId <= 0) return Product::query()->whereRaw('1 = 0');
        $ids = ($filters['include_descendants'] ?? false) ? $this->categories->getCategoryAndDescendantIds($categoryId) : [$categoryId];
        $query = Product::query()->with(['brand:id,name', 'category:id,name', 'primaryImage:id,product_id,path,card_thumb_path,original_path'])->withCount(['images', 'attributes']);
        if ($accountId = ($filters['ozon_account_id'] ?? null)) {
            $query->with(['ozonProducts' => fn ($ozon) => $ozon->where('ozon_account_id', $accountId)->select(['id', 'product_id', 'ozon_account_id', 'status'])]);
        }
        $query->where(function (Builder $q) use ($ids, $filters): void {
            $q->whereIn('category_id', $ids);
            if ($filters['include_additional_categories'] ?? false) $q->orWhereHas('categories', fn (Builder $pivot) => $pivot->whereIn('categories.id', $ids));
        });
        if ($brand = ($filters['brand_id'] ?? null)) $query->where('brand_id', $brand);
        if ($filters['active_only'] ?? false) $query->where('product_status', 'active');
        if ($filters['in_stock_only'] ?? false) $query->where('quantity', '>', 0);
        if ($filters['priced_only'] ?? false) $query->where('price', '>', 0);
        if ($filters['has_image'] ?? false) $query->where(fn (Builder $q) => $q->whereNotNull('primary_image')->orWhereHas('images'));
        if ($filters['has_description'] ?? false) $query->whereNotNull('description')->where('description', '<>', '');
        if ($filters['has_attributes'] ?? false) $query->whereHas('attributes');
        if ($sku = trim((string) ($filters['sku'] ?? ''))) $query->where('sku', 'like', '%'.$sku.'%');
        if ($name = trim((string) ($filters['name'] ?? ''))) $query->where('name', 'like', '%'.$name.'%');
        if (($filters['not_added'] ?? false) && ($account = $filters['ozon_account_id'] ?? null)) $query->whereDoesntHave('ozonProducts', fn (Builder $q) => $q->where('ozon_account_id', $account));
        return $query->distinct('products.id')->orderBy('products.id');
    }
}
