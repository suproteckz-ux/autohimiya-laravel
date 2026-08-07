<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('ozon_warehouses', function (Blueprint $table): void { $table->boolean('is_api_confirmed')->default(false)->index(); $table->timestamp('api_confirmed_at')->nullable(); }); }
    public function down(): void { Schema::table('ozon_warehouses', function (Blueprint $table): void { $table->dropColumn(['is_api_confirmed','api_confirmed_at']); }); }
};
