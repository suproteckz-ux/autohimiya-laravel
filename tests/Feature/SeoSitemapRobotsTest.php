<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoSitemapRobotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_renders_canonical_xml_without_duplicates(): void
    {
        config(['app.url' => 'https://xn--80aesatk1az7g.kz', 'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);

        $category = Category::query()->create([
            'name' => 'Filters',
            'slug' => 'filters',
            'status' => 'active',
        ]);

        Product::query()->create([
            'name' => 'Glass Cleaner',
            'slug' => 'glass-cleaner',
            'category_id' => $category->id,
            'product_status' => ProductStatus::ACTIVE_MANUAL,
            'availability' => true,
            'quantity' => 10,
            'price' => 1500,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/xml; charset=UTF-8');

        $xml = $response->getContent();
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('<loc>https://xn--80aesatk1az7g.kz/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://xn--80aesatk1az7g.kz/catalog</loc>', $xml);
        $this->assertStringContainsString('<loc>https://xn--80aesatk1az7g.kz/contacts</loc>', $xml);
        $this->assertStringContainsString('<loc>https://xn--80aesatk1az7g.kz/category/filters</loc>', $xml);
        $this->assertStringContainsString('<loc>https://xn--80aesatk1az7g.kz/product/glass-cleaner</loc>', $xml);

        preg_match_all('/<loc>(.*?)<\/loc>/', $xml, $matches);
        $this->assertSame($matches[1], array_values(array_unique($matches[1])));
    }

    public function test_robots_points_to_canonical_sitemap(): void
    {
        config(['app.url' => 'https://xn--80aesatk1az7g.kz', 'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);

        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
        $response->assertSeeText('User-agent: *');
        $response->assertSeeText('Allow: /');
        $response->assertSeeText('Disallow: /admin');
        $response->assertSeeText('Sitemap: https://xn--80aesatk1az7g.kz/sitemap.xml');
    }
}