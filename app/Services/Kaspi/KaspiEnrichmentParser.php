<?php

namespace App\Services\Kaspi;

use App\Support\Utf8Sanitizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class KaspiEnrichmentParser
{
    private const SERVICE_ATTRIBUTE_KEYS = [
        'id',
        'createdtime',
        'shoplink',
        'categoryid',
        'reviewslink',
        'code',
        'type',
        'measurementliteral',
        'countingliteral',
        'small',
        'medium',
        'large',
        'location',
        'endpoint',
        'link',
        'subtitle',
        'region',
        'regionid',
        'currency',
        'environment',
        'version',
        'url',
        'image',
        'price',
        'value',
        'name',
        'description',
        'title',
    ];

    private const CODE_NAME_MAP = [
        '*type' => 'Тип',
        '*polishing type' => 'Тип полировки',
        '*purpose' => 'Назначение',
        '*size' => 'Объем упаковки',
        '*volume' => 'Объем',
        '*aerosol' => 'Аэрозоль',
        '*spray' => 'Спрей',
        '*features' => 'Особенности',
        '*additional information' => 'Дополнительная информация',
        '*color' => 'Цвет',
    ];

    public function parse(string $html, ?string $url = null): array
    {
        $html = Utf8Sanitizer::cleanString($html) ?? '';
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $jsonLd = $this->jsonLd($xpath);
        $item = $this->backendItem($html);
        $meta = [
            'title' => $this->title($xpath),
            'meta_description' => $this->meta($xpath, 'description'),
            'og_image' => $this->meta($xpath, 'og:image', 'property'),
            'og_title' => $this->meta($xpath, 'og:title', 'property'),
            'og_description' => $this->meta($xpath, 'og:description', 'property'),
        ];

        $rawImages = $this->collectImageCandidates($xpath, $jsonLd, $item, $html, $meta);
        [$images, $rejectedImages] = $this->cleanImages($rawImages);
        $rawDescription = $this->rawDescription($xpath, $item, $jsonLd);
        $cleanedDescription = $this->cleanDescription($rawDescription);
        $rawAttributes = [
            ...$this->attributesFromHtml($xpath),
            ...$this->attributesFromBackendItem($item),
            ...$this->attributesFromJsonLd($jsonLd),
        ];
        [$attributes, $excludedAttributes] = $this->cleanAttributes($rawAttributes);

        return Utf8Sanitizer::clean([
            'url' => $url,
            'meta' => $meta,
            'json_ld' => $jsonLd,
            'backend_item_found' => $item !== [],
            'name' => $this->firstValue([
                data_get($item, 'card.title'),
                $meta['og_title'],
                ...$this->jsonValues($jsonLd, ['name']),
            ]),
            'description' => $cleanedDescription,
            'brand' => $this->firstValue([
                data_get($item, 'card.promoConditions.brand'),
                ...$this->jsonValues($jsonLd, ['brand', 'name']),
            ]),
            'category' => $this->firstValue([
                data_get($item, 'breadcrumbs.4.title'),
                data_get($item, 'breadcrumbs.3.title'),
                ...$this->jsonValues($jsonLd, ['category']),
            ]),
            'sku' => $this->firstValue($this->jsonValues($jsonLd, ['sku'])),
            'price' => $this->firstValue($this->jsonValues($jsonLd, ['offers', 'price'])),
            'availability' => $this->firstValue($this->jsonValues($jsonLd, ['offers', 'availability'])),
            'images' => $images,
            'attributes' => $attributes,
            'cleaned' => [
                'images' => $images,
                'description' => $cleanedDescription,
                'attributes' => $attributes,
            ],
            'debug' => [
                'raw_images' => $rawImages,
                'rejected_images' => $rejectedImages,
                'raw_description' => $rawDescription,
                'cleaned_description' => $cleanedDescription,
                'raw_attributes' => $rawAttributes,
                'cleaned_attributes' => $attributes,
                'excluded_attributes' => $excludedAttributes,
                'excluded_description_lines' => $this->excludedDescriptionLines($rawDescription),
            ],
            'diagnostics' => [
                'json_ld_blocks' => count($jsonLd),
                'backend_item_found' => $item !== [] ? 'yes' : 'no',
                'raw_images' => count($rawImages),
                'cleaned_images' => count($images),
                'rejected_images' => count($rejectedImages),
                'attributes_raw' => count($rawAttributes),
                'attributes_cleaned' => count($attributes),
                'attributes_excluded' => count($excludedAttributes),
            ],
        ]);
    }

    private function collectImageCandidates(DOMXPath $xpath, array $jsonLd, array $item, string $html, array $meta): array
    {
        $images = [];
        $push = function (mixed $value, string $source = 'unknown') use (&$images): void {
            foreach ((array) $value as $entry) {
                if (! is_scalar($entry)) {
                    continue;
                }

                $entry = html_entity_decode((string) $entry, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                foreach ($this->extractUrls($entry) as $url) {
                    $images[] = ['url' => $url, 'source' => $source];
                }
            }
        };

        $push(data_get($item, 'primaryImage.large'), 'backend.primary.large');
        $push(data_get($item, 'primaryImage.medium'), 'backend.primary.medium');

        foreach ((array) data_get($item, 'galleryImages', []) as $image) {
            $push(data_get($image, 'large'), 'backend.gallery.large');
            $push(data_get($image, 'medium'), 'backend.gallery.medium');
            $push(data_get($image, 'small'), 'backend.gallery.small');
        }

        $push($meta['og_image'] ?? null, 'meta.og_image');
        $push($this->jsonValues($jsonLd, ['image']), 'json_ld.image');

        foreach ($xpath->query('//img[@src or @data-src or @srcset or @data-srcset]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $push($node->getAttribute('src'), 'html.img.src');
            $push($node->getAttribute('data-src'), 'html.img.data_src');
            $push($node->getAttribute('srcset'), 'html.img.srcset');
            $push($node->getAttribute('data-srcset'), 'html.img.data_srcset');
        }

        preg_match_all('/https?:\\\\?\/\\\\?\/[^"\')\s<>]+(?:jpg|jpeg|png|webp)(?:\?[^"\')\s<>]*)?/iu', $html, $matches);
        $push($matches[0] ?? [], 'html.regex');

        return array_values(array_filter($images, fn (array $entry): bool => filled($entry['url'] ?? null)));
    }

    private function cleanImages(array $images): array
    {
        $best = [];
        $rejected = [];

        foreach ($images as $entry) {
            $original = (string) ($entry['url'] ?? '');
            $url = $this->normalizeImageUrl($original);
            $reason = $this->rejectImageReason($url);

            if ($reason !== null) {
                $rejected[] = ['url' => $original, 'normalized_url' => $url, 'source' => $entry['source'] ?? null, 'reason' => $reason];
                continue;
            }

            $key = $this->imageDedupeKey($url);
            if (! isset($best[$key]) || $this->imageScore($url, (string) ($entry['source'] ?? '')) > $this->imageScore($best[$key]['url'], $best[$key]['source'])) {
                $best[$key] = ['url' => $url, 'source' => (string) ($entry['source'] ?? '')];
            }
        }

        return [array_values(array_map(fn (array $entry): string => $entry['url'], $best)), $rejected];
    }

    private function rejectImageReason(string $url): ?string
    {
        $lower = mb_strtolower($url);
        $path = mb_strtolower(parse_url($url, PHP_URL_PATH) ?: '');
        $host = mb_strtolower(parse_url($url, PHP_URL_HOST) ?: '');

        if (! str_starts_with($lower, 'http')) {
            return 'not_http';
        }

        if (! preg_match('/\.(jpe?g|png|webp)$/i', $path)) {
            return 'unsupported_extension';
        }

        if (str_contains($host, 'ks-static.cdn-kaspi.kz')) {
            return 'kaspi_static_asset';
        }

        foreach (['/fav/', 'favicon', 'apple-touch-icon', 'logo', 'icon', 'sprite', 'widget', 'button', 'payment', 'credit', 'installment', 'kaspi-red', 'kaspired', 'kaspi_gold'] as $bad) {
            if (str_contains($path, $bad) || str_contains($lower, $bad)) {
                return 'ui_or_payment_asset';
            }
        }

        if (preg_match('/(?:^|[-_\/])(\d{1,3})x(\d{1,3})(?:[._\/-]|$)/i', $lower, $size) && ((int) $size[1] < 200 || (int) $size[2] < 200)) {
            return 'too_small';
        }

        $isKaspiProductCdn = str_contains($host, 'resources.cdn-kaspi.kz')
            && (str_starts_with($path, '/img/m/p/') || str_starts_with($path, '/shop/medias/'));

        return $isKaspiProductCdn ? null : 'not_product_image_path';
    }

    private function normalizeImageUrl(string $url): string
    {
        $url = str_replace(['\/', '\\/'], '/', trim($url));
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/\s+\d+[wx](?:,|$)/i', '', $url) ?: $url;

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $format = (string) ($query['format'] ?? '');
        $query = $format !== '' ? ['format' => preg_replace('/gallery-(small|medium|preview|thumbnail)/i', 'gallery-large', $format)] : [];
        $scheme = $parts['scheme'] ?? 'https';
        $normalized = $scheme.'://'.$parts['host'].$parts['path'];

        return $query === [] ? $normalized : $normalized.'?'.http_build_query($query);
    }

    private function imageDedupeKey(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return mb_strtolower($path);
    }

    private function imageScore(string $url, string $source): int
    {
        $lower = mb_strtolower($url.' '.$source);
        $score = 10;

        foreach (['backend.gallery.large', 'backend.primary.large', 'gallery-large', 'original'] as $token) {
            if (str_contains($lower, $token)) {
                $score += 30;
            }
        }

        foreach (['gallery-medium', 'medium'] as $token) {
            if (str_contains($lower, $token)) {
                $score += 10;
            }
        }

        foreach (['gallery-small', 'small', 'thumb', 'preview'] as $token) {
            if (str_contains($lower, $token)) {
                $score -= 15;
            }
        }

        return $score;
    }

    private function rawDescription(DOMXPath $xpath, array $item, array $jsonLd): ?string
    {
        foreach ($xpath->query('//*[@id="description"]//*[contains(@class, "description")] | //*[@data-id="description"]//*[contains(@class, "description")] | //*[contains(@class, "item__description")] | //*[contains(@class, "product-description")] | //*[contains(@class, "description__content")]') ?: [] as $node) {
            $html = $this->innerHtml($node);
            if (filled($html) && $this->looksLikeProductDescription($html)) {
                return $html;
            }
        }

        $description = data_get($item, 'descriptions.0.text')
            ?: data_get($item, 'description')
            ?: data_get($item, 'card.description')
            ?: $this->firstValue($this->jsonValues($jsonLd, ['description']));
        if (filled($description)) {
            return (string) $description;
        }

        return null;
    }

    private function looksLikeProductDescription(string $html): bool
    {
        $plain = $this->normalizeText(strip_tags($html));
        $lower = mb_strtolower($plain);

        if (blank($plain) || mb_strlen($plain) < 40) {
            return false;
        }

        foreach (["\u{043a}\u{043e}\u{0434} \u{0442}\u{043e}\u{0432}\u{0430}\u{0440}\u{0430}", "\u{0446}\u{0435}\u{043d}\u{0430}", "\u{0432} \u{0440}\u{0430}\u{0441}\u{0441}\u{0440}\u{043e}\u{0447}\u{043a}\u{0443}", "\u{043e}\u{0442}\u{043a}\u{0440}\u{044b}\u{0442}\u{044c} \u{0432} \u{043f}\u{0440}\u{0438}\u{043b}\u{043e}\u{0436}\u{0435}\u{043d}\u{0438}\u{0438} kaspi"] as $bad) {
            if (str_contains($lower, $bad)) {
                return false;
            }
        }

        return true;
    }

    private function cleanDescription(?string $description): ?string
    {
        if (blank($description)) {
            return null;
        }

        $html = $this->sanitizeDescriptionHtml((string) $description);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?: $html;
        $lines = preg_split('/\R+/u', $text) ?: [];
        $clean = [];

        foreach ($lines as $line) {
            $plain = $this->normalizeText(strip_tags($line));
            if (blank($plain) || $this->isServiceDescriptionLine($plain)) {
                continue;
            }
            $clean[] = trim($line);
        }

        $result = $this->normalizeDescriptionBlocks(implode("\n", array_unique($clean)));

        return $result !== '' ? $result : null;
    }

    private function excludedDescriptionLines(?string $description): array
    {
        if (blank($description)) {
            return [];
        }

        $text = preg_replace('/<br\s*\/?>/i', "\n", (string) $description) ?: (string) $description;
        $excluded = [];

        foreach (preg_split('/\R+/u', strip_tags($text)) ?: [] as $line) {
            $line = $this->normalizeText($line);
            if (filled($line) && $this->isServiceDescriptionLine($line)) {
                $excluded[] = ['line' => $line, 'reason' => 'service_description_line'];
            }
        }

        return $excluded;
    }

    private function sanitizeDescriptionHtml(string $description): string
    {
        $description = Utf8Sanitizer::cleanString($description) ?? '';
        $description = preg_replace('#<(script|style|iframe|object|embed|noscript)\b[^>]*>.*?</\1>#isu', '', $description) ?: $description;
        $description = strip_tags($description, '<p><br><ul><ol><li><strong><b><em>');
        $description = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $description) ?: $description;
        $description = preg_replace('/\s+(href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $description) ?: $description;

        return trim($description);
    }

    private function normalizeDescriptionBlocks(string $html): string
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/[ \t]+/u', ' ', $html) ?: $html;
        $html = preg_replace('/\n{3,}/u', "\n\n", $html) ?: $html;

        if (! preg_match('/<(p|ul|ol|li|br)\b/i', $html)) {
            $paragraphs = array_values(array_filter(array_map(
                fn (string $line): string => $this->normalizeText($line),
                preg_split('/\R+/u', strip_tags($html)) ?: []
            )));

            return implode('', array_map(fn (string $line): string => '<p>'.e($line).'</p>', array_unique($paragraphs)));
        }

        return trim($html);
    }

    private function isServiceDescriptionLine(string $line): bool
    {
        $lower = mb_strtolower($line);
        foreach (['kaspi', "\u{0440}\u{0430}\u{0441}\u{0441}\u{0440}\u{043e}\u{0447}", "\u{043a}\u{0440}\u{0435}\u{0434}\u{0438}\u{0442}", 'kaspi red', 'kaspi gold', "\u{0446}\u{0435}\u{043d}\u{044b}", "\u{043e}\u{0442}\u{0437}\u{044b}\u{0432}\u{044b}", "\u{0434}\u{043e}\u{0441}\u{0442}\u{0430}\u{0432}\u{043a}\u{0430}", "\u{043f}\u{0440}\u{043e}\u{0434}\u{0430}\u{0432}\u{0446}\u{044b}"] as $bad) {
            if (str_contains($lower, $bad)) {
                return true;
            }
        }

        if (in_array($lower, ["\u{043e}\u{043f}\u{0438}\u{0441}\u{0430}\u{043d}\u{0438}\u{0435}", "\u{0445}\u{0430}\u{0440}\u{0430}\u{043a}\u{0442}\u{0435}\u{0440}\u{0438}\u{0441}\u{0442}\u{0438}\u{043a}\u{0438}"], true)) {
            return true;
        }

        foreach (['kaspi', 'рассроч', 'кредит', 'kaspi red', 'kaspi gold', 'цены', 'отзывы', 'доставка', 'продавцы'] as $bad) {
            if (str_contains($lower, $bad)) {
                return true;
            }
        }

        return in_array($lower, ['описание', 'характеристики'], true);
    }

    private function attributesFromBackendItem(array $item): array
    {
        $attributes = [];

        foreach ((array) data_get($item, 'specifications', []) as $group) {
            foreach ((array) ($group['features'] ?? []) as $feature) {
                if (! is_array($feature)) {
                    continue;
                }

                $name = $this->attributeName($feature);
                $values = $this->featureValues($feature);

                if (filled($name) && $values !== []) {
                    $attributes[] = [
                        'name' => $name,
                        'value' => implode(', ', $values),
                        'source' => 'backend.specifications',
                    ];
                }
            }
        }

        return $attributes;
    }

    private function attributeName(array $feature): ?string
    {
        $name = $this->normalizeText((string) ($feature['name'] ?? ''));
        if (filled($name) && $this->looksHumanAttributeName($name)) {
            return $name;
        }

        $code = mb_strtolower((string) ($feature['code'] ?? ''));
        foreach (self::CODE_NAME_MAP as $suffix => $label) {
            if (str_ends_with($code, $suffix)) {
                return $label;
            }
        }

        return null;
    }

    private function featureValues(array $feature): array
    {
        $values = [];

        foreach ((array) ($feature['featureValues'] ?? []) as $entry) {
            $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
            if (is_bool($value)) {
                $values[] = $value ? 'Да' : 'Нет';
            } elseif (is_scalar($value)) {
                $values[] = $this->normalizeText((string) $value);
            }
        }

        return array_values(array_unique(array_filter($values, 'filled')));
    }

    private function attributesFromHtml(DOMXPath $xpath): array
    {
        $attributes = [];
        $rows = $xpath->query('//dl[contains(@class, "specifications-list__spec")][.//dt and .//dd]');

        foreach ($rows ?: [] as $row) {
            $local = new DOMXPath($row->ownerDocument);
            $name = $this->nodeText($local->query('.//*[contains(@class, "specifications-list__spec-term-text")]', $row)?->item(0));
            $value = $this->nodeText($local->query('.//*[contains(@class, "specifications-list__spec-definition")]', $row)?->item(0));

            if (filled($name) && filled($value)) {
                $attributes[] = ['name' => (string) $name, 'value' => (string) $value, 'source' => 'html.specifications'];
            }
        }

        return $attributes;
    }

    private function attributesFromJsonLd(array $json): array
    {
        $attributes = [];
        foreach ($json as $item) {
            foreach ((array) data_get($item, 'additionalProperty', []) as $property) {
                if (is_array($property) && filled($property['name'] ?? null) && filled($property['value'] ?? null)) {
                    $attributes[] = ['name' => (string) $property['name'], 'value' => (string) $property['value'], 'source' => 'json_ld.additionalProperty'];
                }
            }
        }

        return $attributes;
    }

    private function cleanAttributes(array $attributes): array
    {
        $clean = [];
        $excluded = [];
        $seen = [];

        foreach ($attributes as $attribute) {
            $name = $this->normalizeText((string) ($attribute['name'] ?? ''));
            $value = $this->normalizeText((string) ($attribute['value'] ?? ''));

            if (blank($name) || blank($value)) {
                $excluded[] = ['name' => $name, 'value' => $value, 'source' => $attribute['source'] ?? null, 'reason' => 'empty'];
                continue;
            }

            if (! $this->looksHumanAttributeName($name)) {
                $excluded[] = ['name' => $name, 'value' => $value, 'source' => $attribute['source'] ?? null, 'reason' => 'technical_name'];
                continue;
            }

            if ($this->isBlockedAttributeName($name) || $this->isBlockedAttributeValue($value)) {
                $excluded[] = ['name' => $name, 'value' => $value, 'source' => $attribute['source'] ?? null, 'reason' => 'service_attribute'];
                continue;
            }

            if (mb_strlen($name) > 90 || mb_strlen($value) > 600) {
                $excluded[] = ['name' => $name, 'value' => $value, 'source' => $attribute['source'] ?? null, 'reason' => 'too_long'];
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $excluded[] = ['name' => $name, 'value' => $value, 'source' => $attribute['source'] ?? null, 'reason' => 'duplicate'];
                continue;
            }

            $seen[$key] = true;
            $clean[] = ['name' => $name, 'value' => $value];
        }

        return [$clean, $excluded];
    }

    private function looksHumanAttributeName(string $name): bool
    {
        if (preg_match('/[{}[\]<>]|https?:|\/shop\/|[a-z]+[A-Z]|_/', $name)) {
            return false;
        }

        return (bool) preg_match('/\p{Cyrillic}/u', $name);
    }

    private function isBlockedAttributeName(string $name): bool
    {
        $lower = mb_strtolower($name);

        if (in_array($lower, self::SERVICE_ATTRIBUTE_KEYS, true)) {
            return true;
        }

        foreach (['общие характеристики', 'код товара', 'описание', 'характеристики', 'отзывы', 'продавцы', 'доставка', 'цена'] as $bad) {
            if ($lower === $bad || str_contains($lower, $bad)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedAttributeValue(string $value): bool
    {
        $lower = mb_strtolower($value);

        return str_contains($lower, 'http://')
            || str_contains($lower, 'https://')
            || str_contains($lower, '/shop/')
            || str_contains($lower, 'endpoint');
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xc2\xa0", '�', 'пїЅ'], [' ', 'л', 'л'], $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        $value = preg_replace('/\btrue\b/i', 'Да', $value) ?: $value;
        $value = preg_replace('/\bfalse\b/i', 'Нет', $value) ?: $value;
        $value = preg_replace('/(\d+(?:[.,]\d+)?)\s*л\b/u', '$1 л', $value) ?: $value;
        $value = preg_replace('/(\d+(?:[.,]\d+)?)\s*мл\b/u', '$1 мл', $value) ?: $value;

        return trim($value);
    }

    private function backendItem(string $html): array
    {
        $needle = 'BACKEND.components.item';
        $start = strpos($html, $needle);
        if ($start === false) {
            return [];
        }

        $equals = strpos($html, '=', $start);
        if ($equals === false) {
            return [];
        }

        $braceStart = strpos($html, '{', $equals);
        if ($braceStart === false) {
            return [];
        }

        $json = $this->balancedJsonObject($html, $braceStart);
        $decoded = $json ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function balancedJsonObject(string $html, int $start): ?string
    {
        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($html);

        for ($i = $start; $i < $length; $i++) {
            $char = $html[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($html, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function title(DOMXPath $xpath): ?string
    {
        return $this->nodeText($xpath->query('//title')->item(0));
    }

    private function meta(DOMXPath $xpath, string $name, string $attribute = 'name'): ?string
    {
        $nodes = $xpath->query('//meta[translate(@'.$attribute.', "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="'.mb_strtolower($name).'"]/@content');

        return $this->nodeText($nodes?->item(0));
    }

    private function jsonLd(DOMXPath $xpath): array
    {
        $items = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    private function jsonValues(array $items, array $path): array
    {
        $values = [];
        foreach ($items as $item) {
            $value = data_get($item, implode('.', $path));
            foreach ((array) $value as $entry) {
                if (is_array($entry)) {
                    $values = [...$values, ...array_filter($entry, 'is_string')];
                } elseif (is_scalar($entry)) {
                    $values[] = (string) $entry;
                }
            }
        }

        return $values;
    }

    private function extractUrls(string $value): array
    {
        preg_match_all('/https?:\/\/[^,\s"\')<>]+/iu', str_replace('\\/', '/', $value), $matches);

        return array_map(fn (string $url): string => rtrim($url, '.,;'), $matches[0] ?? []);
    }

    private function firstValue(array $values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function nodeText(mixed $node): ?string
    {
        if (! $node instanceof DOMNode) {
            return null;
        }

        return $this->normalizeText((string) $node->textContent);
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }
}


