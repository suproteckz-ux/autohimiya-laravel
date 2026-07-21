<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->nullable()->unique();
            $table->string('image')->nullable();
            $table->text('description')->nullable();
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_url')->nullable();
            $table->json('settings')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['target_type', 'target_id']);
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_cards');
    }
};
