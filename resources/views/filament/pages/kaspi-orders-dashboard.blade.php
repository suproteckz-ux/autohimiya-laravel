<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Mode banner --}}
        @if($mode === 'observe')
            <div class="rounded-lg bg-blue-50 border border-blue-200 p-4 flex items-start gap-3">
                <x-heroicon-o-eye class="w-5 h-5 text-blue-500 mt-0.5 shrink-0" />
                <div>
                    <p class="font-semibold text-blue-800">Режим: OBSERVE</p>
                    <p class="text-sm text-blue-700">
                        Система читает заказы Kaspi и ведёт учёт резервов, но <strong>не влияет на stock feed</strong>.
                        Текущая схема Paloma → Kaspi продолжает работать без изменений.
                    </p>
                </div>
            </div>
        @else
            <div class="rounded-lg bg-green-50 border border-green-200 p-4 flex items-start gap-3">
                <x-heroicon-o-check-circle class="w-5 h-5 text-green-500 mt-0.5 shrink-0" />
                <div>
                    <p class="font-semibold text-green-800">Режим: ACTIVE</p>
                    <p class="text-sm text-green-700">
                        Reservation engine активен. Kaspi XML feed использует расчётный остаток.
                    </p>
                </div>
            </div>
        @endif

        @unless($handoff_flag)
            <div class="rounded-lg bg-yellow-50 border border-yellow-200 p-4 flex items-start gap-3">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-yellow-500 mt-0.5 shrink-0" />
                <div>
                    <p class="font-semibold text-yellow-800">KASPI_HANDOFF_BY_COURIER_DATE = false</p>
                    <p class="text-sm text-yellow-700">
                        Автоматический перевод RESERVED → HANDED_OFF отключён.
                        Заказы с <code>courierTransmissionDate</code> отображаются как «Кандидат на передачу».
                        Включите флаг после проверки на реальных заказах.
                    </p>
                </div>
            </div>
        @endunless

        {{-- Stats grid --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg border p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Всего заказов</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $total_orders }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg border p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Зарезервировано</p>
                <p class="text-3xl font-bold text-orange-600">{{ $reserved_count }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg border p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Ожидают Paloma</p>
                <p class="text-3xl font-bold text-red-600">{{ $pending_paloma_count }}</p>
                <p class="text-xs text-gray-400">{{ $pending_paloma_qty }} ед.</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg border p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Baseline (старые)</p>
                <p class="text-3xl font-bold text-gray-400">{{ $baseline_count }}</p>
            </div>
        </div>

        {{-- Paloma sync status --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border p-4">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Последний успешный Paloma sync</p>
            <p class="text-lg font-semibold {{ $last_paloma_sync ? 'text-green-600' : 'text-red-500' }}">
                {{ $last_paloma_sync ? $last_paloma_sync->format('d.m.Y H:i') : 'Не найден' }}
            </p>
            @if(!$confirm_safe && $confirm_block_reason)
                <p class="text-sm text-red-500 mt-1">⚠ {{ $confirm_block_reason }}</p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
