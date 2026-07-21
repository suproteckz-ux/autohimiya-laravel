<?php

namespace App\Services\Kaspi;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Support\ContentScore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class KaspiWidgetBrowserResolver
{
    public function __construct(private readonly KaspiEnrichmentParser $parser)
    {
    }

    public function resolve(Product $product, array $options = []): array
    {
        $started = microtime(true);
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $headless = (bool) ($options['headless'] ?? false);
        $delayMs = (int) ($options['delay_ms'] ?? 5000);
        $fetchContent = (bool) ($options['fetch_content'] ?? false);

        if (! $product->canShowKaspiCreditButton()) {
            return $this->result($product, null, false, false, 'widget_not_found', 'Kaspi button is not rendered for this product.', $started);
        }

        $productUrl = route('products.show', $product->slug);

        if (filled($product->kaspi_product_url)) {
            if (! $dryRun) {
                $this->saveResolvedUrl($product, $product->kaspi_product_url, 'product.kaspi_product_url', $fetchContent);
            }

            return $this->result($product, $product->kaspi_product_url, true, true, 'resolved_from_widget', null, $started, $productUrl);
        }

        $script = base_path('scripts/kaspi-widget-resolver.mjs');
        if (! is_file($script)) {
            return $this->result($product, null, false, false, 'error', 'Playwright resolver script is missing.', $started, $productUrl);
        }

        $artifactDir = $this->artifactDirectory($product);

        $payload = null;
        $attempts = [];
        $lastError = '';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $process = new Process([
                'node',
                $script,
                '--url='.$productUrl,
                '--headless='.($headless ? 'true' : 'false'),
                '--delay-ms='.$delayMs,
                '--artifact-dir='.$artifactDir,
            ], base_path(), null, null, max(60, (int) ceil(($delayMs / 1000) + 60)));

            try {
                $process->run();
            } catch (Throwable $exception) {
                return $this->markNeedsManualUrl($product, $dryRun, $productUrl, 'error', $this->friendlyProcessError($exception->getMessage()), $started, false, false, $artifactDir, [
                    'current_step' => 'spawn_node_process',
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'page_url' => null,
                    'http_status' => null,
                    'timeout' => false,
                    'captcha' => false,
                ]);
            }

            $attemptPayload = json_decode(trim($process->getOutput()), true);
            if (! is_array($attemptPayload)) {
                $lastError = $this->friendlyProcessError(trim($process->getErrorOutput()));

                return $this->markNeedsManualUrl($product, $dryRun, $productUrl, 'error', $lastError, $started, false, false, $artifactDir, [
                    'current_step' => 'parse_node_payload',
                    'exception_class' => null,
                    'exception_message' => trim($process->getErrorOutput()) ?: 'Resolver did not return valid JSON.',
                    'page_url' => null,
                    'http_status' => null,
                    'timeout' => false,
                    'captcha' => false,
                ]);
            }

            $attemptPayload['attempt'] = $attempt;
            $attempts[] = [
                'attempt' => $attempt,
                'status' => $attemptPayload['status'] ?? null,
                'widget_found' => (bool) ($attemptPayload['widget_found'] ?? false),
                'button_found' => (bool) ($attemptPayload['button_found'] ?? false),
                'resolved_kaspi_url' => $attemptPayload['resolved_kaspi_url'] ?? null,
                'page_url' => $attemptPayload['page_url'] ?? null,
                'error' => $attemptPayload['error'] ?? null,
            ];

            $payload = $attemptPayload;
            $url = $this->canonicalKaspiUrl($attemptPayload['resolved_kaspi_url'] ?? null);
            $status = (string) ($attemptPayload['status'] ?? 'error');
            $widgetFound = (bool) ($attemptPayload['widget_found'] ?? false);
            $buttonFound = (bool) ($attemptPayload['button_found'] ?? false);
            $captcha = (bool) ($attemptPayload['captcha'] ?? false);

            if ($status === 'resolved_from_widget' && filled($url)) {
                break;
            }

            if (! $widgetFound || ! $buttonFound || $captcha || ! in_array($status, ['kaspi_url_not_opened', 'invalid_kaspi_url', 'error'], true)) {
                break;
            }

            if ($attempt < 3) {
                usleep(max(500, $delayMs) * 1000);
            }
        }

        $payload ??= ['status' => 'error', 'error' => $lastError ?: 'Resolver did not return payload.'];
        $payload['attempts'] = $attempts;

        $status = (string) ($payload['status'] ?? 'error');
        $url = $this->canonicalKaspiUrl($payload['resolved_kaspi_url'] ?? null);
        $widgetFound = (bool) ($payload['widget_found'] ?? false);
        $buttonFound = (bool) ($payload['button_found'] ?? false);
        $error = $this->friendlyProcessError((string) ($payload['error'] ?? ''));
        if ($status === 'error' && str_starts_with($error, 'process_error')) {
            $status = 'process_error';
        }

        if ($status === 'resolved_from_widget' && filled($url)) {
            if (! $dryRun) {
                $this->saveResolvedUrl($product, $url, 'widget_browser', $fetchContent);
            }

            return $this->result($product, $url, $widgetFound, $buttonFound, 'resolved_from_widget', null, $started, $productUrl, $payload['artifact_dir'] ?? $artifactDir, $payload);
        }

        return $this->markNeedsManualUrl($product, $dryRun, $productUrl, $status, $error ?: $this->friendlyStatusMessage($status), $started, $widgetFound, $buttonFound, $payload['artifact_dir'] ?? $artifactDir, $payload);
    }

    private function saveResolvedUrl(Product $product, string $url, string $source, bool $fetchContent): void
    {
        $product->update(['kaspi_product_url' => $url]);

        $task = KaspiEnrichmentTask::query()->updateOrCreate([
            'product_id' => $product->id,
            'kaspi_merchant_sku' => $product->sku,
        ], [
            'kaspi_product_url' => $url,
            'missing_photo' => ! ContentScore::hasPhoto($product),
            'missing_description' => blank($product->description),
            'missing_attributes' => ! $product->attributes()->exists(),
            'status' => 'resolved_from_widget',
            'source' => $source,
            'finished_at' => now(),
            'error' => null,
        ]);

        if ($fetchContent) {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
                ->get($url);
            $payload = $this->parser->parse($response->body(), $url);

            $task->update([
                'status' => 'draft',
                'parsed_title' => ['value' => $payload['name'] ?? null],
                'parsed_images' => $payload['images'] ?? [],
                'parsed_description' => $payload['description'] ?? null,
                'parsed_attributes' => $payload['attributes'] ?? [],
                'parsed_brand' => $payload['brand'] ?? null,
                'parsed_category' => $payload['category'] ?? null,
                'raw_payload' => $payload,
                'finished_at' => now(),
                'error' => null,
            ]);
        }
    }

    private function markNeedsManualUrl(Product $product, bool $dryRun, string $productUrl, string $status, ?string $error, float $started, bool $widgetFound = false, bool $buttonFound = false, ?string $artifactDir = null, array $diagnostics = []): array
    {
        $safeStatus = $this->safeStatus($status);
        $safeError = $this->friendlyStatusMessage($safeStatus, $error);

        if (! $dryRun) {
            KaspiEnrichmentTask::query()->updateOrCreate([
                'product_id' => $product->id,
                'kaspi_merchant_sku' => $product->sku,
            ], [
                'kaspi_product_url' => null,
                'missing_photo' => ! ContentScore::hasPhoto($product),
                'missing_description' => blank($product->description),
                'missing_attributes' => ! $product->attributes()->exists(),
                'status' => $safeStatus,
                'source' => 'widget_browser',
                'finished_at' => now(),
                'error' => trim($safeError.($artifactDir ? ' Diagnostics: '.$artifactDir : '')),
            ]);
        }

        return $this->result($product, null, $widgetFound, $buttonFound, $safeStatus, $safeError, $started, $productUrl, $artifactDir, $diagnostics);
    }

    private function result(Product $product, ?string $url, bool $widgetFound, bool $buttonFound, string $status, ?string $error, float $started, ?string $productUrl = null, ?string $artifactDir = null, array $diagnostics = []): array
    {
        $sku = (string) $product->sku;

        return [
            'product_id' => $product->id,
            'sku' => $sku,
            'kaspi_search_url' => $sku !== '' ? 'https://kaspi.kz/shop/search/?text='.rawurlencode($sku) : null,
            'product_url' => $productUrl ?: route('products.show', $product->slug),
            'widget_found' => $widgetFound ? 'yes' : 'no',
            'button_found' => $buttonFound ? 'yes' : 'no',
            'resolved_kaspi_url' => $url,
            'status' => $status,
            'error' => $error,
            'current_step' => $diagnostics['current_step'] ?? null,
            'exception_class' => $diagnostics['exception_class'] ?? null,
            'exception_message' => $diagnostics['exception_message'] ?? ($error ?: null),
            'playwright_page_url' => $diagnostics['page_url'] ?? null,
            'http_status' => $diagnostics['http_status'] ?? null,
            'timeout' => (bool) ($diagnostics['timeout'] ?? $status === 'widget_timeout'),
            'captcha' => (bool) ($diagnostics['captcha'] ?? false),
            'artifact_dir' => $artifactDir,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    private function canonicalKaspiUrl(?string $url): ?string
    {
        if (blank($url) || ! str_contains($url, 'kaspi.kz/shop/p/')) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        $path = $parts['path'];
        if (! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return $parts['scheme'].'://'.$parts['host'].$path;
    }

    private function friendlyProcessError(string $stderr): string
    {
        if (str_contains($stderr, 'std::shared_ptr')) {
            return 'Playwright browser did not start. Restart Laragon/Node and try again.';
        }

        if (str_contains($stderr, 'ERR_MODULE_NOT_FOUND') && str_contains($stderr, 'playwright')) {
            return 'Playwright is not installed. Run: npm install, then npm run playwright:install.';
        }

        if (blank($stderr)) {
            return 'Playwright resolver did not return JSON. Make sure Node.js and Playwright are installed.';
        }

        return strtok($stderr, "\r\n") ?: $stderr;
    }

    private function artifactDirectory(Product $product): string
    {
        $folder = 'kaspi-resolver/'.now()->format('Ymd-His').'-'.$product->id.'-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $product->sku);

        return Storage::path($folder);
    }

    private function safeStatus(string $status): string
    {
        return match ($status) {
            'widget_not_found',
            'widget_timeout',
            'kaspi_js_not_loaded',
            'kaspi_button_not_found',
            'kaspi_url_not_opened',
            'invalid_kaspi_url',
            'error',
            'failed' => $status,
            default => 'needs_manual_url',
        };
    }

    private function friendlyStatusMessage(string $status, ?string $fallback = null): string
    {
        return match ($status) {
            'widget_not_found' => 'Widget not found.',
            'widget_timeout' => 'Widget did not load in time.',
            'kaspi_js_not_loaded' => 'Kaspi JS not loaded.',
            'kaspi_button_not_found' => 'Kaspi button not found.',
            'kaspi_url_not_opened' => 'Kaspi URL was not received after click.',
            'invalid_kaspi_url' => 'Kaspi URL is invalid.',
            'error', 'failed' => $fallback ? $this->friendlyProcessError($fallback) : 'Resolver failed.',
            default => $fallback ?: 'Kaspi URL needs manual confirmation.',
        };
    }
}
