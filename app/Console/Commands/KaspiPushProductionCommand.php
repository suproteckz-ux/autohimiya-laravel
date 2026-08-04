<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiProductionBridgeService;
use Illuminate\Console\Command;

class KaspiPushProductionCommand extends Command
{
    protected $signature = 'kaspi:push-production
        {--sku=* : SKU to collect and push}
        {--limit= : Maximum products to process}
        {--dry-run : Collect and validate without sending}
        {--force : Recollect/resend even when a previous success exists}
        {--debug : Print safe diagnostics}';

    protected $description = 'Collect Kaspi content locally with Playwright and push it to production over HTTPS.';

    public function handle(KaspiProductionBridgeService $service): int
    {
        try {
            $result = $service->push([
                'sku' => $this->option('sku'),
                'limit' => $this->option('limit'),
                'dry_run' => (bool) $this->option('dry-run'),
                'force' => (bool) $this->option('force'),
                'debug' => (bool) $this->option('debug'),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['SKU', 'Candidate', 'Kaspi URL', 'Images', 'Description', 'Attributes', 'Payload', 'Status', 'Message', 'Request ID'], array_map(
            fn (array $row): array => [
                $row['sku'],
                $row['candidate_status'] ?? '',
                $row['kaspi_url'] ?? '',
                $row['image_count'] ?? 0,
                ($row['description_present'] ?? false) ? 'yes' : 'no',
                $row['attribute_count'] ?? 0,
                ($row['payload_valid'] ?? false) ? 'valid' : 'invalid',
                $row['status'],
                $row['message'],
                $row['request_id'],
            ],
            $result['rows'],
        ));
        $this->table(['Metric', 'Count'], collect($result['metrics'])->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all());
        $this->printCandidateDiagnostics((array) ($result['candidate_diagnostics'] ?? []));
        $this->info($result['message']);

        return $result['successful'] ? self::SUCCESS : self::FAILURE;
    }

    private function printCandidateDiagnostics(array $diagnostics): void
    {
        if ($diagnostics === []) {
            return;
        }

        $this->line('Candidate diagnostics');
        $this->line('Total products: '.(int) ($diagnostics['total_products'] ?? 0));
        $this->table(['Filter', 'Rejected'], collect((array) ($diagnostics['rejected'] ?? []))
            ->map(fn (int $count, string $filter): array => [$filter, $count])
            ->values()
            ->all());

        foreach ((array) ($diagnostics['requested_skus'] ?? []) as $skuDiagnostic) {
            if (! is_array($skuDiagnostic)) {
                continue;
            }

            $this->line('SKU: '.($skuDiagnostic['sku'] ?? ''));
            $this->line('manual_content_protected = '.$this->formatDiagnosticValue($skuDiagnostic['manual_content_protected'] ?? null));
            $this->line('has_images = '.$this->formatDiagnosticValue($skuDiagnostic['has_images'] ?? null));
            $this->line('has_description = '.$this->formatDiagnosticValue($skuDiagnostic['has_description'] ?? null));
            $this->line('has_attributes = '.$this->formatDiagnosticValue($skuDiagnostic['has_attributes'] ?? null));
            $this->line('kaspi_url = '.($skuDiagnostic['kaspi_url'] ?? 'missing'));
            $this->line('excluded because '.$skuDiagnostic['excluded_because']);
        }
    }

    private function formatDiagnosticValue(mixed $value): string
    {
        return match ($value) {
            true => 'true',
            false => 'false',
            default => 'unknown',
        };
    }
}
