<?php

namespace Tests\Feature;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Models\User;
use App\Services\Automation\AutomationRunner;
use App\Services\Automation\AutomationRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationRunServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_button_creates_pending_automation_run(): void
    {
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret', 'is_admin' => true]);

        $result = app(AutomationRunService::class)->request(AutomationType::PalomaSyncRemains, AutomationRunSource::Admin, $admin, ['limit' => 5]);

        $this->assertTrue($result['created']);
        $this->assertSame(AutomationRunStatus::Pending->value, $result['run']->status);
        $this->assertSame($admin->id, $result['run']->requested_by);
        $this->assertSame(5, $result['run']->context['limit']);
    }

    public function test_duplicate_pending_runs_are_prevented(): void
    {
        $service = app(AutomationRunService::class);

        $first = $service->request(AutomationType::AutomationHealth, AutomationRunSource::Admin);
        $second = $service->request(AutomationType::AutomationHealth, AutomationRunSource::Admin);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['run']->id, $second['run']->id);
        $this->assertSame(1, AutomationRun::query()->count());
    }

    public function test_pending_processor_executes_correct_handler_and_marks_completed(): void
    {
        $run = app(AutomationRunService::class)->request(AutomationType::AutomationHealth, AutomationRunSource::System)['run'];

        $summary = app(AutomationRunner::class)->runPending(runId: $run->id, limit: 1, dryRun: true);

        $run->refresh();
        $this->assertSame(1, $summary['processed']);
        $this->assertContains($run->status, [AutomationRunStatus::Completed->value, AutomationRunStatus::CompletedWithWarnings->value]);
        $this->assertGreaterThan(0, $run->total_items);
        $this->assertSame(100, $run->progress);
    }

    public function test_failed_run_captures_safe_error_message(): void
    {
        $run = AutomationRun::query()->create([
            'type' => 'unsupported',
            'source' => AutomationRunSource::System->value,
            'status' => AutomationRunStatus::Pending->value,
            'requested_at' => now(),
            'heartbeat_at' => now(),
            'command_name' => 'unsupported',
            'handler' => 'unsupported',
            'lock_key' => 'automation:unsupported',
        ]);

        app(AutomationRunner::class)->runPending(runId: $run->id, limit: 1);

        $run->refresh();
        $this->assertSame(AutomationRunStatus::Failed->value, $run->status);
        $this->assertStringContainsString('Unsupported automation type', $run->error_message);
    }

    public function test_stale_running_jobs_expire(): void
    {
        AutomationRun::query()->create([
            'type' => AutomationType::AutomationHealth->value,
            'source' => AutomationRunSource::System->value,
            'status' => AutomationRunStatus::Running->value,
            'requested_at' => now()->subHours(3),
            'started_at' => now()->subHours(3),
            'heartbeat_at' => now()->subHours(3),
            'command_name' => AutomationType::AutomationHealth->commandName(),
            'handler' => AutomationType::AutomationHealth->handlerIdentifier(),
            'lock_key' => 'automation:automation_health',
        ]);

        $expired = app(AutomationRunService::class)->expireStaleRuns(120);

        $this->assertSame(1, $expired);
        $this->assertSame(AutomationRunStatus::Expired->value, AutomationRun::query()->first()->status);
    }
}