@php
    use App\Support\ProductImageUrlResolver;

    $url = ProductImageUrlResolver::productAdminUrl($record);
@endphp

<div style="width:48px;height:48px;overflow:hidden;border-radius:8px;border:1px solid #e5e7eb;background:#fff;display:flex;align-items:center;justify-content:center;">
    @if($url)
        <img src="{{ $url }}" alt="{{ $record->display_name }}" style="width:48px;height:48px;max-width:48px;max-height:48px;object-fit:contain;display:block;padding:4px;">
    @else
        <div style="font-size:10px;color:#9ca3af;font-weight:700;">Нет</div>
    @endif
</div>
