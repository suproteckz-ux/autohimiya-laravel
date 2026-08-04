<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\KaspiBridgeSku;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class KaspiInspectImageCommand extends Command
{
    protected $signature = 'kaspi:inspect-image {--sku= : Product SKU to inspect}';

    protected $description = 'Read-only inspection of Kaspi-imported product image storage and public URLs.';

    public function handle(): int
    {
        $sku = trim((string) $this->option('sku'));
        if ($sku === '') {
            $this->error('Provide --sku=...');

            return self::FAILURE;
        }

        $this->printStorageEnvironment();

        $products = $this->productsForSku($sku);
        if ($products->isEmpty()) {
            $this->error('No product found for SKU '.$sku);

            return self::FAILURE;
        }

        foreach ($products as $product) {
            $this->newLine();
            $this->line('Product: id='.$product->id.' sku='.$product->sku.' slug='.$product->slug);

            $images = $product->images()
                ->where('source', 'kaspi')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            if ($images->isEmpty()) {
                $this->warn('No Kaspi-imported product_images rows found.');

                continue;
            }

            foreach ($images as $image) {
                $this->inspectImage($image);
            }
        }

        return self::SUCCESS;
    }

    private function printStorageEnvironment(): void
    {
        $publicStorage = public_path('storage');

        $this->line('Storage public root: '.Storage::disk('public')->path(''));
        $this->line("public_path('storage'): ".$publicStorage);
        $this->line("storage_path('app/public'): ".storage_path('app/public'));
        $this->line('is_link(public/storage): '.($this->bool(is_link($publicStorage))));
        $this->line('readlink(public/storage): '.$this->safeReadlink($publicStorage));
    }

    private function inspectImage(ProductImage $image): void
    {
        $diskName = 'public';
        $disk = Storage::disk($diskName);
        $path = (string) $image->path;
        $exists = $disk->exists($path);
        $size = $exists ? $this->safeSize($diskName, $path) : null;
        $absolutePath = $disk->path($path);
        $url = $disk->url($path);
        $http = $this->inspectHttp($url);
        $flags = $this->flags($exists, $size, $http);

        $this->newLine();
        $this->line('Database row');
        $this->table(['field', 'value'], [
            ['image id', $image->id],
            ['product_id', $image->product_id],
            ['path', $image->path],
            ['card_thumb_path', $image->card_thumb_path],
            ['original_path', $image->original_path],
            ['source', $image->source],
            ['sort_order', $image->sort_order],
            ['is_primary', $this->bool((bool) $image->is_primary)],
            ['created_at', (string) $image->created_at],
        ]);

        $this->line('Storage inspection');
        $this->table(['field', 'value'], [
            ['disk name', $diskName],
            ['Storage::exists(path)', $this->bool($exists)],
            ['Storage::size(path)', $size === null ? 'null' : $size],
            ['absolute filesystem path', $absolutePath],
        ]);

        $this->line('Public URL');
        $this->line($url);

        $this->line('HTTP inspection');
        $this->table(['field', 'value'], [
            ['status', $http['status'] ?? 'error'],
            ['content-type', $http['content_type'] ?? ''],
            ['bytes', $http['bytes']],
            ['error', $http['error'] ?? ''],
        ]);

        if ($flags !== []) {
            $this->warn('Flags: '.implode(', ', $flags));
        } else {
            $this->info('Flags: OK');
        }
    }

    private function inspectHttp(string $url): array
    {
        try {
            $response = Http::timeout(20)
                ->connectTimeout(5)
                ->withHeaders(['User-Agent' => 'AutohimiyaKzImageInspector/1.0'])
                ->get($url);

            $body = $response->body();

            return [
                'status' => $response->status(),
                'content_type' => (string) $response->header('Content-Type', ''),
                'bytes' => strlen($body),
                'is_html' => $this->isHtmlResponse((string) $response->header('Content-Type', ''), $body),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => null,
                'content_type' => null,
                'bytes' => 0,
                'is_html' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function flags(bool $exists, ?int $size, array $http): array
    {
        $flags = [];
        $status = $http['status'] ?? null;

        if (! $exists) {
            $flags[] = 'FILE_MISSING';
        }

        if ($size === 0) {
            $flags[] = 'ZERO_BYTE_FILE';
        }

        if ((bool) ($http['is_html'] ?? false)) {
            $flags[] = 'WRONG_PUBLIC_PATH';
        }

        if ($status === 404) {
            $flags[] = 'PUBLIC_FILE_NOT_FOUND';
        }

        if ($status === 403) {
            $flags[] = 'PUBLIC_ACCESS_DENIED';
        }

        if ($exists && ($status !== 200 || ($size !== null && (int) ($http['bytes'] ?? 0) !== $size))) {
            $flags[] = 'SYMLINK_OR_URL_PROBLEM';
        }

        return array_values(array_unique($flags));
    }

    private function isHtmlResponse(string $contentType, string $body): bool
    {
        $prefix = mb_strtolower(mb_substr(ltrim($body), 0, 80));

        return str_contains(mb_strtolower($contentType), 'text/html')
            || str_starts_with($prefix, '<!doctype html')
            || str_starts_with($prefix, '<html');
    }

    private function productsForSku(string $sku)
    {
        $normalized = KaspiBridgeSku::normalize($sku);

        return Product::query()
            ->orderBy('id')
            ->get()
            ->filter(function (Product $product) use ($normalized): bool {
                foreach ([$product->sku, $product->kaspi_merchant_sku, $product->paloma_sku] as $candidate) {
                    if (KaspiBridgeSku::normalize($candidate) === $normalized) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    private function safeSize(string $diskName, string $path): ?int
    {
        try {
            return Storage::disk($diskName)->size($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeReadlink(string $path): string
    {
        if (! is_link($path)) {
            return 'not_link';
        }

        $target = @readlink($path);

        return $target === false ? 'unreadable' : $target;
    }

    private function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
