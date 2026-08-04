<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class KaspiProductionPayloadValidator
{
    public function validate(array $payload, int $rawBytes = 0): array
    {
        if ($rawBytes > (int) config('services.kaspi.production_payload_max_bytes', 262144)) {
            throw ValidationException::withMessages(['payload' => 'Payload is too large.']);
        }

        $validator = Validator::make($payload, [
            'version' => ['required', 'integer', 'in:1'],
            'request_id' => ['required', 'uuid'],
            'collected_at' => ['required', 'date'],
            'sku' => ['required', 'string', 'max:120'],
            'kaspi_url' => ['required', 'url', 'max:2048'],
            'content' => ['required', 'array'],
            'content.name' => ['nullable', 'string', 'max:255'],
            'content.description' => ['nullable', 'string', 'max:65000'],
            'content.attributes' => ['nullable', 'array', 'max:80'],
            'content.attributes.*.name' => ['required_with:content.attributes', 'string', 'max:120'],
            'content.attributes.*.value' => ['required_with:content.attributes', 'string', 'max:1000'],
            'content.images' => ['nullable', 'array', 'max:12'],
            'content.images.*.url' => ['required_with:content.images', 'url', 'max:2048'],
            'content.images.*.position' => ['required_with:content.images', 'integer', 'min:1', 'max:99'],
            'source' => ['required', 'array'],
            'source.collector' => ['required', 'string', 'max:80'],
            'source.parser_version' => ['required', 'string', 'max:40'],
        ]);

        $validator->after(function ($validator) use ($payload): void {
            $kaspiUrl = (string) data_get($payload, 'kaspi_url', '');
            if (! $this->isKaspiProductUrl($kaspiUrl)) {
                $validator->errors()->add('kaspi_url', 'Kaspi URL must be an HTTPS product URL.');
            }

            foreach ((array) data_get($payload, 'content.images', []) as $index => $image) {
                $url = (string) ($image['url'] ?? '');
                if (! $this->isSafeRemoteImageUrl($url)) {
                    $validator->errors()->add('content.images.'.$index.'.url', 'Image URL must be a safe HTTPS remote URL.');
                }
            }

            foreach ($this->flattenStrings($payload) as $path => $value) {
                if ($this->containsExecutableContent($value)) {
                    $validator->errors()->add($path, 'Executable or serialized content is not accepted.');
                }
            }
        });

        return $validator->validate();
    }

    public function contentHash(array $payload): string
    {
        return hash('sha256', json_encode([
            'sku' => data_get($payload, 'sku'),
            'kaspi_url' => data_get($payload, 'kaspi_url'),
            'content' => data_get($payload, 'content'),
            'source' => data_get($payload, 'source'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function isKaspiProductUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'kaspi.kz' || str_ends_with($host, '.kaspi.kz'))
            && str_starts_with($path, '/shop/p/');
    }

    private function isSafeRemoteImageUrl(string $url): bool
    {
        if (str_starts_with($url, 'data:') || preg_match('/^[a-z]:[\\\\\/]/i', $url) || str_starts_with($url, '/')) {
            return false;
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || blank($parts['host'] ?? null)) {
            return false;
        }

        $host = mb_strtolower((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        foreach ((array) config('services.kaspi.image_allowed_hosts', ['resources.cdn-kaspi.kz', 'kaspi.kz']) as $allowedHost) {
            $allowedHost = mb_strtolower(trim((string) $allowedHost));
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function flattenStrings(array $payload, string $prefix = ''): array
    {
        $strings = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $strings += $this->flattenStrings($value, $path);
            } elseif (is_string($value)) {
                $strings[$path] = $value;
            }
        }

        return $strings;
    }

    private function containsExecutableContent(string $value): bool
    {
        $lower = mb_strtolower($value);

        return str_contains($lower, '<?php')
            || str_contains($lower, '<script')
            || str_contains($lower, 'proc_open(')
            || str_contains($lower, 'shell_exec(')
            || preg_match('/^[aOsibd]:\d+:/', $value) === 1;
    }
}
