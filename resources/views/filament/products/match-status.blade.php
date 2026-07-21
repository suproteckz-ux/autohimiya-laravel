@php
    $status = $record->sync_status ?: 'manual';
    $method = $record->match_method ?: 'manual';
    $color = match ($status) {
        'matched' => ['#166534', '#dcfce7', '#bbf7d0'],
        'conflict', 'duplicate_sku', 'sync_error' => ['#991b1b', '#fee2e2', '#fecaca'],
        default => ['#92400e', '#fef3c7', '#fde68a'],
    };
@endphp

<div style="display:grid;gap:3px;min-width:90px;">
    <span style="display:inline-flex;width:max-content;align-items:center;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:700;color:{{ $color[0] }};background:{{ $color[1] }};border:1px solid {{ $color[2] }};">
        {{ $status }}
    </span>
    <span style="font-size:10px;color:#6b7280;white-space:nowrap;">{{ $method }}</span>
</div>
