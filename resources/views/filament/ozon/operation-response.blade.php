<div class="max-h-[70vh] space-y-3 overflow-auto text-sm">
    <div><strong>HTTP status:</strong> {{ $operation?->http_status ?? '—' }}</div>
    <div><strong>Operation status:</strong> {{ $operation?->status?->label() ?? '—' }}</div>
    <div><strong>Task ID:</strong> {{ $record->ozon_task_id ?? '—' }}</div>
    <div><strong>Error code:</strong> {{ $operation?->error_code ?? '—' }}</div>
    <div><strong>Error message:</strong> {{ $operation?->error_message ?? $record->last_error ?? '—' }}</div>
    <div><strong>Response payload:</strong><pre class="mt-1 whitespace-pre-wrap">{{ json_encode($operation?->response_payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></div>
</div>
