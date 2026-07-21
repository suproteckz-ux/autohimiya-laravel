@php
    use App\Support\ProductImageUrlResolver;

    $url = $record ? ProductImageUrlResolver::productAdminUrl($record) : null;
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-4">
    @if($url)
        <img src="{{ $url }}" alt="{{ $record->display_name }}" class="mx-auto h-56 w-full object-contain">
    @else
        <div class="flex h-56 items-center justify-center rounded-lg bg-gray-50 text-sm font-semibold text-gray-400">
            Нет фото
        </div>
    @endif
</div>
