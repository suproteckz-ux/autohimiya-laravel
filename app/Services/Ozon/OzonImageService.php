<?php

namespace App\Services\Ozon;

use App\Models\Product;
use App\Services\Ozon\DTO\OzonImageResult;
use App\Support\ProductImageUrlResolver;
use App\Support\StorefrontCanonicalUrl;

class OzonImageService
{
    public function resolve(Product $product): OzonImageResult
    {
        $product->loadMissing('images');
        $paths = $product->images->sortBy([['is_primary', 'desc'], ['sort_order', 'asc'], ['id', 'asc']])->pluck('path')->prepend($product->primary_image)->filter()->unique();
        $urls = []; $warnings = []; $errors = [];
        foreach ($paths as $path) {
            $normalized = ProductImageUrlResolver::normalizePath($path);
            if (! $normalized || preg_match('#(?:placeholder|no[_-]?image|stub)#i', $normalized)) { $warnings[] = 'Изображение-заглушка пропущено.'; continue; }
            $base = StorefrontCanonicalUrl::base();
            if (! str_starts_with($base, 'https://') || $this->forbidden($base)) { $errors[] = 'Не настроен безопасный production storefront URL.'; break; }
            $url = filter_var($path, FILTER_VALIDATE_URL) ? $path : rtrim($base, '/').'/storage/'.$normalized;
            if (! str_starts_with($url, 'https://') || $this->forbidden($url)) { $errors[] = 'Недопустимый URL изображения: '.$url; continue; }
            $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if ($extension !== '' && ! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) { $warnings[] = 'Неподдерживаемое расширение изображения: '.$extension; continue; }
            $urls[] = $url;
        }
        $urls = array_values(array_unique($urls));
        if ($urls === []) $errors[] = 'Отсутствует валидное главное фото.';
        return new OzonImageResult($urls, $urls[0] ?? null, array_values(array_unique($warnings)), array_values(array_unique($errors)));
    }

    private function forbidden(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_contains((string) parse_url($url, PHP_URL_PATH), '/admin');
    }
}
