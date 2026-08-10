<?php

namespace App\Services\Ozon;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Enums\OzonProductStatus;
use App\Exceptions\OzonApiException;
use App\Models\AutomationRun;
use App\Models\OzonOperation;
use App\Models\OzonProduct;
use Illuminate\Validation\ValidationException;
use Throwable;

class OzonProductExportService
{
    public function __construct(private readonly OzonApiClient $client, private readonly OzonProductPayloadBuilder $payloads) {}

    public function export(OzonProduct $product, AutomationRun $run): array
    {
        $product->loadMissing(['account', 'warehouse']);
        if (filled($product->ozon_task_id)) throw ValidationException::withMessages(['ozon_product' => 'Для товара уже сохранён task_id; повторная отправка заблокирована.']);
        if ($product->operations()->where('operation_type', OzonOperationType::ProductExport->value)->whereIn('status', [OzonOperationStatus::Pending->value, OzonOperationStatus::Running->value, OzonOperationStatus::Completed->value])->exists()) throw ValidationException::withMessages(['ozon_product' => 'Отправка товара уже поставлена или выполняется.']);
        $payload = $this->payloads->build($product);
        $product->update(['status' => OzonProductStatus::Sending, 'last_error' => null]);

        try {
            $response = $this->client->postProductImport($product->account, $product, $payload, $run);
            $taskId = data_get($response, 'result.task_id') ?? data_get($response, 'task_id');
            if (! filled($taskId)) throw new OzonApiException('Ozon product import returned HTTP success without task_id.', 200, 'missing_task_id');
            $product->update(['status' => OzonProductStatus::Processing, 'ozon_task_id' => (string) $taskId, 'last_response' => $response, 'last_exported_at' => now(), 'first_exported_at' => $product->first_exported_at ?: now()]);
            return ['successful' => true, 'task_id' => (string) $taskId];
        } catch (Throwable $exception) {
            $product->operations()->where('automation_run_id', $run->id)->latest()->first()?->update([
                'status' => OzonOperationStatus::Failed,
                'error_code' => $exception instanceof OzonApiException ? ($exception->errorCode ?: 'product_export_failed') : 'product_export_failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);
            $product->update(['status' => OzonProductStatus::Failed, 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }

    public function checkStatus(OzonProduct $product, AutomationRun $run): array
    {
        if (! filled($product->ozon_task_id)) throw ValidationException::withMessages(['ozon_product' => 'Task ID отсутствует.']);
        $response = $this->client->postProductImportStatus($product->account, $product, ['task_id' => (int) $product->ozon_task_id], $run);
        $items = data_get($response, 'result.items', []);
        $errors = collect(is_array($items) ? $items : [])->flatMap(fn ($item) => $item['errors'] ?? [])->values()->all();
        $failed = $errors !== [];
        $product->update(['status' => $failed ? OzonProductStatus::Failed : OzonProductStatus::Processing, 'last_error' => $failed ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null, 'last_response' => $response, 'last_status_checked_at' => now()]);
        return ['successful' => ! $failed, 'errors' => $errors];
    }
}
