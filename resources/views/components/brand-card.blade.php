@props(['brand'])
@php($logo = (! empty($brand->logo) && \Illuminate\Support\Facades\Storage::disk('public')->exists($brand->logo)) ? $brand->logo : null)
<a class="brand-card" href="{{ route('search.index', ['q' => $brand->display_name]) }}" aria-label="{{ $brand->display_name }}">
    @if($logo)
        <img src="{{ asset('storage/'.$logo) }}" alt="{{ $brand->display_name }}" loading="lazy" decoding="async">
    @else
        <strong>{{ $brand->display_name }}</strong>
    @endif
</a>
