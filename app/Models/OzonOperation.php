<?php

namespace App\Models;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use Database\Factories\OzonOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OzonOperation extends Model
{
    /** @use HasFactory<OzonOperationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'status' => OzonOperationStatus::Pending->value,
        'attempt' => 1,
    ];

    protected function casts(): array
    {
        return [
            'operation_type' => OzonOperationType::class,
            'status' => OzonOperationStatus::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(OzonAccount::class, 'ozon_account_id');
    }

    public function ozonProduct(): BelongsTo
    {
        return $this->belongsTo(OzonProduct::class);
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }
}
