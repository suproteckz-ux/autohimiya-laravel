<?php

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'source',
        'requested_by',
        'status',
        'requested_at',
        'started_at',
        'finished_at',
        'heartbeat_at',
        'progress',
        'total_items',
        'processed_items',
        'created_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'message',
        'error_message',
        'context',
        'command_name',
        'handler',
        'lock_key',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'progress' => 'integer',
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'context' => 'array',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AutomationRunStatus::activeValues());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AutomationRunStatus::Pending->value);
    }

    public function automationType(): ?AutomationType
    {
        return AutomationType::tryFrom((string) $this->type);
    }

    public function durationSeconds(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?: now());
    }
}