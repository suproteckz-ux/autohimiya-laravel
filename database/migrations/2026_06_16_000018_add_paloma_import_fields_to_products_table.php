<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->integer('quantity')->default(0)->after('price');
            $table->boolean('availability')->default(false)->after('quantity')->index();
            $table->string('paloma_payload_hash', 64)->nullable()->after('paloma_sku')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['availability']);
            $table->dropIndex(['paloma_payload_hash']);
            $table->dropColumn([
                'quantity',
                'availability',
                'paloma_payload_hash',
            ]);
        });
    }
};
