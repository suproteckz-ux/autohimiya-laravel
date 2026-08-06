<?php

namespace App\Services\Ozon\DTO;

final readonly class OzonImageResult
{
    public function __construct(public array $urls, public ?string $primary, public array $warnings = [], public array $errors = []) {}
    public function toArray(): array { return ['urls' => $this->urls, 'primary' => $this->primary, 'warnings' => $this->warnings, 'errors' => $this->errors]; }
}
