<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CatalogAuditStorefrontUrlsCommand extends Command
{
    protected $signature = 'catalog:audit-storefront-urls
        {--live-check : Also make HTTP GET to each URL — requires php artisan serve running}
        {--show-all : Print full table for every product, not only 404 / error rows}
        {--limit= : Audit only first N products (useful for testing)}
        {--chunk=250 : DB chunk size}';

    protected $description = 'Audit storefront URLs for all products and report which ones return 404.';

    public function handle(): int
    {
        $liveCheck = (bool) $this->option('live-check');
        $showAll = (bool) $this->option('show-all');
        $limit = $this->option('limit') ? max(1, (int) $this->option('limit')) : null;
        $chunkSize = max(1, (int) ($this->option('chunk') ?: 250));
        $appUrl = rtrim(config('app.url'), '/');

        $this->line('Auditing storefront URLs...');
        if ($liveCheck) {
            $this->warn('  Live HTTP check ON — make sure php artisan serve is running at '.$appUrl);
        } else {
            $this->line('  DB-only check (mirrors ProductController logic). Use --live-check to verify with HTTP.');
        }
        $this->newLine();

        $total = 0;
        $countOk = 0;
        $count404 = 0;
        $countError = 0;
        $detailRows = [];   // for --show-all
        $rows404 = [];      // always collected

        $query = Product::query()
            ->select(['id', 'sku', 'name', 'slug', 'product_status', 'availability', 'quantity', 'price', 'category_id'])
            ->orderBy('id');

        if ($limit) {
            $query->limit($limit);
        }

        $query->chunk($chunkSize, function ($products) use (
            $appUrl, $liveCheck, $showAll,
            &$total, &$countOk, &$count404, &$countError, &$detailRows, &$rows404,
        ) {
            foreach ($products as $product) {
                $total++;
                $slug = (string) ($product->slug ?? '');
                $expectedUrl = $slug !== '' ? $appUrl.'/product/'.$slug : null;

                // Replicate ProductController::show() visibility rule.
                if ($slug === '') {
                    $status = '404';
                    $reason = 'no_slug';
                } elseif (! $product->isAvailableForStorefront()) {
                    $status = '404';
                    $reason = 'not_visible';
                } else {
                    $status = 'ok';
                    $reason = '';
                }

                // Optional live HTTP verification (only for products that pass DB check)
                if ($liveCheck && $status === 'ok' && $expectedUrl !== null) {
                    try {
                        $httpStatus = Http::timeout(5)->get($expectedUrl)->status();
                        if ($httpStatus === 200) {
                            $status = 'ok';
                        } elseif ($httpStatus === 404) {
                            $status = '404';
                            $reason = 'http:404';
                        } else {
                            $status = 'error';
                            $reason = 'http:'.$httpStatus;
                        }
                    } catch (\Throwable) {
                        $status = 'error';
                        $reason = 'http:connection_failed';
                    }
                }

                if ($status === 'ok') {
                    $countOk++;
                } elseif ($status === '404') {
                    $count404++;
                } else {
                    $countError++;
                }

                $row = [
                    $product->id,
                    $product->sku ?? '—',
                    mb_strimwidth((string) $product->name, 0, 50, '…'),
                    $slug ?: '—',
                    $expectedUrl ?? '—',
                    $status,
                    $reason,
                ];

                if ($showAll) {
                    $detailRows[] = $row;
                }

                if ($status !== 'ok') {
                    $rows404[] = $row;
                }
            }
        });

        $headers = ['product_id', 'sku', 'name', 'slug', 'expected_url', 'status', 'reason'];

        // Full table (--show-all)
        if ($showAll && $detailRows !== []) {
            $this->table($headers, $detailRows);
            $this->newLine();
        }

        // Summary
        $this->table(['Metric', 'Count'], [
            ['Total products', $total],
            ['OK (reachable)', $countOk],
            ['404', $count404],
            ['Error (HTTP / connection)', $countError],
        ]);

        // 404 / error detail — always shown when non-empty
        if ($rows404 !== []) {
            $this->newLine();
            $this->error(sprintf('%d product(s) return 404 or error:', count($rows404)));
            $this->table($headers, $rows404);
        } else {
            $this->newLine();
            $this->info('No 404s found — all products are reachable.');
        }

        return $count404 > 0 || $countError > 0 ? self::FAILURE : self::SUCCESS;
    }
}
