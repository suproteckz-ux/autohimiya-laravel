<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ozon_operations', function (Blueprint $table): void {
            $table->string('http_method', 8)->nullable()->after('endpoint');
            $table->string('error_code', 64)->nullable()->index()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('ozon_operations', function (Blueprint $table): void {
            $table->dropIndex(['error_code']);
            $table->dropColumn(['http_method', 'error_code']);
        });
    }
};
