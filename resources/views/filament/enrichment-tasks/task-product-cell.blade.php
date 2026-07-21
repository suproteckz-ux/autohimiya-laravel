@php
    use App\Support\ProductImageUrlResolver;

    $product = $record->product;
    $url = $product ? ProductImageUrlResolver::productAdminUrl($product) : null;
@endphp

<div style="display:flex;align-items:center;gap:10px;min-width:0;height:50px;max-height:50px;overflow:hidden;">
    <div style="height:40px;width:40px;min-width:40px;overflow:hidden;border-radius:8px;border:1px solid #e5e7eb;background:#fff;display:flex;align-items:center;justify-content:center;">
        @if($url)
            <img src="{{ $url }}" alt="{{ $product->display_name }}" style="height:40px;width:40px;object-fit:contain;padding:4px;">
        @else
            <div style="font-size:8px;font-weight:700;color:#94a3b8;">Нет фото</div>
        @endif
    </div>
    <div style="min-width:0;">
        <div style="max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:13px;font-weight:800;color:#0f172a;">{{ $product?->display_name ?: 'Товар не найден' }}</div>
        <div style="margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11px;color:#64748b;">
            {{ $product?->paloma_sku ?: $product?->sku ?: $product?->model ?: 'SKU не указан' }}
        </div>
    </div>
</div>
