<?php

namespace Tests\Feature;

use App\Services\Kaspi\KaspiEnrichmentParser;
use Tests\TestCase;

class KaspiEnrichmentParserTest extends TestCase
{
    public function test_aut_636_bin_gallery_images_are_extracted_as_full_size_urls(): void
    {
        $payload = $this->parse($this->fixtureHtml(
            'aut_636',
            $this->binImages([
                'pc8/p7a/64354814',
                'pe5/p7a/64354815',
                'p1d/p7b/64354817',
                'p39/p7b/64354818',
                'pdd/p7d/64354821',
                'p16/p7e/64354823',
            ]),
            '<p>Меловой ароматизатор Eikosha Gucini для салона автомобиля с насыщенным и стойким ароматом.</p>',
            $this->attributes(9),
        ));

        $this->assertCount(6, $payload['images']);
        $this->assertSame('https://resources.cdn-kaspi.kz/img/m/p/pc8/p7a/64354814.bin?format=gallery-large', $payload['images'][0]);
        $this->assertSame('https://resources.cdn-kaspi.kz/img/m/p/p16/p7e/64354823.bin?format=gallery-large', $payload['images'][5]);
        $this->assertTrue(str_contains($payload['description'], 'Меловой ароматизатор'));
        $this->assertCount(9, $payload['attributes']);
    }

    public function test_working_kaspi_gallery_counts_stay_unchanged(): void
    {
        $cases = [
            'aut_55' => [$this->jpgImages(['hb2/hb4/83747328426014', 'he5/hfe/83747333472286', 'h6a/he5/83747336519710', 'h93/h4f/83747339567134']), null, 5, 4],
            'aut_163' => [$this->jpgImages(['h11/h22/16300000000001', 'h11/h22/16300000000002', 'h11/h22/16300000000003', 'h11/h22/16300000000004']), '<p>Герметик для прокладок и соединений с устойчивостью к высоким температурам.</p>', 3, 4],
            'aut_608' => [$this->jpgImages(['h11/h22/60800000000001', 'h11/h22/60800000000002', 'h11/h22/60800000000003', 'h11/h22/60800000000004', 'h11/h22/60800000000005', 'h11/h22/60800000000006', 'h11/h22/60800000000007', 'h11/h22/60800000000008']), '<p>Очиститель кузова автомобиля для регулярного ухода за внешними поверхностями.</p>', 4, 8],
        ];

        foreach ($cases as $sku => [$images, $description, $attributeCount, $imageCount]) {
            $payload = $this->parse($this->fixtureHtml($sku, $images, $description, $this->attributes($attributeCount)));

            $this->assertCount($imageCount, $payload['images'], $sku);
            $this->assertCount($attributeCount, $payload['attributes'], $sku);
            $this->assertSame($description !== null, filled($payload['description']), $sku);
        }
    }

    public function test_gallery_duplicates_and_thumbnails_are_removed_when_full_size_exists(): void
    {
        $payload = $this->parse($this->fixtureHtml(
            'duplicate_gallery',
            $this->binImages(['pc8/p7a/64354814', 'pc8/p7a/64354814', 'pe5/p7a/64354815']),
            null,
            [],
        ));

        $this->assertSame([
            'https://resources.cdn-kaspi.kz/img/m/p/pc8/p7a/64354814.bin?format=gallery-large',
            'https://resources.cdn-kaspi.kz/img/m/p/pe5/p7a/64354815.bin?format=gallery-large',
        ], $payload['images']);
    }

    private function parse(string $html): array
    {
        return app(KaspiEnrichmentParser::class)->parse($html, 'https://kaspi.kz/shop/p/fixture/');
    }

    private function fixtureHtml(string $sku, array $galleryImages, ?string $description, array $attributes): string
    {
        $item = [
            'card' => [
                'title' => 'Fixture '.$sku,
                'promoConditions' => ['brand' => 'Fixture brand'],
            ],
            'descriptions' => filled($description) ? [['text' => $description]] : [],
            'primaryImage' => $galleryImages[0] ?? [],
            'galleryImages' => $galleryImages,
            'specifications' => [[
                'features' => array_map(
                    fn (array $attribute): array => [
                        'name' => $attribute['name'],
                        'featureValues' => [['value' => $attribute['value']]],
                    ],
                    $attributes,
                ),
            ]],
        ];

        $firstLarge = (string) ($galleryImages[0]['large'] ?? '');
        $domImages = implode('', array_map(
            fn (array $image): string => '<img src="'.e($image['medium'] ?? $image['large'] ?? '').'" data-src="'.e($image['small'] ?? $image['medium'] ?? '').'">',
            $galleryImages,
        ));

        return '<html><head>'
            .'<meta property="og:image" content="'.e($firstLarge).'">'
            .'<script type="application/ld+json">'.json_encode(['@type' => 'Product', 'name' => 'Fixture '.$sku, 'image' => $firstLarge], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'</script>'
            .'</head><body>'
            .'<script>BACKEND.components.item = '.json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).';</script>'
            .$domImages
            .'<img src="https://ks-static.cdn-kaspi.kz/shop/front/sa/stable/desktop/fav/favicon-32x32.png">'
            .'</body></html>';
    }

    private function binImages(array $ids): array
    {
        return array_map(fn (string $id): array => [
            'large' => 'https://resources.cdn-kaspi.kz/img/m/p/'.$id.'.bin?format=gallery-large',
            'medium' => 'https://resources.cdn-kaspi.kz/img/m/p/'.$id.'.bin?format=gallery-medium',
            'small' => 'https://resources.cdn-kaspi.kz/img/m/p/'.$id.'.bin?format=gallery-small',
        ], $ids);
    }

    private function jpgImages(array $ids): array
    {
        return array_map(fn (string $id): array => [
            'large' => 'https://resources.cdn-kaspi.kz/img/m/p/'.$id.'.jpg?format=gallery-large',
            'medium' => 'https://resources.cdn-kaspi.kz/img/m/p/'.$id.'.jpg?format=gallery-medium',
            'small' => 'https://resources.cdn-kaspi.kz/img/m/p/'.$id.'.jpg?format=gallery-small',
        ], $ids);
    }

    private function attributes(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        return array_map(fn (int $index): array => [
            'name' => 'Характеристика '.$index,
            'value' => 'Значение '.$index,
        ], range(1, $count));
    }
}
