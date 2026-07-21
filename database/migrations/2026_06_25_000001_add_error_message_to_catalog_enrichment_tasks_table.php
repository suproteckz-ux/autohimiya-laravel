<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_enrichment_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('catalog_enrichment_tasks', 'error_message')) {
                $table->text('error_message')->nullable()->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_enrichment_tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('catalog_enrichment_tasks', 'error_message')) {
                $table->dropColumn('error_message');
            }
        });
    }
};
