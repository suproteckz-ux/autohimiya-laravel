<?php

namespace App\Enums;

enum AutomationRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }

    /**
     * @return array<int, string>
     */
    public static function activeValues(): array
    {
        return [self::Pending->value, self::Running->value];
    }
}