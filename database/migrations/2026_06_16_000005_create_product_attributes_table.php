<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('opencart_attribute_id')->nullable();
            $table->string('group_name')->nullable();
            $table->string('name')->index();
            $table->text('value')->nullable();
            $table->string('unit')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_filterable')->default(false)->index();
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
