@php
    use App\Support\ProductImageUrlResolver;

    $url = ProductImageUrlResolver::productAdminUrl($record);
@endphp

<div style="display:flex;align-items:center;gap:10px;min-width:0;height:58px;max-height:58px;overflow:hidden;">
    <div style="width:48px;height:48px;min-width:48px;border:1px solid #e5e7eb;border-radius:9px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;">
        @if($url)
            <img src="{{ $url }}" alt="{{ $record->display_name }}" style="width:48px;height:48px;object-fit:contain;padding:4px;">
        @else
            <span style="font-size:9px;color:#94a3b8;font-weight:700;">Нет фото</span>
        @endif
    </div>
    <div style="min-width:0;">
        <div title="{{ $record->display_name }}" style="max-width:240px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:13px;font-weight:800;line-height:16px;color:#0f172a;">{{ $record->display_name }}</div>
        <div style="margin-top:2px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:11px;color:#64748b;">
            <span>SKU: {{ $record->paloma_sku ?: $record->sku ?: $record->model ?: 'не указан' }}</span>
            @if($record->product_status === 'needs_review')
                <span style="border-radius:999px;padding:2px 6px;background:#fff7ed;color:#c2410c;font-weight:800;">на проверке</span>
            @endif
        </div>
    </div>
</div>
