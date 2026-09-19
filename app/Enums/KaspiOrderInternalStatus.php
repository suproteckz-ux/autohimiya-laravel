<?php

namespace App\Enums;

enum KaspiOrderInternalStatus: string
{
    case Observed = 'OBSERVED';
    case Reserved = 'RESERVED';
    case CancelledBeforeHandoff = 'CANCELLED_BEFORE_HANDOFF';
    case HandoffCandidate = 'HANDOFF_CANDIDATE';
    case HandedOffPendingPaloma = 'HANDED_OFF_PENDING_PALOMA';
    case PalomaConfirmed = 'PALOMA_CONFIRMED';
    case CancelledAfterHandoff = 'CANCELLED_AFTER_HANDOFF';
    case BaselineIgnored = 'BASELINE_IGNORED';
    case ErrorUnmatchedSku = 'ERROR_UNMATCHED_SKU';

    public function label(): string
    {
        return match ($this) {
            self::Observed => 'Наблюдение',
            self::Reserved => 'Зарезервирован',
            self::CancelledBeforeHandoff => 'Отменён до передачи',
            self::HandoffCandidate => 'Кандидат на передачу',
            self::HandedOffPendingPaloma => 'Передан / ожидает Paloma',
            self::PalomaConfirmed => 'Paloma подтверждён',
            self::CancelledAfterHandoff => 'Отменён после передачи',
            self::BaselineIgnored => 'Исходный (baseline)',
            self::ErrorUnmatchedSku => 'Ошибка SKU',
        };
    }

    public function affectsStock(): bool
    {
        return in_array($this, [self::Reserved, self::HandedOffPendingPaloma], true);
    }
}
