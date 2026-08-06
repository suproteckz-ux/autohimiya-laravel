<?php

use App\Enums\OzonOperationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ozon_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('ozon_product_id')->nullable()->constrained('ozon_products')->cascadeOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained('automation_runs')->nullOnDelete();
            $table->string('operation_key')->unique();
            $table->string('operation_type')->index();
            $table->string('status')->default(OzonOperationStatus::Pending->value)->index();
            $table->string('endpoint')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['operation_type', 'status', 'created_at']);
            $table->index(['ozon_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_operations');
    }
};
