<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            foreach ([
                'name_is_manual',
                'category_is_manual',
                'description_is_manual',
                'photos_are_manual',
                'attributes_are_manual',
                'seo_is_manual',
                'auto_content_locked',
            ] as $column) {
                if (! Schema::hasColumn('products', $column)) {
                    $table->boolean($column)->default(false)->index()->after('kaspi_source');
                }
            }

            if (! Schema::hasColumn('products', 'content_verified_at')) {
                $table->timestamp('content_verified_at')->nullable()->index()->after('auto_content_locked');
            }

            if (! Schema::hasColumn('products', 'content_verified_by')) {
                $table->foreignId('content_verified_by')->nullable()->after('content_verified_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'content_verified_by')) {
                $table->dropConstrainedForeignId('content_verified_by');
            }

            foreach ([
                'content_verified_at',
                'auto_content_locked',
                'seo_is_manual',
                'attributes_are_manual',
                'photos_are_manual',
                'description_is_manual',
                'category_is_manual',
                'name_is_manual',
            ] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
