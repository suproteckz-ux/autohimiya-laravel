<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_images', 'original_name')) {
                $table->string('original_name')->nullable()->after('original_path');
            }

            if (! Schema::hasColumn('product_images', 'source')) {
                $table->string('source')->default('opencart')->after('is_primary')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            if (Schema::hasColumn('product_images', 'source')) {
                $table->dropColumn('source');
            }

            if (Schema::hasColumn('product_images', 'original_name')) {
                $table->dropColumn('original_name');
            }
        });
    }
};
