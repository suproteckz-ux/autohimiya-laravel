@php
    $badges = [
        ['label' => 'Фото', 'ok' => filled($record->primary_image) || ($record->images_count ?? 0) > 0],
        ['label' => 'TXT', 'ok' => filled($record->description)],
        ['label' => 'SEO-T', 'ok' => filled($record->meta_title)],
        ['label' => 'SEO-D', 'ok' => filled($record->meta_description)],
        ['label' => 'BR', 'ok' => filled($record->brand_id)],
        ['label' => 'CAT', 'ok' => filled($record->category_id)],
    ];
@endphp

<div style="display:flex;align-items:center;gap:4px;white-space:nowrap;flex-wrap:wrap;max-width:170px;">
    @foreach($badges as $badge)
        <span
            title="{{ $badge['label'] }}"
            style="
                display:inline-flex;
                align-items:center;
                justify-content:center;
                height:20px;
                min-width:26px;
                padding:0 6px;
                border-radius:999px;
                font-size:10px;
                line-height:1;
                font-weight:700;
                color:{{ $badge['ok'] ? '#166534' : '#991b1b' }};
                background:{{ $badge['ok'] ? '#dcfce7' : '#fee2e2' }};
                border:1px solid {{ $badge['ok'] ? '#bbf7d0' : '#fecaca' }};
            "
        >{{ $badge['label'] }}</span>
    @endforeach
</div>
