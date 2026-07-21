<?php

namespace App\Models;

use App\Support\Utf8Sanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogEnrichmentTask extends Model
{
    public const TASK_TYPES = [
        'brand',
        'category',
        'description',
        'seo_title',
        'seo_description',
        'seo',
        'image',
        'attributes',
    ];

    public const STATUSES = [
        'pending',
        'draft',
        'approved',
        'rejected',
        'applied',
        'published',
        'failed',
    ];

    public const SOURCES = [
        'opencart',
        'paloma',
        'rule',
        'ai',
        'manual',
        'gsc',
        'external_search',
    ];

    protected $guarded = [];

    protected $casts = [
        'payload_json' => 'array',
        'current_payload' => 'array',
        'suggested_payload' => 'array',
        'priority' => 'integer',
        'confidence' => 'integer',
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function setPayloadJsonAttribute(mixed $value): void
    {
        $this->attributes['payload_json'] = $this->encodeJsonPayload($value);
    }

    public function setCurrentPayloadAttribute(mixed $value): void
    {
        $this->attributes['current_payload'] = $this->encodeJsonPayload($value);
    }

    public function setSuggestedPayloadAttribute(mixed $value): void
    {
        $this->attributes['suggested_payload'] = $this->encodeJsonPayload($value);
    }

    public function setCurrentValueAttribute(mixed $value): void
    {
        $this->attributes['current_value'] = Utf8Sanitizer::cleanString($value === null ? null : (string) $value);
    }

    public function setSuggestedValueAttribute(mixed $value): void
    {
        $this->attributes['suggested_value'] = Utf8Sanitizer::cleanString($value === null ? null : (string) $value);
    }

    public function setReasonAttribute(mixed $value): void
    {
        $this->attributes['reason'] = Utf8Sanitizer::cleanString($value === null ? null : (string) $value);
    }

    public function setErrorMessageAttribute(mixed $value): void
    {
        $this->attributes['error_message'] = Utf8Sanitizer::cleanString($value === null ? null : (string) $value);
    }

    private function encodeJsonPayload(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = Utf8Sanitizer::clean($value);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $json = json_encode([], JSON_UNESCAPED_UNICODE);
        }

        return $json;
    }
}
