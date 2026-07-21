<?php

namespace App\Services\Paloma;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use SimpleXMLElement;

class PalomaClient
{
    /**
     * @return array<int, PalomaOfferData>
     */
    public function offers(): array
    {
        $response = $this->response();

        if (! $response->successful()) {
            throw new RuntimeException('Paloma endpoint returned HTTP '.$response->status().'.');
        }

        return $this->parse($response->body(), $response->header('Content-Type'));
    }

    public function response(): Response
    {
        $endpoint = config('services.paloma.endpoint');

        if (blank($endpoint)) {
            throw new RuntimeException('PALOMA_ENDPOINT is not configured.');
        }

        try {
            return Http::timeout(60)->get($endpoint);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to connect to Paloma endpoint. Check PALOMA_ENDPOINT availability and network access.', previous: $exception);
        }
    }

    /**
     * @return array<int, PalomaOfferData>
     */
    public function parse(string $body, ?string $contentType = null): array
    {
        if ($this->looksLikeJson($body, $contentType)) {
            return $this->parseJson($body);
        }

        return $this->parseXml($body);
    }

    /**
     * @return array<int, PalomaOfferData>
     */
    public function parseXml(string $xml): array
    {
        libxml_use_internal_errors(true);

        $document = simplexml_load_string($xml);

        if (! $document instanceof SimpleXMLElement) {
            $errors = collect(libxml_get_errors())
                ->map(fn ($error) => trim($error->message))
                ->filter()
                ->implode('; ');

            libxml_clear_errors();

            throw new RuntimeException('Unable to parse Paloma XML.'.($errors ? ' '.$errors : ''));
        }

        $offers = $this->xmlNodes($document, ['offer']);

        if ($offers === []) {
            $offers = $this->xmlNodes($document, ['item', 'product', 'good']);
        }

        return array_map(
            fn (SimpleXMLElement $offer): PalomaOfferData => $this->mapOffer($offer),
            $offers,
        );
    }

    private function mapOffer(SimpleXMLElement $offer): PalomaOfferData
    {
        $availability = $this->firstXmlNode($offer, [
            'availabilities.availability',
            'availability',
        ]);

        $stock = (int) (
            $this->attributeText($availability, 'stockCount')
            ?? $this->firstXmlText($offer, [
            'availabilities.availability.stockCount',
            'availability.stockCount',
            'stockCount',
            'stock',
            'quantity',
            'qty',
            ])
            ?? 0
        );

        $availableText = $this->attributeText($availability, 'available')
            ?? $this->firstXmlText($offer, [
            'availabilities.availability.available',
            'availability.available',
            'available',
            'inStock',
            ]);

        return new PalomaOfferData(
            sku: $this->nullableString($this->attributeText($offer, 'sku') ?? $this->firstXmlText($offer, ['sku', 'SKU', 'code', 'article', 'vendorCode'])),
            model: $this->nullableString($this->firstXmlText($offer, ['model', 'name', 'title'])),
            price: $this->nullableFloat($this->firstXmlText($offer, ['price', 'Price', 'cost'])),
            stock: $stock,
            available: $availableText === null
                ? $stock > 0
                : filter_var($availableText, FILTER_VALIDATE_BOOLEAN),
            payload_hash: hash('sha256', $offer->asXML() ?: ''),
        );
    }

    /**
     * @return array<int, PalomaOfferData>
     */
    private function parseJson(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Unable to parse Paloma JSON.');
        }

        return array_map(
            fn (array $item): PalomaOfferData => new PalomaOfferData(
                sku: $this->nullableString($item['sku'] ?? $item['SKU'] ?? $item['code'] ?? $item['article'] ?? null),
                model: $this->nullableString($item['model'] ?? $item['name'] ?? $item['title'] ?? null),
                price: $this->nullableFloat($item['price'] ?? $item['Price'] ?? $item['cost'] ?? null),
                stock: (int) ($item['stockCount'] ?? $item['stock'] ?? $item['quantity'] ?? $item['qty'] ?? 0),
                available: (bool) ($item['available'] ?? $item['inStock'] ?? (($item['stockCount'] ?? $item['stock'] ?? 0) > 0)),
                payload_hash: hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            ),
            $this->jsonOfferNodes($decoded),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function jsonOfferNodes(array $value): array
    {
        $nodes = [];

        $walk = function (array $node) use (&$walk, &$nodes): void {
            if ($this->looksLikeOfferArray($node)) {
                $nodes[] = $node;
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        $walk($value);

        return $nodes;
    }

    private function looksLikeOfferArray(array $node): bool
    {
        $keys = array_map('strtolower', array_keys($node));

        return count(array_intersect($keys, ['sku', 'model', 'name', 'price', 'stockcount', 'stock', 'quantity'])) >= 2;
    }

    private function nullableFloat(mixed $value): ?float
    {
        $text = str_replace(',', '.', trim((string) $value));

        return is_numeric($text) ? (float) $text : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function firstXmlText(SimpleXMLElement $node, array $paths): ?string
    {
        $xmlNode = $this->firstXmlNode($node, $paths);

        if (! $xmlNode instanceof SimpleXMLElement) {
            return null;
        }

        $text = trim((string) $xmlNode);

        return $text === '' ? null : $text;
    }

    private function firstXmlNode(SimpleXMLElement $node, array $paths): ?SimpleXMLElement
    {
        foreach ($paths as $path) {
            $current = $node;

            foreach (explode('.', $path) as $part) {
                $children = $this->childrenByLocalName($current, $part);

                if ($children === []) {
                    $current = null;
                    break;
                }

                $current = $children[0];
            }

            if ($current instanceof SimpleXMLElement) {
                return $current;
            }
        }

        return null;
    }

    private function attributeText(?SimpleXMLElement $node, string $name): ?string
    {
        if (! $node instanceof SimpleXMLElement) {
            return null;
        }

        foreach ($node->attributes() as $attributeName => $value) {
            if (strtolower((string) $attributeName) === strtolower($name)) {
                $text = trim((string) $value);

                return $text === '' ? null : $text;
            }
        }

        foreach ($node->getNamespaces(true) as $namespace) {
            foreach ($node->attributes($namespace) as $attributeName => $value) {
                if (strtolower((string) $attributeName) === strtolower($name)) {
                    $text = trim((string) $value);

                    return $text === '' ? null : $text;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, SimpleXMLElement>
     */
    public function xmlNodes(SimpleXMLElement $node, array $names): array
    {
        $nodes = [];
        $wanted = array_map('strtolower', $names);

        $walk = function (SimpleXMLElement $current) use (&$walk, &$nodes, $wanted): void {
            if (in_array(strtolower($current->getName()), $wanted, true)) {
                $nodes[] = $current;
            }

            foreach ($this->allChildren($current) as $child) {
                $walk($child);
            }
        };

        $walk($node);

        return $nodes;
    }

    /**
     * @return array<int, SimpleXMLElement>
     */
    private function allChildren(SimpleXMLElement $node): array
    {
        $children = [];

        foreach ($node->children() as $child) {
            $children[] = $child;
        }

        foreach ($node->getNamespaces(true) as $namespace) {
            foreach ($node->children($namespace) as $child) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * @return array<int, SimpleXMLElement>
     */
    private function childrenByLocalName(SimpleXMLElement $node, string $name): array
    {
        return array_values(array_filter(
            $this->allChildren($node),
            fn (SimpleXMLElement $child): bool => strtolower($child->getName()) === strtolower($name),
        ));
    }

    private function looksLikeJson(string $body, ?string $contentType): bool
    {
        if ($contentType && str_contains(strtolower($contentType), 'json')) {
            return true;
        }

        return str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[');
    }
}
