<?php

namespace App\Services\Kaspi;

use App\Models\KaspiProductionPush;
use App\Support\Utf8Sanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class KaspiProductionBridgeService
{
    public function __construct(
        private readonly KaspiProductionCandidateClient $candidateClient,
        private readonly KaspiLocalPageCollector $collector,
        private readonly KaspiLocalUrlResolver $urlResolver,
        private readonly KaspiProductionPayloadValidator $validator,
    ) {
    }

    public function push(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $force = (bool) ($options['force'] ?? false);
        $debug = (bool) ($options['debug'] ?? false);
        if (array_values(array_filter((array) ($options['sku'] ?? []), 'filled')) === [] && blank($options['limit'] ?? null)) {
            throw new \InvalidArgumentException('Provide --sku or --limit.');
        }

        $candidates = $this->candidateClient->fetch($options);
        $rows = [];
        $metrics = ['candidates' => count($candidates), 'collected' => 0, 'sent' => 0, 'unchanged' => 0, 'blocked' => 0, 'not_found' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($candidates as $candidate) {
            $sku = (string) ($candidate['sku'] ?? '');
            try {
                $push = $this->existingReusablePush($sku, $force);
                if ($push && ! $force) {
                    if ($push->status === 'sent') {
                        $metrics['skipped']++;
                        $rows[] = $this->row($candidate, 'skipped', 'already_sent', $push);
                        continue;
                    }
                } else {
                    $push = $this->collect($candidate, $debug);
                }

                $metrics['collected']++;

                if ($dryRun) {
                    $rows[] = $this->row($candidate, 'dry_run', 'production_send_skipped_dry_run', $push);
                    continue;
                }

                $response = $this->send($push);
                $status = (string) ($response['body']['status'] ?? $response['body']['error'] ?? 'failed');
                $this->recordResponse($push, $response['http_status'], $status, $response['body']);

                match ($status) {
                    'imported' => $metrics['sent']++,
                    'unchanged' => $metrics['unchanged']++,
                    'manual_content_protected' => $metrics['blocked']++,
                    'product_not_found' => $metrics['not_found']++,
                    default => $response['http_status'] >= 400 ? $metrics['failed']++ : $metrics['sent']++,
                };

                $rows[] = $this->row($candidate, $status, 'http_'.$response['http_status'], $push);
            } catch (Throwable $exception) {
                $metrics['failed']++;
                $rows[] = [
                    'sku' => $sku,
                    'candidate_status' => $this->candidateStatus($candidate),
                    'kaspi_url' => $candidate['kaspi_product_url'] ?? null,
                    'image_count' => 0,
                    'description_present' => false,
                    'attribute_count' => 0,
                    'payload_valid' => false,
                    'status' => 'failed',
                    'message' => Utf8Sanitizer::errorForDb($exception, 300),
                    'request_id' => null,
                    'content_hash' => null,
                ];
            }
        }

        return [
            'successful' => $metrics['failed'] === 0,
            'message' => 'Kaspi production push complete. Products checked: '.count($candidates),
            'metrics' => $metrics,
            'rows' => $rows,
        ];
    }

    private function collect(array $candidate, bool $debug): KaspiProductionPush
    {
        $sku = (string) ($candidate['sku'] ?? '');
        if (blank($sku)) {
            throw new \RuntimeException('candidate_sku_missing');
        }

        $kaspiUrl = (string) ($candidate['kaspi_product_url'] ?? '');
        if (blank($kaspiUrl)) {
            $resolved = $this->urlResolver->resolve($sku, (string) ($candidate['name'] ?? ''), $debug);
            $kaspiUrl = (string) $resolved['url'];
        }

        $collected = $this->collector->collectUrl($kaspiUrl, $sku, $debug);
        $parserPayload = (array) $collected['parser_payload'];
        $content = [
            'name' => $parserPayload['name'] ?? null,
            'description' => $parserPayload['description'] ?? null,
            'attributes' => array_values((array) data_get($parserPayload, 'cleaned.attributes', [])),
            'images' => collect((array) data_get($parserPayload, 'cleaned.images', []))
                ->values()
                ->map(fn (string $url, int $index): array => ['url' => $url, 'position' => $index + 1])
                ->all(),
        ];
        $payload = [
            'version' => 1,
            'request_id' => (string) Str::uuid(),
            'collected_at' => now()->toIso8601String(),
            'sku' => $sku,
            'kaspi_url' => (string) $collected['url'],
            'content' => $content,
            'source' => [
                'collector' => 'local-playwright',
                'parser_version' => '1',
            ],
        ];
        $this->validator->validate($payload, strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

        return KaspiProductionPush::query()->create([
            'product_id' => null,
            'sku' => $sku,
            'kaspi_url' => (string) $collected['url'],
            'request_id' => $payload['request_id'],
            'content_hash' => $this->validator->contentHash($payload),
            'collected_payload' => $payload,
            'status' => 'collected',
            'collected_at' => now(),
        ]);
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    private function send(KaspiProductionPush $push): array
    {
        $url = (string) config('services.kaspi.production_api_url');
        $token = (string) config('services.kaspi.production_api_token');
        if (blank($url) || blank($token)) {
            throw new \RuntimeException('production_api_not_configured');
        }

        $response = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::timeout(30)
                    ->acceptJson()
                    ->withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($url, $push->collected_payload);
            } catch (ConnectionException $exception) {
                if ($attempt === 3) {
                    throw $exception;
                }

                usleep(500_000 * $attempt);

                continue;
            }

            if (! $response->serverError() || $attempt === 3) {
                break;
            }

            usleep(500_000 * $attempt);
        }

        if (! $response) {
            throw new \RuntimeException('production_api_no_response');
        }

        return [
            'http_status' => $response->status(),
            'body' => is_array($response->json()) ? $response->json() : ['ok' => false, 'error' => 'invalid_json_response'],
        ];
    }

    private function recordResponse(KaspiProductionPush $push, int $httpStatus, string $status, array $body): void
    {
        $push->update([
            'status' => $httpStatus >= 200 && $httpStatus < 300 ? 'sent' : 'failed',
            'production_status' => $status,
            'http_status' => $httpStatus,
            'response_summary' => $this->safeResponse($body),
            'error_code' => $body['error'] ?? null,
            'sent_at' => now(),
        ]);
    }

    private function existingReusablePush(string $sku, bool $force): ?KaspiProductionPush
    {
        if ($force) {
            return null;
        }

        return KaspiProductionPush::query()
            ->where('sku', $sku)
            ->whereIn('status', ['collected', 'failed', 'sent'])
            ->latest('id')
            ->first();
    }

    private function row(array $candidate, string $status, string $message, ?KaspiProductionPush $push): array
    {
        $payload = (array) ($push?->collected_payload ?: []);

        return [
            'sku' => $candidate['sku'] ?? $push?->sku,
            'candidate_status' => $this->candidateStatus($candidate),
            'kaspi_url' => $push?->kaspi_url ?: ($candidate['kaspi_product_url'] ?? null),
            'image_count' => count((array) data_get($payload, 'content.images', [])),
            'description_present' => filled(data_get($payload, 'content.description')),
            'attribute_count' => count((array) data_get($payload, 'content.attributes', [])),
            'payload_valid' => (bool) $push,
            'status' => $status,
            'message' => $message,
            'request_id' => $push?->request_id,
            'content_hash' => $push?->content_hash,
        ];
    }

    private function safeResponse(array $body): array
    {
        unset($body['token'], $body['authorization'], $body['cookies']);

        return $body;
    }

    private function candidateStatus(array $candidate): string
    {
        return match (true) {
            (bool) ($candidate['manual_content_protected'] ?? false) => 'protected',
            ! (bool) ($candidate['has_images'] ?? false) && ! (bool) ($candidate['has_description'] ?? false) => 'missing_photo_and_description',
            ! (bool) ($candidate['has_images'] ?? false) => 'missing_photo',
            ! (bool) ($candidate['has_description'] ?? false) => 'missing_description',
            default => 'not_missing_content',
        };
    }
}
