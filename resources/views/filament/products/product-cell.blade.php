@php
    use App\Support\ProductImageUrlResolver;

    $url = ProductImageUrlResolver::productAdminUrl($record);
@endphp

<div style="display:flex;align-items:center;gap:10px;min-width:0;height:54px;max-height:54px;overflow:hidden;">
    <div style="width:48px;height:48px;min-width:48px;max-width:48px;overflow:hidden;border-radius:8px;border:1px solid #e5e7eb;background:#fff;display:flex;align-items:center;justify-content:center;">
        @if($url)
            <img src="{{ $url }}" alt="{{ $record->display_name }}" style="width:48px;height:48px;max-width:48px;max-height:48px;object-fit:contain;display:block;padding:4px;">
        @else
            <div style="width:48px;height:48px;display:flex;align-items:center;justify-content:center;background:#f9fafb;color:#9ca3af;font-size:9px;font-weight:700;text-align:center;line-height:1.1;">
                Нет фото
            </div>
        @endif
    </div>
    <div style="min-width:0;overflow:hidden;">
        <div title="{{ $record->display_name }}" style="max-width:300px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:13px;font-weight:700;line-height:17px;color:#111827;">{{ $record->display_name }}</div>
        <div style="margin-top:2px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;line-height:14px;color:#6b7280;">
            SKU: {{ $record->paloma_sku ?: $record->sku ?: $record->model ?: 'по запросу' }}
        </div>
    </div>
</div>
