<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('days')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('pages_count')->default(0);
            $table->unsignedInteger('queries_count')->default(0);
            $table->text('message')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_sync_logs');
    }
};
