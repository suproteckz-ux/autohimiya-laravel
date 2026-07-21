<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('opencart_category_id')->nullable()->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('image')->nullable();
            $table->string('icon')->nullable();
            $table->longText('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1')->nullable();
            $table->string('canonical_url')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->string('status')->default('active')->index();
            $table->boolean('show_on_homepage')->default(false);
            $table->integer('homepage_sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['show_on_homepage', 'homepage_sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
