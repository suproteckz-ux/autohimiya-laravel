<?php

namespace App\Services\Kaspi;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class KaspiLocalPageCollector
{
    public function __construct(private readonly KaspiEnrichmentParser $parser)
    {
    }

    public function collect(Product $product, bool $debug = false): array
    {
        return $this->collectUrl((string) $product->kaspi_product_url, (string) $product->sku, $debug);
    }

    public function collectUrl(string $url, string $sku, bool $debug = false): array
    {
        if (blank($url)) {
            throw new \RuntimeException('kaspi_url_missing');
        }

        $script = base_path('scripts/kaspi-product-page-collector.mjs');
        if (! is_file($script)) {
            throw new \RuntimeException('collector_script_missing');
        }

        $artifactDir = Storage::path('kaspi-production-push/'.now()->format('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $sku));
        $process = new Process([
            'node',
            $script,
            '--url='.$url,
            '--headless=true',
            '--artifact-dir='.$artifactDir,
        ], base_path(), null, null, 90);
        $process->run();

        $result = json_decode(trim($process->getOutput()), true);
        if (! is_array($result)) {
            throw new \RuntimeException('collector_invalid_json');
        }

        if (($result['status'] ?? null) !== 'ok') {
            throw new \RuntimeException((string) ($result['error'] ?? $result['status'] ?? 'collector_failed'));
        }

        $htmlPath = (string) ($result['html_path'] ?? '');
        if (! is_file($htmlPath)) {
            throw new \RuntimeException('collector_html_missing');
        }

        $payload = $this->parser->parse((string) file_get_contents($htmlPath), (string) ($result['final_url'] ?? $url));

        return [
            'url' => (string) ($result['final_url'] ?? $url),
            'http_status' => $result['http_status'] ?? null,
            'captcha' => (bool) ($result['captcha'] ?? false),
            'parser_payload' => $payload,
            'artifact_dir' => $debug ? $artifactDir : null,
        ];
    }
}
