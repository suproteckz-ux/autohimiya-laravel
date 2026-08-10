<x-filament-panels::page>
 <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm">На этом этапе данные сохраняются только локально и не отправляются в Ozon.</div>
 @if(empty($this->tableFilters['category']['value'] ?? null))<div class="rounded-xl border p-6">Выберите категорию сайта, чтобы загрузить товары.</div>@endif
 {{ $this->table }}
</x-filament-panels::page>
