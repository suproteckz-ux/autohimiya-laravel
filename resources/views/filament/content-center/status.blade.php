@php
    $ok = (bool) $ok;
    $label = $ok ? 'Есть' : $missing;
@endphp

<div style="display:grid;place-items:center;gap:2px;">
    <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:999px;font-size:13px;font-weight:900;color:{{ $ok ? '#15803d' : '#dc2626' }};background:{{ $ok ? '#dcfce7' : '#fee2e2' }};">{{ $ok ? '✓' : '×' }}</span>
    <span style="font-size:10px;color:{{ $ok ? '#15803d' : '#dc2626' }};white-space:nowrap;">{{ $label }}</span>
</div>
