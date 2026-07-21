@php($problems = \App\Support\ContentScore::problems($record))

@if(empty($problems))
    <span style="display:inline-flex;border-radius:999px;padding:4px 8px;background:#dcfce7;color:#15803d;font-size:12px;font-weight:800;">Готово</span>
@else
    <div style="display:flex;gap:4px;flex-wrap:wrap;max-width:170px;">
        @foreach(array_slice($problems, 0, 3) as $problem)
            <span style="display:inline-flex;border-radius:999px;padding:4px 7px;background:#fff7ed;color:#c2410c;font-size:11px;font-weight:800;">{{ $problem }}</span>
        @endforeach
        @if(count($problems) > 3)
            <span style="display:inline-flex;border-radius:999px;padding:4px 7px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:800;">+{{ count($problems) - 3 }}</span>
        @endif
    </div>
@endif
