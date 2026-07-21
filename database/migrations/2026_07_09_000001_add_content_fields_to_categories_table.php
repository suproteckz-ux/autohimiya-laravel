<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->text('short_description')->nullable()->after('description');
            $table->longText('seo_description')->nullable()->after('short_description');
            $table->string('image_path')->nullable()->after('icon');
            $table->string('icon_path')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['short_description', 'seo_description', 'image_path', 'icon_path']);
        });
    }
};
