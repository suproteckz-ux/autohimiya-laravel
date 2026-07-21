@php
    use App\Support\ProductImageUrlResolver;

    $url = ProductImageUrlResolver::productAdminUrl($record);
    $tasks = $record->enrichmentTasks()->latest()->limit(8)->get();
@endphp

<div style="display:grid;gap:16px;">
    <div style="display:grid;grid-template-columns:140px 1fr;gap:16px;align-items:start;">
        <div style="height:140px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;">
            @if($url)
                <img src="{{ $url }}" alt="{{ $record->display_name }}" style="max-width:120px;max-height:120px;object-fit:contain;">
            @else
                <span style="color:#9ca3af;font-weight:700;">Нет фото</span>
            @endif
        </div>
        <div>
            <h2 style="margin:0 0 8px;font-size:20px;font-weight:800;">{{ $record->display_name }}</h2>
            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;font-size:13px;color:#374151;">
                <div><strong>Цена:</strong> {{ number_format((float) $record->price, 0, '.', ' ') }} ₸</div>
                <div><strong>Остаток:</strong> {{ (int) $record->quantity }}</div>
                <div><strong>Наличие:</strong> {{ $record->availability ? 'В наличии' : 'Нет в наличии' }}</div>
                <div><strong>Категория:</strong> {{ $record->category?->display_name ?: 'Нет категории' }}</div>
                <div><strong>Бренд:</strong> {{ $record->brand?->display_name ?: 'Нет бренда' }}</div>
                <div><strong>SKU:</strong> {{ $record->paloma_sku ?: $record->sku ?: $record->model ?: 'не указан' }}</div>
            </div>
        </div>
    </div>

    <div>
        <h3 style="margin:0 0 6px;font-weight:800;">Краткое описание</h3>
        <p style="margin:0;color:#4b5563;">{{ \App\Support\StorefrontText::plain($record->short_description ?: $record->description, 'Описание еще не заполнено.') }}</p>
    </div>

    <div>
        <h3 style="margin:0 0 8px;font-weight:800;">SEO / Контент</h3>
        @include('filament.products.content-badges', ['record' => $record])
    </div>

    <div>
        <h3 style="margin:0 0 8px;font-weight:800;">Задачи наполнения</h3>
        @if($tasks->isEmpty())
            <div style="color:#6b7280;">Задач пока нет.</div>
        @else
            <div style="display:grid;gap:6px;">
                @foreach($tasks as $task)
                    <div style="display:flex;gap:8px;align-items:center;font-size:13px;">
                        <strong>{{ $task->task_type }}</strong>
                        <span>{{ $task->status }}</span>
                        <span style="color:#6b7280;">{{ $task->source }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
