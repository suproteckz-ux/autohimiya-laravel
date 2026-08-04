<?php

namespace Tests\Feature;

use App\Models\KaspiProductionPush;
use App\Services\Kaspi\KaspiLocalPageCollector;
use App\Services\Kaspi\KaspiLocalNodeProcessRunner;
use App\Services\Kaspi\KaspiLocalUrlResolver;
use App\Services\Kaspi\KaspiProductionBridgeService;
use App\Services\Kaspi\KaspiProductionCandidateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KaspiProductionBridgeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kaspi.production_api_url' => 'https://production.test/api/internal/kaspi-content/import',
            'services.kaspi.production_api_token' => 'local-secret',
        ]);
    }

    public function test_dry_run_collects_and_validates_without_sending_http(): void
    {
        $collector = $this->fakeCollector();
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([['sku' => 'aut_608', 'name' => 'Remote product', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/aut-608/', 'has_images' => false, 'has_description' => false]]));
        app()->instance(KaspiLocalPageCollector::class, $collector);
        Http::fake();

        $result = app(KaspiProductionBridgeService::class)->push(['sku' => ['aut_608'], 'dry_run' => true]);

        $this->assertTrue($result['successful'], json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $result['metrics']['collected']);
        $this->assertSame(1, KaspiProductionPush::query()->count());
        $this->assertSame(1, $collector->calls);
        Http::assertNothingSent();
    }

    public function test_dry_run_calls_candidate_endpoint_but_not_import_endpoint(): void
    {
        app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector());
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/candidates')) {
                return Http::response([
                    'data' => [[
                        'sku' => 'aut_608',
                        'name' => 'Remote product',
                        'kaspi_product_url' => 'https://kaspi.kz/shop/p/aut-608/',
                        'has_images' => false,
                        'has_description' => false,
                        'has_attributes' => false,
                        'manual_content_protected' => false,
                    ]],
                    'next_cursor' => null,
                ], 200);
            }

            return Http::response(['ok' => false, 'error' => 'unexpected_import_call'], 500);
        });

        app(KaspiProductionBridgeService::class)->push(['sku' => ['aut_608'], 'dry_run' => true]);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/candidates'));
        Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), '/import'));
    }

    public function test_valid_payload_is_built_from_local_parsed_content(): void
    {
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([['sku' => 'aut_608', 'name' => 'Remote product', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/aut-608/', 'has_images' => false, 'has_description' => true]]));
        app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector());

        app(KaspiProductionBridgeService::class)->push(['sku' => ['aut_608'], 'dry_run' => true]);

        $payload = KaspiProductionPush::query()->firstOrFail()->collected_payload;
        $this->assertSame(1, $payload['version']);
        $this->assertSame('aut_608', $payload['sku']);
        $this->assertSame('local-playwright', $payload['source']['collector']);
        $this->assertSame('Parsed name', $payload['content']['name']);
        $this->assertCount(1, $payload['content']['images']);
    }

    public function test_success_and_common_permanent_responses_are_recorded(): void
    {
        $cases = [
            [200, ['ok' => true, 'status' => 'imported'], 'imported', 'sent'],
            [200, ['ok' => true, 'status' => 'unchanged'], 'unchanged', 'sent'],
            [401, ['ok' => false, 'error' => 'unauthorized'], 'unauthorized', 'failed'],
            [404, ['ok' => false, 'error' => 'product_not_found'], 'product_not_found', 'failed'],
            [409, ['ok' => false, 'error' => 'manual_content_protected'], 'manual_content_protected', 'failed'],
            [422, ['ok' => false, 'error' => 'validation_failed'], 'validation_failed', 'failed'],
        ];

        $sequence = Http::sequence();
        foreach ($cases as [$httpStatus, $body]) {
            $sequence->push($body, $httpStatus);
        }
        Http::fake(['production.test/*' => $sequence]);

        foreach ($cases as [$httpStatus, $body, $productionStatus, $localStatus]) {
            $sku = 'sku_'.$httpStatus.'_'.str_replace('_', '-', $productionStatus);
            app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([['sku' => $sku, 'name' => 'Remote product', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/'.$sku.'/', 'has_images' => false, 'has_description' => false]]));
            app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector());

            app(KaspiProductionBridgeService::class)->push(['sku' => [$sku]]);

            $push = KaspiProductionPush::query()->latest('id')->firstOrFail();
            $this->assertSame($localStatus, $push->status);
            $this->assertSame($productionStatus, $push->production_status, json_encode($push->response_summary));
            $this->assertSame($httpStatus, $push->http_status);
        }
    }

    public function test_5xx_retries_are_bounded_and_network_failure_remains_retryable(): void
    {
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([['sku' => 'aut_608', 'name' => 'Remote product', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/aut-608/', 'has_images' => false, 'has_description' => false]]));
        app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector());
        Http::fakeSequence('production.test/*')
            ->push(['ok' => false, 'error' => 'server_error'], 500)
            ->push(['ok' => false, 'error' => 'server_error'], 500)
            ->push(['ok' => true, 'status' => 'imported'], 200);

        app(KaspiProductionBridgeService::class)->push(['sku' => ['aut_608']]);

        Http::assertSentCount(3);
        $this->assertSame('sent', KaspiProductionPush::query()->firstOrFail()->status);

        KaspiProductionPush::query()->delete();
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([['sku' => 'aut_609', 'name' => 'Remote product', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/aut-609/', 'has_images' => false, 'has_description' => false]]));
        app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector());
        Http::fake(fn () => throw new ConnectionException('network failed'));

        $result = app(KaspiProductionBridgeService::class)->push(['sku' => ['aut_609']]);
        $this->assertFalse($result['successful']);
        $this->assertSame('collected', KaspiProductionPush::query()->firstOrFail()->status);
    }

    public function test_one_sku_failure_does_not_stop_other_skus(): void
    {
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([
            ['sku' => 'bad_sku', 'name' => 'Bad', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/bad-sku/', 'has_images' => false, 'has_description' => false],
            ['sku' => 'good_sku', 'name' => 'Good', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/good-sku/', 'has_images' => false, 'has_description' => false],
        ]));
        app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector(failSku: 'bad_sku'));
        Http::fake(['production.test/*' => Http::response(['ok' => true, 'status' => 'imported'], 200)]);

        $result = app(KaspiProductionBridgeService::class)->push(['sku' => ['bad_sku', 'good_sku']]);

        $this->assertFalse($result['successful']);
        $this->assertSame(1, $result['metrics']['failed']);
        $this->assertSame(1, KaspiProductionPush::query()->count());
        $this->assertSame('good_sku', KaspiProductionPush::query()->firstOrFail()->sku);
    }

    public function test_already_collected_payload_can_be_retried_without_rerunning_collector(): void
    {
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([['sku' => 'aut_608', 'name' => 'Remote product', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/aut-608/', 'has_images' => false, 'has_description' => false]]));
        KaspiProductionPush::query()->create([
            'product_id' => null,
            'sku' => 'aut_608',
            'kaspi_url' => 'https://kaspi.kz/shop/p/aut-608/',
            'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf470000',
            'content_hash' => str_repeat('a', 64),
            'collected_payload' => $this->payload('aut_608'),
            'status' => 'failed',
            'collected_at' => now(),
        ]);
        app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector(failSku: 'aut_608'));
        Http::fake(['production.test/*' => Http::response(['ok' => true, 'status' => 'imported'], 200)]);

        app(KaspiProductionBridgeService::class)->push(['sku' => ['aut_608']]);

        $this->assertSame(1, KaspiProductionPush::query()->count());
        $this->assertSame('sent', KaspiProductionPush::query()->firstOrFail()->status);
    }

    public function test_command_output_never_prints_bearer_token(): void
    {
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([['sku' => 'aut_608', 'name' => 'Remote product', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/aut-608/', 'has_images' => false, 'has_description' => false]]));
        app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector());
        Http::fake(['production.test/*' => Http::response(['ok' => true, 'status' => 'imported'], 200)]);

        Artisan::call('kaspi:push-production', ['--sku' => ['aut_608']]);

        $this->assertStringNotContainsString('local-secret', Artisan::output());
    }

    public function test_debug_command_prints_candidate_exclusion_reason_for_requested_sku(): void
    {
        Http::fake(['production.test/*' => Http::response([
            'data' => [],
            'next_cursor' => null,
            'diagnostics' => [
                'total_products' => 1,
                'returned_candidate_count' => 0,
                'rejected' => [
                    'manual_content_protected' => 0,
                    'has_images' => 1,
                    'has_description' => 1,
                    'has_attributes' => 0,
                    'missing_kaspi_url' => 0,
                    'sku_filter' => 0,
                    'other' => 0,
                ],
                'requested_skus' => [[
                    'sku' => 'aut_608',
                    'found' => true,
                    'manual_content_protected' => false,
                    'has_images' => true,
                    'has_description' => true,
                    'has_attributes' => false,
                    'kaspi_url' => 'present',
                    'excluded_because' => 'has_images_and_has_description',
                ]],
            ],
        ], 200)]);

        Artisan::call('kaspi:push-production', ['--sku' => ['aut_608'], '--dry-run' => true, '--debug' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('Total products: 1', $output);
        $this->assertStringContainsString('SKU: aut_608', $output);
        $this->assertStringContainsString('manual_content_protected = false', $output);
        $this->assertStringContainsString('has_images = true', $output);
        $this->assertStringContainsString('has_description = true', $output);
        $this->assertStringContainsString('has_attributes = false', $output);
        $this->assertStringContainsString('kaspi_url = present', $output);
        $this->assertStringContainsString('excluded because has_images_and_has_description', $output);
        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'debug=true'));
    }

    public function test_local_command_works_without_local_product_record_and_uses_candidate_sku_and_url(): void
    {
        $collector = $this->fakeCollector();
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([['sku' => 'remote_1', 'name' => 'Remote product', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/remote-1/', 'has_images' => false, 'has_description' => false]]));
        app()->instance(KaspiLocalPageCollector::class, $collector);

        app(KaspiProductionBridgeService::class)->push(['sku' => ['remote_1'], 'dry_run' => true]);

        $this->assertSame(0, \App\Models\Product::query()->count());
        $this->assertSame('remote_1', KaspiProductionPush::query()->firstOrFail()->sku);
        $this->assertSame('https://kaspi.kz/shop/p/remote-1/', $collector->urls[0]);
    }

    public function test_missing_url_invokes_resolver_and_existing_url_skips_resolution(): void
    {
        $resolver = $this->fakeResolver();
        app()->instance(KaspiProductionCandidateClient::class, $this->fakeCandidateClient([
            ['sku' => 'missing_url', 'name' => 'Missing URL', 'kaspi_product_url' => null, 'has_images' => false, 'has_description' => false],
            ['sku' => 'existing_url', 'name' => 'Existing URL', 'kaspi_product_url' => 'https://kaspi.kz/shop/p/existing-url/', 'has_images' => false, 'has_description' => false],
        ]));
        app()->instance(KaspiLocalUrlResolver::class, $resolver);
        app()->instance(KaspiLocalPageCollector::class, $this->fakeCollector());

        app(KaspiProductionBridgeService::class)->push(['limit' => 2, 'dry_run' => true]);

        $this->assertSame(['missing_url'], $resolver->skus);
        $this->assertSame(
            ['https://kaspi.kz/shop/p/resolved-missing-url/', 'https://kaspi.kz/shop/p/existing-url/'],
            KaspiProductionPush::query()->orderBy('id')->pluck('kaspi_url')->all()
        );
    }

    public function test_local_url_resolver_prefers_widget_and_builds_storefront_url_from_name(): void
    {
        config(['app.url' => 'https://xn--80aesatk1az7g.kz']);
        $runner = $this->fakeNodeRunner([
            $this->processJson(['ok' => true, 'sku' => 'FDB060', 'url' => 'https://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/?ksWidget=true&m=30366013', 'method' => 'widget', 'reason' => null]),
        ]);

        $result = (new KaspiLocalUrlResolver($runner))->resolve('FDB060', 'Mitsiji щетка для чернения шин 6х6 см FDB060', true);

        $this->assertSame('https://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/', $result['url']);
        $this->assertSame('widget', $result['method']);
        $this->assertCount(1, $runner->runs);
        $this->assertStringEndsWith('scripts/kaspi-widget-resolver.mjs', str_replace('\\', '/', $runner->runs[0]['script']));
        $this->assertStringContainsString('/product/mitsiji-schetka-dlya-cherneniya-shin-6h6-sm-fdb060', $runner->runs[0]['arguments']['url']);
        $this->assertSame(base_path(), $runner->runs[0]['cwd']);
    }

    public function test_local_url_resolver_does_not_prefix_www_for_loopback_storefront_urls(): void
    {
        foreach (['http://127.0.0.1:8001', 'http://localhost:8001'] as $appUrl) {
            config(['app.url' => $appUrl]);
            $runner = $this->fakeNodeRunner([
                $this->processJson(['ok' => false, 'sku' => 'FDB060', 'url' => null, 'method' => 'widget', 'reason' => 'not_found']),
                $this->processJson(['ok' => true, 'sku' => 'FDB060', 'url' => 'https://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/', 'method' => 'search', 'reason' => null]),
            ]);

            $result = (new KaspiLocalUrlResolver($runner))->resolve('FDB060', 'Loopback product', true);

            $this->assertSame('search', $result['method']);
            $this->assertCount(2, $runner->runs);
            $this->assertStringStartsWith($appUrl.'/product/', $runner->runs[0]['arguments']['url']);
            $this->assertStringNotContainsString('www.127.0.0.1', $runner->runs[0]['arguments']['url']);
            $this->assertStringNotContainsString('www.localhost', $runner->runs[0]['arguments']['url']);
        }
    }
    public function test_existing_production_url_skips_widget_and_search_resolution(): void
    {
        $runner = $this->fakeNodeRunner([]);

        $result = (new KaspiLocalUrlResolver($runner))->resolve('FDB060', 'Ignored', true, [
            'existing_url' => 'https://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/?m=30366013',
        ]);

        $this->assertSame('https://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/', $result['url']);
        $this->assertSame('existing', $result['method']);
        $this->assertSame([], $runner->runs);
    }

    public function test_widget_not_found_triggers_conservative_search_fallback(): void
    {
        $runner = $this->fakeNodeRunner([
            $this->processJson(['ok' => false, 'sku' => 'FDB060', 'url' => null, 'method' => 'widget', 'reason' => 'not_found']),
            $this->processJson(['ok' => true, 'sku' => 'FDB060', 'url' => 'https://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/', 'method' => 'search', 'reason' => null]),
        ]);

        $result = (new KaspiLocalUrlResolver($runner))->resolve('FDB060', 'Mitsiji щетка', true, ['storefront_url' => 'https://www.xn--80aesatk1az7g.kz/product/fdb060']);

        $this->assertSame('search', $result['method']);
        $this->assertCount(2, $runner->runs);
        $this->assertStringEndsWith('scripts/kaspi-search-url-resolver.mjs', str_replace('\\', '/', $runner->runs[1]['script']));
    }

    public function test_widget_browser_error_is_reported_without_search_fallback(): void
    {
        $runner = $this->fakeNodeRunner([
            $this->processJson(['ok' => false, 'sku' => 'FDB060', 'url' => null, 'method' => 'widget', 'reason' => 'browser_error'], exitCode: 1, stderr: 'browser failed'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('url_resolver_browser_error');

        try {
            (new KaspiLocalUrlResolver($runner))->resolve('FDB060', 'Mitsiji щетка', true, ['storefront_url' => 'https://www.xn--80aesatk1az7g.kz/product/fdb060']);
        } finally {
            $this->assertCount(1, $runner->runs);
        }
    }

    public function test_resolver_reports_empty_stdout_malformed_json_noise_and_nonzero_exit(): void
    {
        foreach ([
            ['', 0, '', 'url_resolver_empty_stdout'],
            ['{bad json', 0, '', 'url_resolver_invalid_json'],
            ['progress'.PHP_EOL.'{"ok":true}', 0, '', 'url_resolver_invalid_json'],
            [json_encode(['ok' => false, 'sku' => 'FDB060', 'url' => null, 'method' => 'widget', 'reason' => 'not_found']), 1, '', 'url_resolver_nonzero_exit'],
        ] as [$stdout, $exitCode, $stderr, $message]) {
            $runner = $this->fakeNodeRunner([$this->process($stdout, $exitCode, $stderr)]);

            try {
                (new KaspiLocalUrlResolver($runner))->resolve('FDB060', 'Mitsiji щетка', true, ['storefront_url' => 'https://www.xn--80aesatk1az7g.kz/product/fdb060']);
                $this->fail('Expected resolver exception.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
                $this->assertStringContainsString('stdout_bytes', $exception->getMessage());
            }
        }
    }

    public function test_resolver_accepts_bom_json_and_stderr_warnings(): void
    {
        $runner = $this->fakeNodeRunner([
            $this->process("\xEF\xBB\xBF".json_encode(['ok' => true, 'sku' => 'FDB060', 'url' => 'https://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/', 'method' => 'widget', 'reason' => null]), 0, 'warning on stderr'),
        ]);

        $result = (new KaspiLocalUrlResolver($runner))->resolve('FDB060', 'Mitsiji щетка', true, ['storefront_url' => 'https://www.xn--80aesatk1az7g.kz/product/fdb060']);

        $this->assertSame('https://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/', $result['url']);
        $this->assertSame(17, $result['diagnostics']['stderr_bytes']);
    }

    public function test_resolver_rejects_invalid_hosts_non_https_non_product_paths_and_sku_mismatch(): void
    {
        foreach ([
            'https://example.test/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/',
            'http://kaspi.kz/shop/p/mitsuji-schetka-fdb060-1-sht-171100340/',
            'https://kaspi.kz/shop/search/?text=FDB060',
            'https://kaspi.kz/shop/p/another-product-123/',
        ] as $url) {
            $runner = $this->fakeNodeRunner([
                $this->processJson(['ok' => true, 'sku' => 'FDB060', 'url' => $url, 'method' => 'widget', 'reason' => null]),
            ]);

            try {
                (new KaspiLocalUrlResolver($runner))->resolve('FDB060', 'Mitsiji щетка', true, ['storefront_url' => 'https://www.xn--80aesatk1az7g.kz/product/fdb060']);
                $this->fail('Expected invalid URL exception.');
            } catch (\RuntimeException $exception) {
                $this->assertContains($exception->getMessage(), ['url_resolver_invalid_url', 'url_resolver_sku_mismatch']);
            }
        }
    }

    private function payload(string $sku): array
    {
        return [
            'version' => 1,
            'request_id' => '8fb91896-7e3d-4f74-bf7f-b0d9bf470000',
            'collected_at' => '2026-08-03T20:00:00+05:00',
            'sku' => $sku,
            'kaspi_url' => 'https://kaspi.kz/shop/p/'.$sku.'/',
            'content' => [
                'name' => 'Parsed name',
                'description' => '<p>Parsed description</p>',
                'attributes' => [['name' => 'Объем', 'value' => '1 л']],
                'images' => [['url' => 'https://resources.cdn-kaspi.kz/img/m/p/test/product/image.png', 'position' => 1]],
            ],
            'source' => ['collector' => 'local-playwright', 'parser_version' => '1'],
        ];
    }

    private function fakeCollector(?string $failSku = null): KaspiLocalPageCollector
    {
        return new class($failSku) extends KaspiLocalPageCollector {
            public int $calls = 0;
            public array $urls = [];

            public function __construct(private readonly ?string $failSku = null)
            {
            }

            public function collectUrl(string $url, string $sku, bool $debug = false): array
            {
                $this->calls++;
                $this->urls[] = $url;
                if ($this->failSku === $sku) {
                    throw new \RuntimeException('collector_failed');
                }

                return [
                    'url' => $url,
                    'http_status' => 200,
                    'captcha' => false,
                    'parser_payload' => [
                        'name' => 'Parsed name',
                        'description' => '<p>Parsed description</p>',
                        'cleaned' => [
                            'images' => ['https://resources.cdn-kaspi.kz/img/m/p/test/product/image.png'],
                            'attributes' => [['name' => 'Объем', 'value' => '1 л']],
                        ],
                    ],
                    'artifact_dir' => null,
                ];
            }
        };
    }

    private function fakeCandidateClient(array $candidates): KaspiProductionCandidateClient
    {
        return new class($candidates) extends KaspiProductionCandidateClient {
            public function __construct(private readonly array $candidates)
            {
            }

            public function fetch(array $options = []): array
            {
                return $this->candidates;
            }
        };
    }

    private function fakeResolver(): KaspiLocalUrlResolver
    {
        return new class extends KaspiLocalUrlResolver {
            public array $skus = [];

            public function resolve(string $sku, ?string $name = null, bool $debug = false, array $options = []): array
            {
                $this->skus[] = $sku;

                return ['url' => 'https://kaspi.kz/shop/p/resolved-'.str_replace('_', '-', $sku).'/', 'status' => 'resolved', 'method' => 'widget'];
            }
        };
    }

    private function fakeNodeRunner(array $responses): KaspiLocalNodeProcessRunner
    {
        return new class($responses) extends KaspiLocalNodeProcessRunner {
            public array $runs = [];

            public function __construct(private array $responses)
            {
            }

            public function run(string $script, array $arguments, int $timeoutSeconds = 90): array
            {
                $this->runs[] = [
                    'script' => $script,
                    'arguments' => $arguments,
                    'timeout' => $timeoutSeconds,
                    'cwd' => base_path(),
                ];

                $response = array_shift($this->responses) ?: ['stdout' => '', 'stderr' => '', 'exit_code' => 1];
                $command = ['node', $script];
                foreach ($arguments as $name => $value) {
                    if ($value !== null) {
                        $command[] = '--'.$name.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
                    }
                }

                return [
                    'command' => $command,
                    'script' => $script,
                    'cwd' => base_path(),
                    'exit_code' => $response['exit_code'],
                    'stdout' => $response['stdout'],
                    'stderr' => $response['stderr'],
                ];
            }
        };
    }

    private function processJson(array $payload, int $exitCode = 0, string $stderr = ''): array
    {
        return $this->process(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $exitCode, $stderr);
    }

    private function process(string $stdout, int $exitCode = 0, string $stderr = ''): array
    {
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
    }
}
