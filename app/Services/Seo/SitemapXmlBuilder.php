<?php

namespace App\Services\Seo;

use App\Models\Category;
use App\Models\Product;
use App\Support\StorefrontCanonicalUrl;
use Illuminate\Support\Collection;

class SitemapXmlBuilder
{
    public function build(): string
    {
        $entries = $this->entries();
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        foreach ($entries as $entry) {
            $lines[] = '    <url>';
            $lines[] = '        <loc>'.$this->xml((string) $entry['loc']).'</loc>';

            if (! empty($entry['lastmod'])) {
                $lines[] = '        <lastmod>'.$this->xml((string) $entry['lastmod']).'</lastmod>';
            }

            $lines[] = '        <changefreq>'.$this->xml((string) $entry['changefreq']).'</changefreq>';
            $lines[] = '        <priority>'.$this->xml((string) $entry['priority']).'</priority>';
            $lines[] = '    </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    public function entries(): Collection
    {
        $entries = collect([
            $this->entry(StorefrontCanonicalUrl::path('/'), null, 'daily', '1.0'),
            $this->entry(StorefrontCanonicalUrl::path('/catalog'), null, 'daily', '0.9'),
            $this->entry(StorefrontCanonicalUrl::path('/contacts'), null, 'monthly', '0.5'),
        ]);

        Category::query()
            ->where('status', 'active')
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->orderBy('updated_at', 'desc')
            ->get(['slug', 'updated_at'])
            ->each(function (Category $category) use ($entries): void {
                $entries->push($this->entry(
                    StorefrontCanonicalUrl::path('/category/'.rawurlencode((string) $category->slug)),
                    $category->updated_at,
                    'weekly',
                    '0.8',
                ));
            });

        Product::query()
            ->visibleOnStorefront()
            ->orderBy('updated_at', 'desc')
            ->get(['slug', 'updated_at'])
            ->each(function (Product $product) use ($entries): void {
                $entries->push($this->entry(
                    StorefrontCanonicalUrl::path('/product/'.rawurlencode((string) $product->slug)),
                    $product->updated_at,
                    'daily',
                    '0.7',
                ));
            });

        return $entries
            ->filter(fn (array $entry): bool => $entry['loc'] !== '')
            ->unique('loc')
            ->values();
    }

    private function entry(string $loc, mixed $lastmod, string $changefreq, string $priority): array
    {
        return [
            'loc' => $this->stripInvalidXml($loc),
            'lastmod' => $lastmod && method_exists($lastmod, 'toAtomString') ? $lastmod->toAtomString() : null,
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($this->stripInvalidXml($value), ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function stripInvalidXml(string $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);

        return is_string($clean) ? $clean : '';
    }
}