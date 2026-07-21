@php
    $task = $record->kaspiEnrichmentTasks->sortByDesc('updated_at')->first();
    $url = $record->kaspi_product_url ?: $task?->kaspi_product_url;
    $label = blank($url) ? 'Нет' : (filled($record->kaspi_product_url) ? 'Задан вручную' : 'Найден автоматически');
    $color = match ($label) {
        'Найден автоматически' => '#16a34a',
        'Задан вручную' => '#2563eb',
        default => '#6b7280',
    };
@endphp

<div style="display:flex;align-items:center;gap:8px;min-width:0;">
    <span style="display:inline-flex;align-items:center;border-radius:999px;background:#f3f4f6;color:{{ $color }};font-size:11px;font-weight:700;padding:3px 8px;white-space:nowrap;">
        {{ $label }}
    </span>

    @if($url)
        <a href="{{ $url }}" target="_blank" rel="noopener" style="font-size:12px;color:#2563eb;text-decoration:none;font-weight:600;">
            Открыть
        </a>
    @endif
</div>
