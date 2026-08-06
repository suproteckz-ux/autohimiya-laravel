<?php

namespace App\Services\Kaspi;

use App\Support\ProductSlugger;
use Illuminate\Support\Facades\Storage;
use JsonException;

class KaspiLocalUrlResolver
{
    public function __construct(private readonly ?KaspiLocalNodeProcessRunner $runner = null)
    {
    }

    public function resolve(string $sku, ?string $name = null, bool $debug = false, array $options = []): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new \RuntimeException('url_resolver_invalid_input');
        }

        if (filled($options['existing_url'] ?? null)) {
            return [
                'url' => $this->validatedProductUrl((string) $options['existing_url']),
                'status' => 'resolved',
                'method' => 'existing',
                'diagnostics' => [],
            ];
        }

        $notFound = [];
        foreach ($this->storefrontUrls($sku, (string) $name, $options) as $storefrontUrl) {
            $widget = $this->runResolver('kaspi-widget-resolver.mjs', [
                'url' => $storefrontUrl,
                'sku' => $sku,
                'headless' => true,
                'delay-ms' => 5000,
                'artifact-dir' => $this->artifactDirectory($sku, 'widget'),
                'debug' => $debug,
            ], 'widget', $debug);

            if (($widget['ok'] ?? false) === true) {
                return $this->resolved($widget);
            }

            if (($widget['reason'] ?? null) === 'not_found') {
                if ($this->storefrontNotFound($widget)) {
                    throw new \RuntimeException($this->exceptionMessage('storefront_product_url_invalid', (array) ($widget['process'] ?? []), null, $debug));
                }

                $notFound[] = $widget;
                continue;
            }

            throw new \RuntimeException($this->exceptionMessage('url_resolver_'.$widget['reason'], (array) ($widget['process'] ?? []), null, $debug));
        }

        $lastWidget = $notFound === [] ? [] : (array) $notFound[array_key_last($notFound)];
        $lastWidgetProcess = (array) ($lastWidget['process'] ?? []);

        throw new \RuntimeException($this->exceptionMessage('kaspi_product_missing', $lastWidgetProcess, null, $debug, ['widget_not_found_attempts' => count($notFound)]));
    }

    /**
     * @param array<string, string|int|bool|null> $arguments
     * @return array<string, mixed>
     */
    private function runResolver(string $scriptName, array $arguments, string $method, bool $debug): array
    {
        $script = base_path('scripts/'.$scriptName);
        if (! is_file($script)) {
            throw new \RuntimeException('url_resolver_script_missing');
        }

        $process = $this->processRunner()->run($script, $arguments, 90);
        $stdout = $this->stripUtf8Bom((string) $process['stdout']);
        $stderr = (string) $process['stderr'];

        if ($stdout === '') {
            throw new \RuntimeException($this->exceptionMessage('url_resolver_empty_stdout', $process, null, $debug));
        }

        try {
            $payload = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException($this->exceptionMessage('url_resolver_invalid_json', $process, $exception->getMessage(), $debug));
        }

        if (! is_array($payload)) {
            throw new \RuntimeException($this->exceptionMessage('url_resolver_invalid_json', $process, 'Decoded JSON is not an object.', $debug));
        }

        $payload['process'] = $this->safeProcessDiagnostics($process, $stdout, $stderr, $debug);
        $payload['method'] = $payload['method'] ?? $method;

        $reason = (string) ($payload['reason'] ?? '');
        if (($process['exit_code'] ?? 0) !== 0 && ($payload['ok'] ?? false) !== true) {
            if ($reason === 'browser_error') {
                return $payload;
            }

            throw new \RuntimeException($this->exceptionMessage('url_resolver_nonzero_exit', $process, null, $debug));
        }

        if (($payload['ok'] ?? false) === true) {
            $payload['url'] = $this->validatedProductUrl((string) ($payload['url'] ?? $payload['resolved_kaspi_url'] ?? ''));

            if (($payload['method'] ?? $method) !== 'widget') {
                $this->assertSkuMatchesUrl((string) ($payload['sku'] ?? ''), $payload['url']);
            }

            return $payload;
        }

        if (($payload['ok'] ?? false) !== false || ! is_string($payload['reason'] ?? null)) {
            throw new \RuntimeException($this->exceptionMessage('url_resolver_invalid_json', $process, 'Resolver JSON schema is invalid.', $debug));
        }

        return $payload;
    }

    private function processRunner(): KaspiLocalNodeProcessRunner
    {
        return $this->runner ?: app(KaspiLocalNodeProcessRunner::class);
    }

    /**
     * @return array<int, string>
     */
    private function storefrontUrls(string $sku, string $name, array $options): array
    {
        $urls = [];
        if (filled($options['storefront_url'] ?? null)) {
            return [(string) $options['storefront_url']];
        }

        $base = rtrim((string) config('app.url'), '/');
        if ($base !== '') {
            if (filled($options['slug'] ?? null)) {
                $slug = trim((string) $options['slug'], '/');

                return collect($this->baseUrlVariants($base))
                    ->map(fn (string $baseUrl): string => $baseUrl.'/product/'.$slug)
                    ->unique()
                    ->values()
                    ->all();
            }

            $slugs = array_values(array_unique(array_filter([
                ProductSlugger::fromName($name, $sku),
                ProductSlugger::normalizeSlug($sku),
            ])));

            foreach ($this->baseUrlVariants($base) as $baseUrl) {
                foreach ($slugs as $slug) {
                    $urls[] = $baseUrl.'/product/'.$slug;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<int, string>
     */
    private function baseUrlVariants(string $base): array
    {
        $variants = [$base];
        $parts = parse_url($base);
        $host = (string) ($parts['host'] ?? '');
        if ($host !== '' && ! str_starts_with($host, 'www.') && ! $this->isLoopbackOrIpHost($host)) {
            $scheme = (string) ($parts['scheme'] ?? 'https');
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $variants[] = $scheme.'://www.'.$host.$port;
        }

        return array_values(array_unique($variants));
    }

    private function isLoopbackOrIpHost(string $host): bool
    {
        $host = mb_strtolower(trim($host, '[]'));

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function storefrontNotFound(array $payload): bool
    {
        $status = (int) ($payload['http_status'] ?? $payload['navigation_http_status'] ?? 0);

        return $status === 404;
    }

    private function resolved(array $payload): array
    {
        return [
            'url' => (string) $payload['url'],
            'status' => 'resolved',
            'method' => (string) ($payload['method'] ?? 'unknown'),
            'reason' => null,
            'diagnostics' => $payload['process'] ?? [],
        ];
    }

    private function validatedProductUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? '');
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || ($host !== 'kaspi.kz' && ! str_ends_with($host, '.kaspi.kz')) || ! str_starts_with($path, '/shop/p/')) {
            throw new \RuntimeException('url_resolver_invalid_url');
        }

        $path = rtrim($path, '/').'/';

        return 'https://'.$host.$path;
    }

    private function assertSkuMatchesUrl(string $sku, string $url): void
    {
        if ($sku === '') {
            return;
        }

        $needle = $this->skuKey($sku);
        $haystack = $this->skuKey((string) (parse_url($url, PHP_URL_PATH) ?: $url));
        if ($needle !== '' && ! str_contains($haystack, $needle)) {
            throw new \RuntimeException('url_resolver_sku_mismatch');
        }
    }

    private function skuKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($value)) ?: '';
    }

    private function artifactDirectory(string $sku, string $method): string
    {
        $safeSku = preg_replace('/[^A-Za-z0-9_-]+/', '-', $sku) ?: 'sku';

        return Storage::path('kaspi-production-push/resolve-'.$method.'-'.now()->format('Ymd-His').'-'.$safeSku);
    }

    private function stripUtf8Bom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    /**
     * @param array{command?: array<int, string>, script?: string, cwd?: string, exit_code?: int|null, stdout?: string, stderr?: string} $process
     */
    private function exceptionMessage(string $code, array $process, ?string $jsonError = null, bool $debug = false, array $extra = []): string
    {
        if (! $debug) {
            return $code;
        }

        $diagnostics = $this->safeProcessDiagnostics($process, (string) ($process['stdout'] ?? ''), (string) ($process['stderr'] ?? ''), $debug);
        if ($jsonError !== null) {
            $diagnostics['json_error'] = $jsonError;
        }

        $diagnostics += $extra;

        return $code.': '.json_encode($diagnostics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array{command?: array<int, string>, script?: string, cwd?: string, exit_code?: int|null, stdout?: string, stderr?: string} $process
     * @return array<string, mixed>
     */
    private function safeProcessDiagnostics(array $process, string $stdout, string $stderr, bool $includeFullOutput = false): array
    {
        $diagnostics = [
            'command' => $process['command'] ?? [],
            'script' => $process['script'] ?? null,
            'cwd' => $process['cwd'] ?? null,
            'exit_code' => $process['exit_code'] ?? null,
            'stdout_bytes' => strlen($stdout),
            'stderr_bytes' => strlen($stderr),
            'stdout_empty' => $stdout === '',
            'stdout_preview' => $this->preview($stdout, 300),
            'stderr_preview' => $this->preview($stderr, 500),
        ];

        if ($includeFullOutput) {
            $diagnostics['stdout'] = $this->sanitize($stdout);
            $diagnostics['stderr'] = $this->sanitize($stderr);
        }

        return $diagnostics;
    }

    private function preview(string $value, int $limit): string
    {
        return mb_substr($this->sanitize($value), 0, $limit);
    }

    private function sanitize(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $value) ?: $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $value) ?: $value;

        return $value;
    }
}
