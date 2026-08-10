<div class="max-h-[70vh] space-y-3 overflow-auto text-sm">
    <div><strong>Название:</strong> {{ $record->prepared_name }}</div>
    <div><strong>SKU:</strong> {{ $record->offer_id }}</div>
    <div><strong>Категория Ozon:</strong> {{ $record->description_category_name }} ({{ $record->description_category_id }})</div>
    <div><strong>Тип Ozon:</strong> {{ $record->type_name }} ({{ $record->type_id }})</div>
    <div><strong>Склад:</strong> {{ $record->warehouse?->name ?? '—' }}</div>
    <div><strong>Цена / остаток:</strong> {{ $record->calculated_price }} / {{ $record->calculated_stock }}</div>
    <div><strong>Габариты:</strong> {{ $record->width_mm ?? '—' }}×{{ $record->height_mm ?? '—' }}×{{ $record->depth_mm ?? '—' }} мм; вес {{ $record->weight_g ?? '—' }} г</div>
    <div><strong>ТН ВЭД:</strong> {{ $record->tnved_code ?? '—' }}</div>
    <div><strong>Ошибки:</strong> {{ $record->last_error ?? '—' }}</div>
    <div><strong>Описание:</strong><pre class="mt-1 whitespace-pre-wrap">{{ $record->prepared_description }}</pre></div>
    <div><strong>Изображения:</strong><pre class="mt-1 whitespace-pre-wrap">{{ json_encode($record->prepared_images, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></div>
    <div><strong>Характеристики:</strong><pre class="mt-1 whitespace-pre-wrap">{{ json_encode($record->prepared_attributes, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></div>
    <div><strong>Полный snapshot:</strong><pre class="mt-1 whitespace-pre-wrap">{{ json_encode($record->prepared_payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></div>
</div>
