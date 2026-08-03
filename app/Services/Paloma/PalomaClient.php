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
        $availabilityEntries = [];
        $invalidAvailabilityCount = 0;

        foreach ($this->availabilityNodes($offer) as $availability) {
            $entry = $this->mapAvailability($availability);

            if ($entry instanceof PalomaAvailabilityData) {
                $availabilityEntries[] = $entry;
            } else {
                $invalidAvailabilityCount++;
            }
        }

        if ($availabilityEntries === []) {
            $stockText = $this->firstXmlText($offer, ['stockCount', 'stock', 'quantity', 'qty']);
            $stock = $this->integerStock($stockText);

            if ($stock === null) {
                $invalidAvailabilityCount += $stockText === null ? 0 : 1;
                $stock = 0;
            }

            $availableText = $this->firstXmlText($offer, ['available', 'inStock']);
            $available = $this->booleanValue($availableText) ?? $stock > 0;
            $availabilityEntries[] = new PalomaAvailabilityData(
                storeId: null,
                stockCount: $stock,
                available: $available,
                payload_hash: hash('sha256', $offer->asXML() ?: ''),
            );
        }

        $stockSummary = $this->summarizeAvailabilityEntries($availabilityEntries);

        return new PalomaOfferData(
            sku: $this->nullableString($this->attributeText($offer, 'sku') ?? $this->firstXmlText($offer, ['sku', 'SKU', 'code', 'article', 'vendorCode'])),
            model: $this->nullableString($this->firstXmlText($offer, ['model', 'name', 'title'])),
            price: $this->nullableFloat($this->firstXmlText($offer, ['price', 'Price', 'cost'])),
            stock: $stockSummary['stock'],
            available: $stockSummary['stock'] > 0,
            payload_hash: hash('sha256', $offer->asXML() ?: ''),
            availability_entries: $availabilityEntries,
            duplicate_availability_count: $stockSummary['duplicates'],
            invalid_availability_count: $invalidAvailabilityCount,
            has_stock_conflict: $stockSummary['conflicts'] > 0,
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
            fn (array $item): PalomaOfferData => $this->mapJsonOffer($item),
            $this->jsonOfferNodes($decoded),
        );
    }

    private function mapJsonOffer(array $item): PalomaOfferData
    {
        $stockText = $item['stockCount'] ?? $item['stock'] ?? $item['quantity'] ?? $item['qty'] ?? null;
        $stock = $this->integerStock($stockText);
        $invalidAvailabilityCount = 0;

        if ($stock === null) {
            $invalidAvailabilityCount = $stockText === null ? 0 : 1;
            $stock = 0;
        }

        $available = $this->booleanValue($item['available'] ?? $item['inStock'] ?? null) ?? $stock > 0;
        $entry = new PalomaAvailabilityData(
            storeId: $this->nullableString($item['storeId'] ?? $item['storeID'] ?? $item['store'] ?? null),
            stockCount: $stock,
            available: $available,
            payload_hash: hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        );

        return new PalomaOfferData(
            sku: $this->nullableString($item['sku'] ?? $item['SKU'] ?? $item['code'] ?? $item['article'] ?? null),
            model: $this->nullableString($item['model'] ?? $item['name'] ?? $item['title'] ?? null),
            price: $this->nullableFloat($item['price'] ?? $item['Price'] ?? $item['cost'] ?? null),
            stock: $entry->effectiveStock(),
            available: $entry->effectiveStock() > 0,
            payload_hash: $entry->payload_hash,
            availability_entries: [$entry],
            invalid_availability_count: $invalidAvailabilityCount,
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

    private function integerStock(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if (! preg_match('/^-?\d+$/', $text)) {
            return null;
        }

        return max(0, (int) $text);
    }

    private function booleanValue(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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

    private function mapAvailability(SimpleXMLElement $availability): ?PalomaAvailabilityData
    {
        $stock = $this->integerStock(
            $this->attributeText($availability, 'stockCount')
                ?? $this->firstXmlText($availability, ['stockCount', 'stock', 'quantity', 'qty'])
        );

        if ($stock === null) {
            return null;
        }

        $available = $this->booleanValue(
            $this->attributeText($availability, 'available')
                ?? $this->firstXmlText($availability, ['available', 'inStock'])
        ) ?? $stock > 0;

        return new PalomaAvailabilityData(
            storeId: $this->nullableString(
                $this->attributeText($availability, 'storeId')
                    ?? $this->attributeText($availability, 'storeID')
                    ?? $this->attributeText($availability, 'store')
                    ?? $this->firstXmlText($availability, ['storeId', 'storeID', 'store'])
            ),
            stockCount: $stock,
            available: $available,
            payload_hash: hash('sha256', $availability->asXML() ?: ''),
        );
    }

    /**
     * @return array<int, SimpleXMLElement>
     */
    private function availabilityNodes(SimpleXMLElement $offer): array
    {
        $nodes = [];

        foreach ($this->childrenByLocalName($offer, 'availabilities') as $container) {
            foreach ($this->childrenByLocalName($container, 'availability') as $availability) {
                $nodes[] = $availability;
            }
        }

        foreach ($this->childrenByLocalName($offer, 'availability') as $availability) {
            $nodes[] = $availability;
        }

        return $nodes;
    }

    /**
     * @param array<int, PalomaAvailabilityData> $entries
     * @return array{stock: int, duplicates: int, conflicts: int}
     */
    private function summarizeAvailabilityEntries(array $entries): array
    {
        $seen = [];
        $stores = [];
        $duplicates = 0;
        $conflicts = 0;

        foreach ($entries as $entry) {
            $duplicateKey = $entry->duplicateKey();

            if (isset($seen[$duplicateKey])) {
                $duplicates++;
                continue;
            }

            $seen[$duplicateKey] = true;
            $storeKey = $entry->storeKey();
            $stock = $entry->effectiveStock();

            if (array_key_exists($storeKey, $stores) && $stores[$storeKey] !== $stock) {
                $conflicts++;
                $stores[$storeKey] = max($stores[$storeKey], $stock);
                continue;
            }

            $stores[$storeKey] = $stock;
        }

        return [
            'stock' => array_sum($stores),
            'duplicates' => $duplicates,
            'conflicts' => $conflicts,
        ];
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
