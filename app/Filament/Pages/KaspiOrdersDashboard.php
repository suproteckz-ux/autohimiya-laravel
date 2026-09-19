<?php

namespace App\Filament\Pages;

use App\Enums\KaspiOrderInternalStatus;
use App\Models\KaspiOrder;
use App\Models\KaspiStockEventLog;
use App\Services\Kaspi\PalomaStockConfirmationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use BackedEnum;
use UnitEnum;

class KaspiOrdersDashboard extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';
    protected static string|UnitEnum|null $navigationGroup = 'Kaspi';
    protected static ?string $navigationLabel = 'Панель управления';
    protected static ?string $title = 'Kaspi Orders — Панель управления';
    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.kaspi-orders-dashboard';

    public bool $showCutoverModal = false;
    public bool $showConfirmModal = false;

    public function getViewData(): array
    {
        $mode = config('services.kaspi.orders_mode', 'observe');
        $handoffFlag = (bool) config('services.kaspi.handoff_by_courier_date', false);

        $confirmationService = app(PalomaStockConfirmationService::class);
        $stats = $confirmationService->pendingStats();
        $lastPalomaSync = $confirmationService->latestSuccessfulPalomaSyncLog();

        $confirmSafe = false;
        $confirmBlockReason = null;
        try {
            $confirmationService->assertCanConfirm();
            $confirmSafe = true;
        } catch (\Throwable $e) {
            $confirmBlockReason = $e->getMessage();
        }

        return [
            'mode' => $mode,
            'handoff_flag' => $handoffFlag,
            'total_orders' => KaspiOrder::count(),
            'reserved_count' => KaspiOrder::where('internal_stock_status', KaspiOrderInternalStatus::Reserved->value)->count(),
            'pending_paloma_count' => $stats['orders_count'],
            'pending_paloma_qty' => $stats['items_qty'],
            'baseline_count' => KaspiOrder::where('baseline_ignored', true)->count(),
            'last_paloma_sync' => $lastPalomaSync?->finished_at,
            'confirm_safe' => $confirmSafe,
            'confirm_block_reason' => $confirmBlockReason,
        ];
    }

    protected function getHeaderActions(): array
    {
        $confirmationService = app(PalomaStockConfirmationService::class);
        $confirmSafe = false;
        try {
            $confirmationService->assertCanConfirm();
            $confirmSafe = true;
        } catch (\Throwable) {}

        return [
            Action::make('paloma_confirmation')
                ->label('Остатки Paloma проверены и актуальны')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->disabled(! $confirmSafe)
                ->requiresConfirmation()
                ->modalHeading('Подтвердить актуальность остатков?')
                ->modalDescription(
                    'Все товары по заказам, физически переданным Kaspi до этой контрольной точки, '
                    . 'будут считаться уже списанными в Paloma. Продолжить?'
                )
                ->action(function () use ($confirmationService) {
                    try {
                        $userId = auth()->id();
                        $checkpoint = $confirmationService->confirm($userId);

                        Notification::make()
                            ->title('Paloma подтверждена')
                            ->body("Закрыто заказов: {$checkpoint->pending_orders_count}, позиций: {$checkpoint->pending_items_qty}")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Ошибка: ' . $e->getMessage())->danger()->send();
                    }
                }),

            Action::make('create_baseline')
                ->label('Создать контрольную точку запуска')
                ->icon('heroicon-o-flag')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Создать baseline?')
                ->modalDescription(
                    'Все существующие заказы до контрольной точки будут считаться уже учтёнными в Paloma '
                    . 'и не будут влиять на резерв. Новые заказы после контрольной точки будут участвовать '
                    . 'в reservation engine. Это действие нельзя отменить.'
                )
                ->action(function () {
                    $cutoverAt = Carbon::now();
                    $count = KaspiOrder::where('baseline_ignored', false)
                        ->where('kaspi_created_at', '<', $cutoverAt)
                        ->whereNotIn('internal_stock_status', [
                            KaspiOrderInternalStatus::BaselineIgnored->value,
                            KaspiOrderInternalStatus::CancelledBeforeHandoff->value,
                            KaspiOrderInternalStatus::CancelledAfterHandoff->value,
                            KaspiOrderInternalStatus::PalomaConfirmed->value,
                        ])
                        ->update([
                            'baseline_ignored' => true,
                            'internal_stock_status' => KaspiOrderInternalStatus::BaselineIgnored->value,
                        ]);

                    KaspiStockEventLog::record('BASELINE_CREATED', metadata: [
                        'cutover_at' => $cutoverAt->toIso8601String(),
                        'orders_marked' => $count,
                    ]);

                    Notification::make()
                        ->title('Baseline создан')
                        ->body("Помечено заказов: {$count}. Точка отсчёта: {$cutoverAt->format('d.m.Y H:i')}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
