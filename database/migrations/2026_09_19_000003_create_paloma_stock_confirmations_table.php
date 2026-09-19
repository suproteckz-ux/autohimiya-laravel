<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paloma_stock_confirmations', function (Blueprint $table) {
            $table->id();
            $table->timestamp('confirmed_at');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paloma_sync_log_id')->nullable()->constrained('sync_logs')->nullOnDelete();
            $table->timestamp('paloma_import_completed_at')->nullable();
            $table->unsignedInteger('pending_orders_count')->default(0);
            $table->unsignedInteger('pending_items_qty')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paloma_stock_confirmations');
    }
};
