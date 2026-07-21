<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table): void {
            $table->unsignedInteger('offers_count')->default(0)->after('finished_at');
            $table->unsignedInteger('duplicate_count')->default(0)->after('skipped_count');
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'offers_count',
                'duplicate_count',
            ]);
        });
    }
};
