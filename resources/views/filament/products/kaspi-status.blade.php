@php
    $hasSku = filled($record->sku);
    $hasUrl = filled($record->kaspi_product_url);
@endphp

<div style="display:flex;align-items:center;gap:5px;white-space:nowrap;">
    <span
        title="{{ $hasUrl ? 'Kaspi URL найден' : ($hasSku ? 'SKU готов для Kaspi' : 'SKU не указан') }}"
        style="
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:24px;
            height:24px;
            border-radius:999px;
            font-size:12px;
            font-weight:900;
            color:{{ $hasUrl ? '#047857' : ($hasSku ? '#1d4ed8' : '#991b1b') }};
            background:{{ $hasUrl ? '#d1fae5' : ($hasSku ? '#dbeafe' : '#fee2e2') }};
            border:1px solid {{ $hasUrl ? '#a7f3d0' : ($hasSku ? '#bfdbfe' : '#fecaca') }};
        "
    >K</span>
    @if($hasUrl)
        <span style="font-size:11px;font-weight:800;color:#047857;">URL</span>
    @elseif($hasSku)
        <span style="font-size:11px;font-weight:800;color:#1d4ed8;">SKU</span>
    @else
        <span style="font-size:11px;font-weight:800;color:#991b1b;">нет</span>
    @endif
</div>
