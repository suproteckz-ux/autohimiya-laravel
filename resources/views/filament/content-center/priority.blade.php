@php
    $priority = \App\Support\ContentScore::priority($record);
    $label = \App\Support\ContentScore::priorityLabel($record);
    $color = match ($priority) {
        'high' => ['#dc2626', '#fee2e2'],
        'medium' => ['#d97706', '#ffedd5'],
        default => ['#16a34a', '#dcfce7'],
    };
@endphp

<span style="display:inline-flex;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:800;color:{{ $color[0] }};background:{{ $color[1] }};">{{ $label }}</span>
