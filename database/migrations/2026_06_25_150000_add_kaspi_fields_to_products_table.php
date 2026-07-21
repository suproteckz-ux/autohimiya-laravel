<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'kaspi_merchant_sku')) {
                $table->string('kaspi_merchant_sku')->nullable()->index()->after('paloma_sku');
            }
            if (! Schema::hasColumn('products', 'kaspi_product_url')) {
                $table->string('kaspi_product_url')->nullable()->after('kaspi_merchant_sku');
            }
            if (! Schema::hasColumn('products', 'kaspi_credit_enabled')) {
                $table->boolean('kaspi_credit_enabled')->default(false)->index()->after('kaspi_product_url');
            }
            if (! Schema::hasColumn('products', 'kaspi_status')) {
                $table->string('kaspi_status')->nullable()->index()->after('kaspi_credit_enabled');
            }
            if (! Schema::hasColumn('products', 'kaspi_price')) {
                $table->integer('kaspi_price')->nullable()->after('kaspi_status');
            }
            if (! Schema::hasColumn('products', 'kaspi_quantity')) {
                $table->integer('kaspi_quantity')->nullable()->after('kaspi_price');
            }
            if (! Schema::hasColumn('products', 'kaspi_available')) {
                $table->boolean('kaspi_available')->nullable()->index()->after('kaspi_quantity');
            }
            if (! Schema::hasColumn('products', 'kaspi_last_sync_at')) {
                $table->timestamp('kaspi_last_sync_at')->nullable()->after('kaspi_available');
            }
            if (! Schema::hasColumn('products', 'kaspi_last_error')) {
                $table->text('kaspi_last_error')->nullable()->after('kaspi_last_sync_at');
            }
            if (! Schema::hasColumn('products', 'kaspi_sync_status')) {
                $table->string('kaspi_sync_status')->nullable()->index()->after('kaspi_last_error');
            }
            if (! Schema::hasColumn('products', 'kaspi_source')) {
                $table->string('kaspi_source')->nullable()->after('kaspi_sync_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            foreach ([
                'kaspi_source',
                'kaspi_sync_status',
                'kaspi_last_error',
                'kaspi_last_sync_at',
                'kaspi_available',
                'kaspi_quantity',
                'kaspi_price',
                'kaspi_status',
                'kaspi_credit_enabled',
                'kaspi_product_url',
                'kaspi_merchant_sku',
            ] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
