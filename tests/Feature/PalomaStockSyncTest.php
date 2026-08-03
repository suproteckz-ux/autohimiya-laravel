<?php

namespace Tests\Feature;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Automation\AutomationRunner;
use App\Services\Automation\AutomationRunService;
use App\Services\Paloma\PalomaCatalogAggregator;
use App\Services\Paloma\PalomaClient;
use App\Services\Paloma\PalomaSyncRemainsService;
use App\Support\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PalomaStockSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_paloma_offer_rows_do_not_multiply_stock(): void
    {
        $offers = (new PalomaClient())->parseXml($this->duplicateStockFeed());
        $aggregated = collect((new PalomaCatalogAggregator())->aggregate($offers))->keyBy('sku');

        $this->assertSame(1, $aggregated['aut_12']->stock);
        $this->assertSame(4, $aggregated['aut_16']->stock);
        $this->assertSame(3, $aggregated['aut_12']->duplicate_availability_count);
        $this->assertSame(3, $aggregated['aut_16']->duplicate_availability_count);
    }

    public function test_availability_entries_follow_authoritative_stock_rules(): void
    {
        $offers = (new PalomaClient())->parseXml(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<catalog>
    <offers>
        <offer sku="multi"><model>multi</model><price>100</price><availabilities>
            <availability storeId="a" stockCount="2" available="yes"/>
            <availability storeId="a" stockCount="2" available="yes"/>
            <availability storeId="b" stockCount="3" available="yes"/>
        </availabilities></offer>
        <offer sku="closed"><model>closed</model><price>100</price><availability storeId="a" stockCount="8" available="no"/></offer>
        <offer sku="invalid"><model>invalid</model><price>100</price><availability storeId="a" stockCount="1.5" available="yes"/></offer>
        <offer sku="negative"><model>negative</model><price>100</price><availability storeId="a" stockCount="-2" available="yes"/></offer>
        <offer sku="conflict"><model>conflict</model><price>100</price><availabilities>
            <availability storeId="a" stockCount="2" available="yes"/>
            <availability storeId="a" stockCount="5" available="yes"/>
        </availabilities></offer>
    </offers>
</catalog>
XML);
        $aggregated = collect((new PalomaCatalogAggregator())->aggregate($offers))->keyBy('sku');

        $this->assertSame(5, $aggregated['multi']->stock);
        $this->assertSame(1, $aggregated['multi']->duplicate_availability_count);
        $this->assertSame(0, $aggregated['closed']->stock);
        $this->assertFalse($aggregated['closed']->available);
        $this->assertSame(0, $aggregated['invalid']->stock);
        $this->assertSame(1, $aggregated['invalid']->invalid_availability_count);
        $this->assertSame(0, $aggregated['negative']->stock);
        $this->assertSame(5, $aggregated['conflict']->stock);
        $this->assertTrue($aggregated['conflict']->has_stock_conflict);
    }

    public function test_sync_replaces_quantity_idempotently_for_aut_12_and_aut_16(): void
    {
        $this->bindPalomaXml($this->duplicateStockFeed());
        $this->createProduct('aut_12', 99);
        $this->createProduct('aut_16', 99);

        $first = app(PalomaSyncRemainsService::class)->sync();

        $this->assertSame(2, $first['updated_count']);
        $this->assertSame(1, Product::query()->where('sku', 'aut_12')->value('quantity'));
        $this->assertSame(1, Product::query()->where('sku', 'aut_12')->value('stock_quantity'));
        $this->assertSame(4, Product::query()->where('sku', 'aut_16')->value('quantity'));
        $this->assertSame(4, Product::query()->where('sku', 'aut_16')->value('stock_quantity'));

        $second = app(PalomaSyncRemainsService::class)->sync();

        $this->assertSame(2, $second['updated_count']);
        $this->assertSame(1, Product::query()->where('sku', 'aut_12')->value('quantity'));
        $this->assertSame(4, Product::query()->where('sku', 'aut_16')->value('quantity'));
        $this->assertSame(2, SyncLog::query()->where('source', 'paloma')->where('mode', 'sync-remains')->count());
    }

    public function test_found_sku_is_replaced_and_absent_skus_are_zeroed_after_valid_complete_feed(): void
    {
        $this->bindPalomaXml($this->singleOfferFeed('present', 7));
        $this->createProduct('present', 99);
        $this->createProduct('missing-one', 5);
        $this->createProduct('missing-two', 6);

        $result = app(PalomaSyncRemainsService::class)->sync();

        $this->assertSame(1, $result['updated_count']);
        $this->assertSame(2, $result['absent_zeroed_count']);
        $this->assertSame(7, Product::query()->where('sku', 'present')->value('quantity'));
        $this->assertSame(7, Product::query()->where('sku', 'present')->value('stock_quantity'));
        $this->assertSame(0, Product::query()->where('sku', 'missing-one')->value('quantity'));
        $this->assertSame(0, Product::query()->where('sku', 'missing-one')->value('stock_quantity'));
        $this->assertSame(0, Product::query()->where('sku', 'missing-two')->value('quantity'));
        $this->assertSame(0, Product::query()->where('sku', 'missing-two')->value('stock_quantity'));
    }

    public function test_repeated_valid_feed_keeps_present_stock_and_missing_skus_zeroed(): void
    {
        $this->bindPalomaXml($this->singleOfferFeed('present', 7));
        $this->createProduct('present', 99);
        $this->createProduct('missing', 6);

        app(PalomaSyncRemainsService::class)->sync();
        app(PalomaSyncRemainsService::class)->sync();

        $this->assertSame(7, Product::query()->where('sku', 'present')->value('quantity'));
        $this->assertSame(7, Product::query()->where('sku', 'present')->value('stock_quantity'));
        $this->assertSame(0, Product::query()->where('sku', 'missing')->value('quantity'));
        $this->assertSame(0, Product::query()->where('sku', 'missing')->value('stock_quantity'));
    }

    public function test_automation_runner_uses_same_paloma_stock_service(): void
    {
        $this->bindPalomaXml($this->duplicateStockFeed());
        $this->createProduct('aut_12', 99);

        $run = app(AutomationRunService::class)->request(AutomationType::PalomaSyncRemains, AutomationRunSource::System)['run'];
        $summary = app(AutomationRunner::class)->runPending(runId: $run->id, limit: 1);

        $run->refresh();
        $this->assertSame(1, $summary['processed']);
        $this->assertContains($run->status, [AutomationRunStatus::Completed->value, AutomationRunStatus::CompletedWithWarnings->value]);
        $this->assertSame(1, Product::query()->where('sku', 'aut_12')->value('quantity'));
    }

    public function test_duplicate_pending_paloma_runs_are_prevented(): void
    {
        $service = app(AutomationRunService::class);

        $first = $service->request(AutomationType::PalomaSyncRemains, AutomationRunSource::Admin);
        $second = $service->request(AutomationType::PalomaSyncRemains, AutomationRunSource::Admin);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['run']->id, $second['run']->id);
        $this->assertSame(1, AutomationRun::query()->where('type', AutomationType::PalomaSyncRemains->value)->count());
    }

    public function test_empty_feed_does_not_zero_products(): void
    {
        $this->bindPalomaXml('<?xml version="1.0" encoding="UTF-8"?><catalog><offers></offers></catalog>');
        $this->createProduct('aut_12', 9);

        $this->assertSyncFails('empty or contains no valid SKU');

        $this->assertSame(9, Product::query()->where('sku', 'aut_12')->value('quantity'));
        $this->assertSame(9, Product::query()->where('sku', 'aut_12')->value('stock_quantity'));
        $this->assertSame('failed', SyncLog::query()->where('source', 'paloma')->latest('id')->value('status'));
    }

    public function test_malformed_feed_does_not_zero_products(): void
    {
        $this->app->instance(PalomaClient::class, new class extends PalomaClient {
            public function offers(): array
            {
                return $this->parseXml('<catalog><offers><offer sku="aut_12"></offers>');
            }
        });
        $this->createProduct('aut_12', 9);

        $this->assertSyncFails('Unable to parse Paloma XML');

        $this->assertSame(9, Product::query()->where('sku', 'aut_12')->value('quantity'));
        $this->assertSame(9, Product::query()->where('sku', 'aut_12')->value('stock_quantity'));
        $this->assertSame('failed', SyncLog::query()->where('source', 'paloma')->latest('id')->value('status'));
    }

    public function test_failed_feed_request_does_not_zero_products(): void
    {
        $this->createProduct('aut_12', 9);
        $this->app->instance(PalomaClient::class, new class extends PalomaClient {
            public function offers(): array
            {
                throw new RuntimeException('Paloma endpoint returned HTTP 500.');
            }
        });

        $this->assertSyncFails('Paloma endpoint returned HTTP 500');

        $this->assertSame(9, Product::query()->where('sku', 'aut_12')->value('quantity'));
        $this->assertSame(9, Product::query()->where('sku', 'aut_12')->value('stock_quantity'));
        $this->assertSame('failed', SyncLog::query()->where('source', 'paloma')->latest('id')->value('status'));
    }

    private function assertSyncFails(string $expectedMessage): void
    {
        try {
            app(PalomaSyncRemainsService::class)->sync();
            $this->fail('Paloma sync should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    private function bindPalomaXml(string $xml): void
    {
        $this->app->instance(PalomaClient::class, new class($xml) extends PalomaClient {
            public function __construct(private readonly string $xml) {}

            public function offers(): array
            {
                return $this->parseXml($this->xml);
            }
        });
    }

    private function createProduct(string $sku, int $quantity): Product
    {
        return Product::query()->create([
            'name' => 'Product '.$sku,
            'slug' => 'product-'.$sku,
            'sku' => $sku,
            'paloma_sku' => $sku,
            'price' => 1000,
            'quantity' => $quantity,
            'stock_quantity' => $quantity,
            'availability' => $quantity > 0,
            'availability_status' => $quantity > 0 ? 'in_stock' : 'out_of_stock',
            'product_status' => ProductStatus::ACTIVE_SYNCED,
            'sync_status' => 'matched',
        ]);
    }

    private function duplicateStockFeed(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<catalog>
    <offers>
        <offer sku="aut_12"><model>aut_12</model><price>100</price><availabilities><availability storeId="main" stockCount="1" available="yes"/></availabilities></offer>
        <offer sku="aut_12"><model>aut_12</model><price>100</price><availabilities><availability storeId="main" stockCount="1" available="yes"/></availabilities></offer>
        <offer sku="aut_12"><model>aut_12</model><price>100</price><availabilities><availability storeId="main" stockCount="1" available="yes"/></availabilities></offer>
        <offer sku="aut_12"><model>aut_12</model><price>100</price><availabilities><availability storeId="main" stockCount="1" available="yes"/></availabilities></offer>
        <offer sku="aut_16"><model>aut_16</model><price>200</price><availabilities><availability storeId="main" stockCount="4" available="yes"/></availabilities></offer>
        <offer sku="aut_16"><model>aut_16</model><price>200</price><availabilities><availability storeId="main" stockCount="4" available="yes"/></availabilities></offer>
        <offer sku="aut_16"><model>aut_16</model><price>200</price><availabilities><availability storeId="main" stockCount="4" available="yes"/></availabilities></offer>
        <offer sku="aut_16"><model>aut_16</model><price>200</price><availabilities><availability storeId="main" stockCount="4" available="yes"/></availabilities></offer>
    </offers>
</catalog>
XML;
    }

    private function singleOfferFeed(string $sku, int $stock): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<catalog>
    <offers>
        <offer sku="{$sku}"><model>{$sku}</model><price>100</price><availability storeId="main" stockCount="{$stock}" available="yes"/></offer>
    </offers>
</catalog>
XML;
    }
}
