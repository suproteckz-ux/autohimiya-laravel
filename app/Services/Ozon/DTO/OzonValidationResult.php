<?php

namespace App\Services\Ozon\DTO;

final readonly class OzonValidationResult
{
    public function __construct(public array $errors = [], public array $warnings = []) {}

    public function isReady(): bool { return $this->errors === []; }
    public function hasWarnings(): bool { return $this->warnings !== []; }
    public function toArray(): array { return ['is_ready' => $this->isReady(), 'has_warnings' => $this->hasWarnings(), 'errors' => $this->errors, 'warnings' => $this->warnings]; }
}
