<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\Http;

class KaspiProductionCandidateClient
{
    private array $lastDiagnostics = [];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(array $options = []): array
    {
        $url = $this->candidateUrl();
        $token = (string) config('services.kaspi.production_api_token');
        if (blank($url) || blank($token)) {
            throw new \RuntimeException('production_candidates_api_not_configured');
        }

        $limit = filled($options['limit'] ?? null) ? max(1, (int) $options['limit']) : 25;
        $remaining = $limit;
        $cursor = $options['cursor'] ?? null;
        $candidates = [];

        do {
            $pageLimit = min(100, $remaining);
            $query = [
                'limit' => $pageLimit,
                'missing' => 'content',
                'include_protected' => 'false',
            ];

            if ((bool) ($options['debug'] ?? false)) {
                $query['debug'] = 'true';
            }

            if (filled($cursor)) {
                $query['cursor'] = $cursor;
            }

            foreach (array_values(array_filter((array) ($options['sku'] ?? []), 'filled')) as $sku) {
                $query['sku'][] = $sku;
            }

            $response = Http::timeout(30)
                ->acceptJson()
                ->withToken($token)
                ->get($url, $query);

            if (! $response->successful()) {
                throw new \RuntimeException('candidate_http_'.$response->status());
            }

            $body = $response->json();
            if (! is_array($body)) {
                throw new \RuntimeException('candidate_invalid_json');
            }

            if (isset($body['diagnostics']) && is_array($body['diagnostics'])) {
                $this->lastDiagnostics = $body['diagnostics'];
            }

            foreach ((array) ($body['data'] ?? []) as $candidate) {
                if (is_array($candidate)) {
                    $candidates[] = $candidate;
                    $remaining--;
                }
            }

            $cursor = $body['next_cursor'] ?? null;
        } while ($cursor && $remaining > 0);

        return $candidates;
    }

    public function diagnostics(): array
    {
        return $this->lastDiagnostics;
    }

    private function candidateUrl(): string
    {
        $configured = (string) config('services.kaspi.production_candidates_url');
        if (filled($configured)) {
            return $configured;
        }

        $importUrl = (string) config('services.kaspi.production_api_url');

        return str_ends_with($importUrl, '/import')
            ? substr($importUrl, 0, -strlen('/import')).'/candidates'
            : '';
    }
}
