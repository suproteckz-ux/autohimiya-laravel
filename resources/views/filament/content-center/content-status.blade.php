@php
    $tasks = $record->relationLoaded('enrichmentTasks') ? $record->enrichmentTasks : collect();
    $hasTask = function (array $types) use ($tasks): bool {
        return $tasks->whereIn('task_type', $types)->whereIn('status', ['draft', 'approved', 'failed'])->isNotEmpty();
    };
    $statuses = [
        ['label' => 'Фото', 'ok' => \App\Support\ContentScore::hasPhoto($record), 'draft' => $hasTask(['image'])],
        ['label' => 'Описание', 'ok' => \App\Support\ContentScore::hasDescription($record), 'draft' => $hasTask(['description'])],
        ['label' => 'SEO', 'ok' => \App\Support\ContentScore::hasSeo($record), 'draft' => $hasTask(['seo', 'seo_title', 'seo_description'])],
        ['label' => 'Бренд', 'ok' => \App\Support\ContentScore::hasBrand($record), 'draft' => $hasTask(['brand'])],
        ['label' => 'Категория', 'ok' => \App\Support\ContentScore::hasCategory($record), 'draft' => $hasTask(['category'])],
    ];
@endphp

<div style="display:flex;gap:4px;flex-wrap:wrap;max-width:230px;">
    @foreach($statuses as $status)
        @php
            $color = $status['ok'] ? ['#15803d', '#dcfce7', '#bbf7d0', '✓'] : ($status['draft'] ? ['#c2410c', '#ffedd5', '#fed7aa', '!'] : ['#dc2626', '#fee2e2', '#fecaca', '×']);
        @endphp
        <span title="{{ $status['label'] }}" style="display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 7px;font-size:11px;font-weight:800;line-height:1;color:{{ $color[0] }};background:{{ $color[1] }};border:1px solid {{ $color[2] }};">
            {{ $status['label'] }} {{ $color[3] }}
        </span>
    @endforeach
</div>
