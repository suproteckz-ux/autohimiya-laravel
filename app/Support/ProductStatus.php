<?php

namespace App\Support;

final class ProductStatus
{
    public const ACTIVE_SYNCED = 'active_synced';
    public const NEEDS_REVIEW = 'needs_review';
    public const ACTIVE_MANUAL = 'active_manual';
    public const INACTIVE = 'inactive';
    public const ARCHIVED = 'archived';

    public static function values(): array
    {
        return [
            self::ACTIVE_SYNCED,
            self::NEEDS_REVIEW,
            self::ACTIVE_MANUAL,
            self::INACTIVE,
            self::ARCHIVED,
        ];
    }

    public static function visibleValues(): array
    {
        return [
            self::ACTIVE_SYNCED,
            self::ACTIVE_MANUAL,
            self::NEEDS_REVIEW,
        ];
    }

    public static function kaspiEnrichmentValues(): array
    {
        return [
            self::ACTIVE_SYNCED,
            self::ACTIVE_MANUAL,
            self::NEEDS_REVIEW,
        ];
    }

    public static function options(): array
    {
        return collect(self::values())
            ->mapWithKeys(fn (string $status): array => [$status => self::label($status)])
            ->all();
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::ACTIVE_SYNCED => 'Активен, Paloma',
            self::ACTIVE_MANUAL => 'Активен вручную',
            self::NEEDS_REVIEW => 'На проверке',
            self::INACTIVE => 'Скрыт',
            self::ARCHIVED => 'Архив',
            default => $status,
        };
    }

    public static function isVisibleStatus(string $status): bool
    {
        return in_array($status, self::visibleValues(), true);
    }
}
