<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('url')->unique();
            $table->date('date')->nullable()->index();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 4)->default(0);
            $table->decimal('position', 8, 2)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['date', 'impressions']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_pages');
    }
};
