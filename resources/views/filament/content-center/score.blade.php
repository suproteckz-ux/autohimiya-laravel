@php
    $score = \App\Support\ContentScore::score($record);
    $color = $score >= 80 ? '#16a34a' : ($score >= 60 ? '#f59e0b' : '#ef4444');
@endphp

<div style="width:48px;height:48px;border-radius:999px;background:conic-gradient({{ $color }} {{ $score }}%, #e5e7eb 0);display:grid;place-items:center;">
    <div style="width:36px;height:36px;border-radius:999px;background:#fff;display:grid;place-items:center;font-size:12px;font-weight:900;color:{{ $color }};">{{ $score }}%</div>
</div>
