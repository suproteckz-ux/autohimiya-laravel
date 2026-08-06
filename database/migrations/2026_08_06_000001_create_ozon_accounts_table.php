<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('client_id');
            $table->text('api_key');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_test_mode')->default(false);
            $table->string('fulfillment_scheme')->nullable();
            $table->decimal('default_price_multiplier', 10, 4)->default(1);
            $table->string('rounding_rule')->nullable();
            $table->unsignedInteger('default_stock_limit')->nullable();
            $table->unsignedInteger('batch_size')->default(20);
            $table->boolean('sync_prices_enabled')->default(true);
            $table->boolean('sync_stocks_enabled')->default(true);
            $table->timestamp('last_connection_check_at')->nullable();
            $table->text('last_connection_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_accounts');
    }
};
