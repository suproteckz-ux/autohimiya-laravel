<?php

namespace App\Console\Commands;

use App\Services\Paloma\PalomaClient;
use Illuminate\Console\Command;
use SimpleXMLElement;

class PalomaDebugCommand extends Command
{
    protected $signature = 'paloma:debug';

    protected $description = 'Inspect Paloma response structure without importing or changing database records.';

    public function handle(PalomaClient $client): int
    {
        $response = $client->response();
        $body = $response->body();
        $contentType = $response->header('Content-Type', 'unknown');
        $format = $this->detectFormat($body, $contentType);
        $path = $this->saveRawResponse($body, $format);

        $this->info('Paloma debug');
        $this->table(['Metric', 'Value'], [
            ['HTTP status', $response->status()],
            ['Content-Type', $contentType],
            ['Response size', strlen($body).' bytes'],
            ['Detected format', $format],
            ['Saved raw response', storage_path('app/'.$path)],
        ]);

        if ($format === 'xml') {
            $this->inspectXml($client, $body);

            return self::SUCCESS;
        }

        if ($format === 'json') {
            $this->inspectJson($body);

            return self::SUCCESS;
        }

        $this->warn('Response is neither XML nor JSON. First 500 chars:');
        $this->line(mb_substr($body, 0, 500));

        return self::SUCCESS;
    }

    private function inspectXml(PalomaClient $client, string $body): void
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if (! $xml instanceof SimpleXMLElement) {
            $this->error('XML parsing failed.');

            foreach (libxml_get_errors() as $error) {
                $this->line(trim($error->message));
            }

            libxml_clear_errors();

            return;
        }

        $this->table(['XML metric', 'Value'], [
            ['Root element', $xml->getName()],
            ['Unique tags', implode(', ', array_slice($this->tagNames($xml), 0, 80))],
            ['//offer', count($xml->xpath('//offer') ?: [])],
            ['//offers/offer', count($xml->xpath('//offers/offer') ?: [])],
            ['//kaspi_catalog/offers/offer', count($xml->xpath('//kaspi_catalog/offers/offer') ?: [])],
            ['//item', count($xml->xpath('//item') ?: [])],
            ['//product', count($xml->xpath('//product') ?: [])],
            ['//good', count($xml->xpath('//good') ?: [])],
            ['local-name offer', count($client->xmlNodes($xml, ['offer']))],
            ['local-name item/product/good', count($client->xmlNodes($xml, ['item', 'product', 'good']))],
        ]);

        $this->line('First 3 XML levels:');
        $this->line($this->xmlTree($xml));

        $potentialNodes = array_slice($client->xmlNodes($xml, ['offer', 'item', 'product', 'good']), 0, 5);

        if ($potentialNodes === []) {
            $this->warn('No potential product nodes found by offer/item/product/good.');

            return;
        }

        $this->line('First potential product nodes:');

        foreach ($potentialNodes as $index => $node) {
            $attributes = $this->directAttributeNames($node);
            $this->line('#'.($index + 1).' <'.$node->getName().'> attributes: '.implode(', ', $attributes).'; children: '.implode(', ', $this->directChildNames($node)));
        }
    }

    private function inspectJson(string $body): void
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            $this->error('JSON parsing failed.');

            return;
        }

        $this->table(['JSON metric', 'Value'], [
            ['Root type', array_is_list($decoded) ? 'array' : 'object'],
            ['Top-level keys', implode(', ', array_slice(array_keys($decoded), 0, 80))],
        ]);
    }

    private function saveRawResponse(string $body, string $format): string
    {
        $extension = match ($format) {
            'json' => 'json',
            'xml' => 'xml',
            default => 'txt',
        };

        $path = 'debug/paloma-response.'.$extension;
        $fullPath = storage_path('app/'.$path);
        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($fullPath, $body);

        return $path;
    }

    private function detectFormat(string $body, string $contentType): string
    {
        $contentType = strtolower($contentType);
        $trimmed = ltrim($body);

        if (str_contains($contentType, 'json') || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return 'json';
        }

        if (str_contains($contentType, 'xml') || str_starts_with($trimmed, '<')) {
            return 'xml';
        }

        return 'text';
    }

    /**
     * @return array<int, string>
     */
    private function tagNames(SimpleXMLElement $xml): array
    {
        $names = [];

        $walk = function (SimpleXMLElement $node) use (&$walk, &$names): void {
            $names[] = $node->getName();

            foreach ($node->children() as $child) {
                $walk($child);
            }

            foreach ($node->getNamespaces(true) as $namespace) {
                foreach ($node->children($namespace) as $child) {
                    $walk($child);
                }
            }
        };

        $walk($xml);

        return array_values(array_unique($names));
    }

    private function xmlTree(SimpleXMLElement $xml, int $level = 0, int $maxLevel = 2): string
    {
        $line = str_repeat('  ', $level).'- '.$xml->getName();

        if ($level >= $maxLevel) {
            return $line;
        }

        $children = array_slice($this->allChildren($xml), 0, 12);

        foreach ($children as $child) {
            $line .= PHP_EOL.$this->xmlTree($child, $level + 1, $maxLevel);
        }

        return $line;
    }

    /**
     * @return array<int, string>
     */
    private function directChildNames(SimpleXMLElement $xml): array
    {
        return array_values(array_unique(array_map(
            fn (SimpleXMLElement $child): string => $child->getName(),
            $this->allChildren($xml),
        )));
    }

    /**
     * @return array<int, string>
     */
    private function directAttributeNames(SimpleXMLElement $xml): array
    {
        $names = [];

        foreach ($xml->attributes() as $name => $value) {
            $names[] = (string) $name;
        }

        foreach ($xml->getNamespaces(true) as $namespace) {
            foreach ($xml->attributes($namespace) as $name => $value) {
                $names[] = (string) $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<int, SimpleXMLElement>
     */
    private function allChildren(SimpleXMLElement $xml): array
    {
        $children = [];

        foreach ($xml->children() as $child) {
            $children[] = $child;
        }

        foreach ($xml->getNamespaces(true) as $namespace) {
            foreach ($xml->children($namespace) as $child) {
                $children[] = $child;
            }
        }

        return $children;
    }
}
