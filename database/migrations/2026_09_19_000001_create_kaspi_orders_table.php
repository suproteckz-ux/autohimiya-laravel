<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kaspi_orders', function (Blueprint $table) {
            $table->id();
            $table->string('kaspi_order_id')->unique();
            $table->string('kaspi_code')->nullable();
            $table->string('kaspi_status')->nullable();
            $table->string('kaspi_state')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('internal_stock_status')->nullable();
            $table->timestamp('kaspi_created_at')->nullable();
            $table->timestamp('kaspi_updated_at')->nullable();
            $table->timestamp('courier_transmission_planning_date')->nullable();
            $table->timestamp('courier_transmission_date')->nullable();
            $table->timestamp('handoff_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('waybill')->nullable();
            $table->string('waybill_number')->nullable();
            $table->unsignedSmallInteger('number_of_space')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('baseline_ignored')->default(false);
            $table->timestamps();

            $table->index('kaspi_code');
            $table->index('kaspi_status');
            $table->index('kaspi_state');
            $table->index('internal_stock_status');
            $table->index('courier_transmission_date');
            $table->index('baseline_ignored');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kaspi_orders');
    }
};
