<?php

namespace App\Services\Ozon;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Exceptions\OzonApiException;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class OzonApiClient
{
    public const BASE_URL = 'https://api-seller.ozon.ru';

    private const ENDPOINTS = [
        '/v1/seller/info' => 'POST',
        '/v2/warehouse/list' => 'POST',
        '/v1/description-category/tree' => 'POST',
        '/v1/description-category/attribute' => 'POST',
        '/v1/description-category/attribute/values' => 'POST',
    ];

    public function post(OzonAccount $account, string $endpoint, array $payload, OzonOperationType $type, ?AutomationRun $run = null): array
    {
        return $this->request($account, $endpoint, $payload, $type, $run, false);
    }

    public function postEmptyJsonObject(OzonAccount $account, string $endpoint, OzonOperationType $type, ?AutomationRun $run = null): array
    {
        return $this->request($account, $endpoint, [], $type, $run, true);
    }

    private function request(OzonAccount $account, string $endpoint, array $payload, OzonOperationType $type, ?AutomationRun $run, bool $emptyJsonObject): array
    {
        $method = 'POST';
        if ((self::ENDPOINTS[$endpoint] ?? null) !== $method) {
            throw new OzonApiException('Ozon endpoint is not permitted by the read-only client.');
        }

        $operation = OzonOperation::query()->create([
            'ozon_account_id' => $account->id,
            'automation_run_id' => $run?->id,
            'operation_key' => (string) str()->uuid(),
            'operation_type' => $type,
            'status' => OzonOperationStatus::Running,
            'endpoint' => $endpoint,
            'http_method' => $method,
            'request_payload' => $this->redact($payload),
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
                    'response_payload' => $this->redact($body, $account),
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
        return array_keys(self::ENDPOINTS);
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
}
