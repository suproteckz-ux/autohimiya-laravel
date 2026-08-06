<?php

namespace App\Enums;

enum OzonProductStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Processing = 'processing';
    case Accepted = 'accepted';
    case NeedsFix = 'needs_fix';
    case Rejected = 'rejected';
    case Published = 'published';
    case Archived = 'archived';
    case Disabled = 'disabled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Ready => 'Готов',
            self::Queued => 'В очереди',
            self::Sending => 'Отправляется',
            self::Sent => 'Отправлен',
            self::Processing => 'Обрабатывается',
            self::Accepted => 'Принят',
            self::NeedsFix => 'Требует доработки',
            self::Rejected => 'Отклонён',
            self::Published => 'Опубликован',
            self::Archived => 'Архивирован',
            self::Disabled => 'Отключён',
            self::Failed => 'Ошибка',
        };
    }

    public function isExportable(): bool
    {
        return in_array($this, [self::Draft, self::Ready, self::Rejected, self::Failed], true);
    }

    public function isCommercialSyncAllowed(): bool
    {
        return $this === self::Published;
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Published, self::Archived, self::Disabled, self::Failed], true);
    }
}
