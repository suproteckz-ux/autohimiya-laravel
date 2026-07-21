@php
    $badges = [
        'Manual Name' => $record->name_is_manual,
        'Manual Category' => $record->category_is_manual,
        'Manual Description' => $record->description_is_manual,
        'Manual Photos' => $record->photos_are_manual,
        'Manual Specifications' => $record->attributes_are_manual,
        'Manual SEO' => $record->seo_is_manual,
        'Verified Content' => filled($record->content_verified_at),
        'Locked Content' => $record->auto_content_locked,
    ];
@endphp

<div class="flex flex-wrap gap-1">
    @foreach($badges as $label => $enabled)
        @if($enabled)
            <span class="rounded bg-primary-50 px-1.5 py-0.5 text-[10px] font-medium text-primary-700 ring-1 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300">
                {{ $label }}
            </span>
        @endif
    @endforeach

    @if(! collect($badges)->contains(true))
        <span class="text-xs text-gray-400">Auto</span>
    @endif
</div>
