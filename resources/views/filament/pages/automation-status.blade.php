<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($this->statusRows() as $row)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $row['label'] }}</div>
                <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $row['value'] }}</div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
