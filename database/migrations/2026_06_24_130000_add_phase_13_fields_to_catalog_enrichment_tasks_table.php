<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_enrichment_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('catalog_enrichment_tasks', 'current_payload')) {
                $table->json('current_payload')->nullable()->after('current_value');
            }

            if (! Schema::hasColumn('catalog_enrichment_tasks', 'suggested_payload')) {
                $table->json('suggested_payload')->nullable()->after('suggested_value');
            }

            if (! Schema::hasColumn('catalog_enrichment_tasks', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('reviewed_at');
            }

            $table->index(['product_id', 'task_type', 'status'], 'catalog_enrichment_active_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_enrichment_tasks', function (Blueprint $table): void {
            $table->dropIndex('catalog_enrichment_active_lookup_idx');

            if (Schema::hasColumn('catalog_enrichment_tasks', 'published_at')) {
                $table->dropColumn('published_at');
            }

            if (Schema::hasColumn('catalog_enrichment_tasks', 'suggested_payload')) {
                $table->dropColumn('suggested_payload');
            }

            if (Schema::hasColumn('catalog_enrichment_tasks', 'current_payload')) {
                $table->dropColumn('current_payload');
            }
        });
    }
};
