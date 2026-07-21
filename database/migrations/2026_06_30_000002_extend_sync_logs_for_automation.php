<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('sync_logs', 'command')) {
                $table->string('command')->nullable()->after('mode')->index();
            }

            if (! Schema::hasColumn('sync_logs', 'processed_count')) {
                $table->unsignedInteger('processed_count')->default(0)->after('offers_count');
            }

            if (! Schema::hasColumn('sync_logs', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->default(0)->after('finished_at');
            }

            if (! Schema::hasColumn('sync_logs', 'diagnostics')) {
                $table->json('diagnostics')->nullable()->after('payload_summary');
            }

            if (! Schema::hasColumn('sync_logs', 'raw_payload')) {
                $table->json('raw_payload')->nullable()->after('diagnostics');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table): void {
            foreach (['raw_payload', 'diagnostics', 'duration_ms', 'processed_count', 'command'] as $column) {
                if (Schema::hasColumn('sync_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
