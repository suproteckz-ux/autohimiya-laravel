<?php

use App\Enums\AutomationRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('source')->default('system')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default(AutomationRunStatus::Pending->value)->index();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamp('heartbeat_at')->nullable()->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('message')->nullable();
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->string('command_name')->nullable()->index();
            $table->string('handler')->nullable();
            $table->string('lock_key')->index();
            $table->timestamps();

            $table->index(['type', 'status', 'requested_at']);
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};