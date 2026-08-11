<?php

namespace App\Services\Ozon;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Exceptions\OzonApiException;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use App\Models\OzonProduct;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class OzonApiClient
{
    private const TAXONOMY_ERROR_BODY_LIMIT = 12_000;

    public const BASE_URL = 'https://api-seller.ozon.ru';

    public const PRODUCT_IMPORT_ENDPOINT = '/v3/product/import';
    public const PRODUCT_IMPORT_INFO_ENDPOINT = '/v1/product/import/info';

    private const READ_ONLY_ENDPOINTS = [
        '/v1/seller/info' => 'POST',
        '/v2/warehouse/list' => 'POST',
        '/v1/description-category/tree' => 'POST',
        '/v1/description-category/attribute' => 'POST',
        '/v1/description-category/attribute/values' => 'POST',
        self::PRODUCT_IMPORT_INFO_ENDPOINT => 'POST',
    ];

    private const WRITE_ENDPOINTS = [
        self::PRODUCT_IMPORT_ENDPOINT => 'POST',
    ];

    public function post(OzonAccount $account, string $endpoint, array $payload, OzonOperationType $type, ?AutomationRun $run = null): array
    {
        return $this->request($account, $endpoint, $payload, $type, $run, false, false);
    }

    public function postEmptyJsonObject(OzonAccount $account, string $endpoint, OzonOperationType $type, ?AutomationRun $run = null): array
    {
        return $this->request($account, $endpoint, [], $type, $run, true, false);
    }

    public function postProductImport(OzonAccount $account, OzonProduct $product, array $payload, AutomationRun $run): array
    {
        return $this->request($account, self::PRODUCT_IMPORT_ENDPOINT, $payload, OzonOperationType::ProductExport, $run, false, true, $product);
    }

    public function postProductImportStatus(OzonAccount $account, OzonProduct $product, array $payload, AutomationRun $run): array
    {
        return $this->request($account, self::PRODUCT_IMPORT_INFO_ENDPOINT, $payload, OzonOperationType::StatusCheck, $run, false, false, $product);
    }

    private function request(OzonAccount $account, string $endpoint, array $payload, OzonOperationType $type, ?AutomationRun $run, bool $emptyJsonObject, bool $write, ?OzonProduct $product = null): array
    {
        $method = 'POST';
        $allowed = $write ? self::WRITE_ENDPOINTS : self::READ_ONLY_ENDPOINTS;
        if (($allowed[$endpoint] ?? null) !== $method) {
            throw new OzonApiException($write ? 'Ozon write endpoint is not permitted.' : 'Ozon endpoint is not permitted by the read-only client.');
        }

        $operation = OzonOperation::query()->create([
            'ozon_account_id' => $account->id,
            'automation_run_id' => $run?->id,
            'ozon_product_id' => $product?->id,
            'operation_key' => (string) str()->uuid(),
            'operation_type' => $type,
            'status' => OzonOperationStatus::Running,
            'endpoint' => $endpoint,
            'http_method' => $method,
            'request_payload' => $type === OzonOperationType::TaxonomySync
                ? $this->taxonomyRequestMetadata($payload)
                : $this->redact($payload),
            'started_at' => now(),
        ]);
        $attempt = 0;

        try {
            do {
                $attempt++;
                try {
                    $request = Http::baseUrl(self::BASE_URL)->acceptJson()
                        ->withHeaders(['Client-Id' => $account->client_id, 'Api-Key' => $account->api_key])
                        ->connectTimeout(5)->timeout(20);
                    $response = $emptyJsonObject
                        ? $request->withBody('{}', 'application/json')->post($endpoint)
                        : $request->asJson()->post($endpoint, $payload);
                } catch (ConnectionException) {
                    if ($attempt < 3) {
                        usleep(100_000 * $attempt);
                        continue;
                    }
                    throw new OzonApiException('Ozon API connection timed out.', null, 'timeout', true);
                }

                if (($response->status() === 429 || $response->serverError()) && $attempt < 3) {
                    $this->waitBeforeRetry($response, $attempt);
                    continue;
                }

                $body = is_array($response->json()) ? $response->json() : [];
                $businessError = $body['message'] ?? $body['error']['message'] ?? null;
                $businessSuccess = $response->successful() && ! filled($businessError);
                $errorCode = $businessSuccess ? null : $this->errorCode($response->status(), $businessError);
                $safeError = $businessSuccess ? null : $this->safeError($businessError, $response->status(), $account, $endpoint, $errorCode);

                $operation->update([
                    'status' => $businessSuccess ? OzonOperationStatus::Completed : OzonOperationStatus::Failed,
                    'response_payload' => $type === OzonOperationType::TaxonomySync
                        ? $this->taxonomyResponseMetadata($body, $businessSuccess, $account)
                        : $this->redact($body, $account),
                    'http_status' => $response->status(),
                    'request_id' => $response->header('x-request-id'),
                    'attempt' => $attempt,
                    'finished_at' => now(),
                    'error_code' => $errorCode,
                    'error_message' => $safeError,
                ]);

                if (! $businessSuccess) {
                    throw new OzonApiException($safeError, $response->status(), $errorCode, $response->status() === 429 || $response->serverError());
                }

                return $body;
            } while ($attempt < 3);
        } catch (Throwable $exception) {
            $operation->refresh();
            if (! $operation->status->isFinished()) {
                $operation->update([
                    'status' => OzonOperationStatus::Failed,
                    'attempt' => $attempt,
                    'finished_at' => now(),
                    'error_code' => $exception instanceof OzonApiException ? $exception->errorCode : 'unexpected_error',
                    'error_message' => $exception instanceof OzonApiException ? $exception->getMessage() : 'Unexpected Ozon API error.',
                ]);
            }
            throw $exception;
        }

        throw new OzonApiException('Ozon API retry limit reached.', null, null, true);
    }

    public static function allowedEndpoints(): array
    {
        return array_keys(self::READ_ONLY_ENDPOINTS);
    }

    public static function writeEndpoints(): array
    {
        return array_keys(self::WRITE_ENDPOINTS);
    }

    private function waitBeforeRetry(Response $response, int $attempt): void
    {
        $seconds = min(5, max(0, (int) $response->header('Retry-After')));
        usleep($seconds > 0 ? $seconds * 1_000_000 : $attempt * 100_000);
    }

    private function errorCode(int $status, mixed $message): string
    {
        $text = strtolower(is_string($message) ? $message : '');

        return match (true) {
            $status === 400 && str_contains($text, 'obsolete method') => 'obsolete_method',
            $status === 401 && str_contains($text, 'client') => 'invalid_client_id',
            $status === 401 && (str_contains($text, 'api-key') || str_contains($text, 'api key')) => 'invalid_api_key',
            $status === 401 => 'invalid_credentials',
            $status === 403 => 'insufficient_permissions',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'ozon_unavailable',
            default => 'ozon_api_error',
        };
    }

    private function safeError(mixed $message, int $status, OzonAccount $account, string $endpoint, string $code): string
    {
        $text = is_string($message) && $message !== '' ? $message : 'Ozon API request failed.';
        $text = str_replace([(string) $account->api_key, (string) $account->client_id], ['[REDACTED]', '[REDACTED]'], $text);

        if ($code === 'obsolete_method') {
            $text = 'Ozon method is obsolete: POST '.$endpoint.'. The integration must be updated.';
        }

        return str($text)->limit(500).' (HTTP '.$status.')';
    }

    private function redact(array $value, ?OzonAccount $account = null): array
    {
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['api-key', 'api_key', 'client-id', 'client_id', 'authorization'], true)) {
                $value[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $value[$key] = $this->redact($item, $account);
            } elseif (is_string($item) && $account) {
                $value[$key] = str_replace([(string) $account->api_key, (string) $account->client_id], ['[REDACTED]', '[REDACTED]'], $item);
            }
        }

        return $value;
    }

    private function taxonomyRequestMetadata(array $payload): array
    {
        $allowed = [
            'description_category_id', 'type_id', 'attribute_id', 'language',
            'limit', 'last_value_id', 'cursor',
        ];

        return [
            'logging_policy' => 'taxonomy_compact',
            'identifiers' => collect($payload)->only($allowed)->map(
                fn (mixed $value) => is_scalar($value) || $value === null ? $value : '[omitted]',
            )->all(),
        ];
    }

    private function taxonomyResponseMetadata(array $body, bool $successful, OzonAccount $account): array
    {
        if ($successful) {
            $result = $body['result'] ?? null;

            return [
                'logging_policy' => 'taxonomy_compact',
                'payload_omitted' => true,
                'result_count' => is_array($result) ? count($result) : null,
                'last_value_id' => isset($body['last_value_id']) && is_scalar($body['last_value_id'])
                    ? $body['last_value_id']
                    : null,
            ];
        }

        $safe = $this->redact($body, $account);
        $encoded = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $encoded = is_string($encoded) ? $encoded : '{}';
        $truncated = strlen($encoded) > self::TAXONOMY_ERROR_BODY_LIMIT;

        return [
            'logging_policy' => 'taxonomy_bounded_error',
            'truncated' => $truncated,
            'original_bytes' => strlen($encoded),
            'body' => $truncated ? mb_strcut($encoded, 0, self::TAXONOMY_ERROR_BODY_LIMIT, 'UTF-8') : $safe,
        ];
    }
}
