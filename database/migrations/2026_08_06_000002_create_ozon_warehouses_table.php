<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ozon_account_id')->constrained()->cascadeOnDelete();
            $table->string('ozon_warehouse_id', 64);
            $table->string('name');
            $table->string('status')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['ozon_account_id', 'ozon_warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_warehouses');
    }
};
