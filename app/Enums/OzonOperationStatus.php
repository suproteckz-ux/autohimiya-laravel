<?php

namespace App\Enums;

enum OzonOperationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Running => 'Выполняется',
            self::Completed => 'Завершена',
            self::CompletedWithWarnings => 'Завершена с предупреждениями',
            self::Failed => 'Ошибка',
            self::Cancelled => 'Отменена',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }
}
