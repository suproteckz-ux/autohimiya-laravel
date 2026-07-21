<x-filament-panels::page>
    <div class="space-y-5">
        <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-900 dark:border-warning-900 dark:bg-warning-950 dark:text-warning-100">
            <div class="mb-2 font-semibold">CLI workflow</div>
            <ol class="mb-3 list-decimal space-y-1 pl-5">
                <li>Resolve Kaspi URLs from terminal</li>
                <li>Import Kaspi content</li>
                <li>Review products with missing content</li>
            </ol>
            <div class="mb-3">Kaspi URL resolver uses Playwright browser and must be run from CLI. Admin cannot launch browser reliably.</div>
            <pre class="overflow-auto rounded-lg bg-white p-3 text-xs text-gray-900 dark:bg-gray-900 dark:text-gray-100">php artisan kaspi:resolve-widget-urls --limit=50 --delay-ms=5000 -vvv
php artisan kaspi:resolve-widget-urls --limit=0 --delay-ms=5000 -vvv
php artisan kaspi:import-content --limit=0 --delay-ms=3000 --force=true</pre>
            <div class="mt-3">Publish заполняет только фото, описание и характеристики. Цены, остатки, SKU, Paloma, категории и заказы не меняются.</div>
        </div>

        <div class="grid gap-3 text-sm md:grid-cols-4">
            @foreach([
                'Всего товаров' => $this->contentStats()['products'] ?? 0,
                'Kaspi URLs' => $this->contentStats()['url_found'] ?? 0,
                'Imported' => $this->contentStats()['kaspi_imported'] ?? 0,
                'No photo' => $this->contentStats()['no_photo'] ?? 0,
                'No description' => $this->contentStats()['no_description'] ?? 0,
                'Broken text' => $this->contentStats()['broken_text'] ?? 0,
                'No data' => $this->contentStats()['kaspi_no_data'] ?? 0,
                'Errors' => $this->contentStats()['errors'] ?? 0,
            ] as $label => $value)
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        {{ $this->table }}

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Диагностика</div>
            <div class="grid gap-3 text-sm md:grid-cols-3">
                @foreach($this->diagnostics() as $label => $value)
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-950">
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page>
