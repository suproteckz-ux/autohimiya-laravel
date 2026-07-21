<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gsc_page_id')->nullable()->constrained('gsc_pages')->cascadeOnDelete();
            $table->string('query')->index();
            $table->date('date')->nullable()->index();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 4)->default(0);
            $table->decimal('position', 8, 2)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['query', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_queries');
    }
};
