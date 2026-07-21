<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_admin_access_is_allowed_only_for_administrators(): void
    {
        $panel = Mockery::mock(Panel::class);
        $panel->shouldReceive('getId')->andReturn('admin');

        $admin = new User(['is_admin' => true]);
        $user = new User(['is_admin' => false]);

        $this->assertTrue($admin->canAccessPanel($panel));
        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_app_url_example_remains_punycode(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('APP_URL=https://xn--80aesatk1az7g.kz', $envExample);
        $this->assertStringNotContainsString('APP_URL=https://автохимия.kz', $envExample);
    }

    public function test_no_web_or_filament_path_calls_forbidden_process_functions(): void
    {
        $paths = array_merge($this->phpFiles(base_path('app/Filament')), $this->phpFiles(base_path('app/Http')));
        $forbidden = ['Symfony\Component\Process', 'new Process', 'Process::', 'proc_open', 'shell_exec', 'exec(', 'system(', 'passthru(', 'popen('];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $contents, $path.' contains '.$needle);
            }
        }
    }

    public function test_scheduler_registers_pending_processing_and_short_lived_queue_worker(): void
    {
        $routes = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('automation:run-pending --limit=1', $routes);
        $this->assertStringContainsString('queue:work --stop-when-empty --tries=3 --timeout=120', $routes);
        $this->assertStringContainsString('AutomationType::PalomaSyncRemains', $routes);
        $this->assertStringContainsString('AutomationType::KaspiResolveWidgetUrls', $routes);
        $this->assertStringContainsString('AutomationType::KaspiImportContent', $routes);
    }

    public function test_paloma_command_uses_shared_business_service(): void
    {
        $command = file_get_contents(base_path('app/Console/Commands/PalomaSyncRemainsCommand.php'));

        $this->assertStringContainsString('PalomaSyncRemainsService', $command);
        $this->assertStringNotContainsString('PalomaCatalogAggregator', $command);
    }

    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}