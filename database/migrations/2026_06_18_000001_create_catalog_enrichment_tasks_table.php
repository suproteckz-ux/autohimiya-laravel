<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_enrichment_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('task_type')->index();
            $table->string('status')->default('draft')->index();
            $table->string('source')->default('manual')->index();
            $table->unsignedTinyInteger('priority')->default(50)->index();
            $table->longText('current_value')->nullable();
            $table->longText('suggested_value')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0)->index();
            $table->text('reason')->nullable();
            $table->json('payload_json')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'task_type', 'source', 'status'], 'catalog_enrichment_task_unique_draft');
            $table->index(['task_type', 'status']);
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_enrichment_tasks');
    }
};
