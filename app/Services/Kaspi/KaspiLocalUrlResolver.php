<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class KaspiLocalUrlResolver
{
    public function resolve(string $sku, ?string $name = null, bool $debug = false): array
    {
        $script = base_path('scripts/kaspi-search-url-resolver.mjs');
        if (! is_file($script)) {
            throw new \RuntimeException('url_resolver_script_missing');
        }

        $artifactDir = Storage::path('kaspi-production-push/resolve-'.now()->format('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $sku));
        $process = new Process([
            'node',
            $script,
            '--sku='.$sku,
            '--name='.(string) $name,
            '--headless=true',
            '--artifact-dir='.$artifactDir,
        ], base_path(), null, null, 90);
        $process->run();

        $result = json_decode(trim($process->getOutput()), true);
        if (! is_array($result)) {
            throw new \RuntimeException('url_resolver_invalid_json');
        }

        if (($result['status'] ?? null) !== 'resolved' || blank($result['url'] ?? null)) {
            throw new \RuntimeException((string) ($result['error'] ?? $result['status'] ?? 'url_not_resolved'));
        }

        return [
            'url' => (string) $result['url'],
            'status' => (string) $result['status'],
            'artifact_dir' => $debug ? $artifactDir : null,
        ];
    }
}
