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
        $this->info($result['message']);

        return $result['successful'] ? self::SUCCESS : self::FAILURE;
    }
}
