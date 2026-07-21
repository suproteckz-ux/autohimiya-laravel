@php
    /** @var \App\Models\Category $record */
    $depth = \App\Support\CategoryTree::depthFor((int) $record->id);
    $hasChildren = \App\Support\CategoryTree::hasChildren((int) $record->id);
    $isActive = $record->status === 'active';
    $isParent = $hasChildren || $depth === 0;
@endphp

<div
    class="flex min-w-0 items-center gap-2"
    style="padding-left: {{ min($depth * 22, 96) }}px"
    @if ($hasChildren)
        x-data="{ open: true }"
        x-on:category-tree-expand-all.window="open = true"
        x-on:category-tree-collapse-all.window="open = false"
    @endif
>
    @if ($hasChildren)
        <button
            type="button"
            x-on:click.stop="
                open = ! open;
                document.querySelectorAll('.category-descendant-of-{{ (int) $record->id }}').forEach((row) => row.classList.toggle('hidden', ! open));
            "
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-950"
            x-bind:title="open ? 'Свернуть ветку' : 'Развернуть ветку'"
        >
            <x-filament::icon icon="heroicon-o-chevron-down" x-show="open" class="h-4 w-4" />
            <x-filament::icon icon="heroicon-o-chevron-right" x-show="! open" class="h-4 w-4" />
        </button>
    @else
        <span class="h-6 w-6 shrink-0"></span>
    @endif

    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md {{ $hasChildren ? 'bg-amber-50 text-amber-600' : 'bg-gray-50 text-gray-400' }}">
        @if ($hasChildren)
            <x-filament::icon icon="heroicon-o-folder" class="h-4 w-4" />
        @else
            <x-filament::icon icon="{{ $depth >= 2 ? 'heroicon-o-tag' : 'heroicon-o-document' }}" class="h-4 w-4" />
        @endif
    </span>

    <div class="min-w-0">
        <div class="truncate text-sm {{ $isParent ? 'font-semibold' : 'font-medium' }} {{ $isActive ? 'text-gray-950' : 'text-gray-500' }}">
            {{ $record->display_name }}
        </div>

        <div class="mt-0.5 flex items-center gap-2 text-xs text-gray-500">
            <span>ID: {{ $record->id }}</span>
            @if ($record->parent)
                <span>• уровень {{ $depth + 1 }}</span>
                <span>• родитель: {{ $record->parent->display_name }}</span>
            @else
                <span>• верхний уровень</span>
            @endif
        </div>
    </div>
</div>
