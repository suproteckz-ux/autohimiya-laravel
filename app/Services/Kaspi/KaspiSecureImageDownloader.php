<?php

namespace App\Services\Kaspi;

use App\Models\Product;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class KaspiSecureImageDownloader
{
    private const MIME_EXTENSIONS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * @param array<int, string> $urls
     * @return array<string, array{tmp_path: string, final_path: string, extension: string}>
     */
    public function downloadAll(Product $product, array $urls, string $batchId): array
    {
        $downloads = [];

        try {
            foreach (array_values($urls) as $url) {
                $downloads[$url] = $this->download($product, (string) $url, $batchId);
            }

            return $downloads;
        } catch (\Throwable $exception) {
            $this->cleanup($downloads);

            throw $exception;
        }
    }

    /**
     * @param array<string, array{tmp_path: string, final_path: string, extension: string}> $downloads
     */
    public function cleanup(array $downloads): void
    {
        foreach ($downloads as $download) {
            foreach ([$download['tmp_path'] ?? null, $download['final_path'] ?? null] as $path) {
                if (is_string($path) && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
        }
    }

    /**
     * @param array{tmp_path: string, final_path: string, extension: string} $download
     */
    public function promote(array $download): string
    {
        $disk = Storage::disk('public');
        $tmpPath = $download['tmp_path'];
        $finalPath = $download['final_path'];

        if (! $disk->exists($tmpPath)) {
            throw new \RuntimeException('Secure image temporary file is missing.');
        }

        if ($disk->exists($finalPath)) {
            $disk->delete($tmpPath);

            return $finalPath;
        }

        $contents = $disk->get($tmpPath);
        $disk->put($finalPath, $contents);
        $disk->delete($tmpPath);

        return $finalPath;
    }

    /**
     * @return array{tmp_path: string, final_path: string, extension: string}
     */
    private function download(Product $product, string $url, string $batchId): array
    {
        $this->assertAllowedUrl($url);

        $response = Http::connectTimeout((int) config('services.kaspi.image_connect_timeout', 5))
            ->timeout((int) config('services.kaspi.image_timeout', 15))
            ->withOptions([
                'allow_redirects' => ['max' => 3, 'track_redirects' => true],
            ])
            ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
            ->get($url);

        $this->assertAllowedRedirects($response);

        if (! $response->successful()) {
            throw new \RuntimeException('image_http_'.$response->status());
        }

        $body = $response->body();
        $maxBytes = (int) config('services.kaspi.image_max_bytes', 5242880);
        if ($body === '' || strlen($body) > $maxBytes) {
            throw new \RuntimeException(strlen($body) > $maxBytes ? 'image_too_large' : 'image_empty');
        }

        $info = @getimagesizefromstring($body);
        $type = is_array($info) ? (int) ($info[2] ?? 0) : 0;
        if (! isset(self::MIME_EXTENSIONS[$type])) {
            throw new \RuntimeException('image_invalid_mime');
        }

        $extension = self::MIME_EXTENSIONS[$type];
        $safeBatch = preg_replace('/[^A-Za-z0-9_-]/', '', $batchId) ?: sha1($batchId);
        $hash = sha1($url);

        $tmpPath = 'products/kaspi/tmp/'.$safeBatch.'/'.$hash.'.'.$extension;
        $finalPath = 'products/kaspi/'.$product->id.'/'.$hash.'.'.$extension;
        Storage::disk('public')->put($tmpPath, $body);

        return ['tmp_path' => $tmpPath, 'final_path' => $finalPath, 'extension' => $extension];
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || blank($parts['host'] ?? null)) {
            throw new \RuntimeException('image_url_not_https');
        }

        $host = mb_strtolower((string) $parts['host']);
        if (! $this->isAllowedHost($host)) {
            throw new \RuntimeException('image_host_not_allowed');
        }

        if ($this->isPrivateHost($host)) {
            throw new \RuntimeException('image_private_host_blocked');
        }

        $path = mb_strtolower((string) ($parts['path'] ?? ''));
        if (preg_match('/\.(svg|php|html?)$/', $path)) {
            throw new \RuntimeException('image_extension_blocked');
        }
    }

    private function assertAllowedRedirects(Response $response): void
    {
        $history = array_filter(array_map('trim', explode(',', (string) $response->header('X-Guzzle-Redirect-History', ''))));
        $statusHistory = array_filter(array_map('trim', explode(',', (string) $response->header('X-Guzzle-Redirect-Status-History', ''))));

        foreach ($history as $redirectUrl) {
            $this->assertAllowedUrl($redirectUrl);
        }

        foreach ($statusHistory as $status) {
            if ((int) $status >= 300 && (int) $status < 400 && $history === []) {
                throw new \RuntimeException('image_redirect_target_missing');
            }
        }
    }

    private function isAllowedHost(string $host): bool
    {
        $allowed = (array) config('services.kaspi.image_allowed_hosts', ['resources.cdn-kaspi.kz', 'kaspi.kz']);

        foreach ($allowed as $allowedHost) {
            $allowedHost = mb_strtolower(trim((string) $allowedHost));
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateHost(string $host): bool
    {
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
