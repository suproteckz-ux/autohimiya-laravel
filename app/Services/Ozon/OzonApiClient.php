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
    private const ENDPOINTS = ['/v1/warehouse/list', '/v1/description-category/tree', '/v1/description-category/attribute', '/v1/description-category/attribute/values'];

    public function post(OzonAccount $account, string $endpoint, array $payload, OzonOperationType $type, ?AutomationRun $run = null): array
    {
        if (! in_array($endpoint, self::ENDPOINTS, true)) {
            throw new OzonApiException('Ozon endpoint is not permitted by the read-only client.');
        }
        $operation = OzonOperation::query()->create(['ozon_account_id' => $account->id, 'automation_run_id' => $run?->id, 'operation_key' => (string) str()->uuid(), 'operation_type' => $type, 'status' => OzonOperationStatus::Running, 'endpoint' => $endpoint, 'request_payload' => $this->redact($payload), 'started_at' => now()]);
        $attempt = 0;
        try {
            do {
                $attempt++;
                try {
                    $response = Http::baseUrl(self::BASE_URL)->acceptJson()->asJson()
                        ->withHeaders(['Client-Id' => $account->client_id, 'Api-Key' => $account->api_key])
                        ->connectTimeout(5)->timeout(20)->post($endpoint, $payload);
                } catch (ConnectionException) {
                    if ($attempt < 3) { usleep(100_000 * $attempt); continue; }
                    throw new OzonApiException('Ozon API connection timed out.', null, 'timeout', true);
                }
                if (($response->status() === 429 || $response->serverError()) && $attempt < 3) { $this->waitBeforeRetry($response, $attempt); continue; }
                $body = is_array($response->json()) ? $response->json() : [];
                $businessError = $body['message'] ?? $body['error']['message'] ?? null;
                $businessSuccess = $response->successful() && ! filled($businessError);
                $safeError=$this->safeError($businessError,$response->status(),$account);
                $operation->update(['status' => $businessSuccess ? OzonOperationStatus::Completed : OzonOperationStatus::Failed, 'response_payload' => $this->redact($body,$account), 'http_status' => $response->status(), 'request_id' => $response->header('x-request-id'), 'attempt' => $attempt, 'finished_at' => now(), 'error_message' => $businessSuccess ? null : $safeError]);
                if (! $businessSuccess) {
                    throw new OzonApiException($safeError, $response->status(), (string) ($body['code'] ?? $body['error']['code'] ?? ''), $response->status() === 429 || $response->serverError());
                }
                return $body;
            } while ($attempt < 3);
        } catch (Throwable $exception) {
            $operation->refresh();
            if (! $operation->status->isFinished()) { $operation->update(['status' => OzonOperationStatus::Failed, 'attempt' => $attempt, 'finished_at' => now(), 'error_message' => $exception instanceof OzonApiException ? $exception->getMessage() : 'Unexpected Ozon API error.']); }
            throw $exception;
        }
        throw new OzonApiException('Ozon API retry limit reached.', null, null, true);
    }

    public static function allowedEndpoints(): array { return self::ENDPOINTS; }
    private function waitBeforeRetry(Response $response, int $attempt): void { $seconds = min(5, max(0, (int) $response->header('Retry-After'))); usleep($seconds > 0 ? $seconds * 1_000_000 : $attempt * 100_000); }
    private function safeError(mixed $message, int $status, OzonAccount $account): string { $text=is_string($message)&&$message!==''?$message:'Ozon API request failed.'; $text=str_replace([(string)$account->api_key,(string)$account->client_id],['[REDACTED]','[REDACTED]'],$text); return str($text)->limit(500).' (HTTP '.$status.')'; }
    private function redact(array $value, ?OzonAccount $account=null): array { foreach ($value as $key => $item) { if (in_array(strtolower((string) $key), ['api-key','api_key','client-id','client_id','authorization'],true)) { $value[$key]='[REDACTED]'; } elseif(is_array($item)) { $value[$key]=$this->redact($item,$account); } elseif(is_string($item)&&$account) { $value[$key]=str_replace([(string)$account->api_key,(string)$account->client_id],['[REDACTED]','[REDACTED]'],$item); } } return $value; }
}
