<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('ozon_taxonomy_nodes', function (Blueprint $table): void { $table->id(); $table->foreignId('ozon_account_id')->constrained()->cascadeOnDelete(); $table->foreignId('parent_id')->nullable()->constrained('ozon_taxonomy_nodes')->cascadeOnDelete(); $table->string('description_category_id'); $table->string('category_name'); $table->string('type_id'); $table->string('type_name'); $table->boolean('is_disabled')->default(false); $table->json('raw_payload')->nullable(); $table->timestamp('synced_at'); $table->timestamps(); $table->unique(['ozon_account_id','description_category_id','type_id'], 'ozon_taxonomy_account_category_type_unique'); });
        Schema::create('ozon_taxonomy_attributes', function (Blueprint $table): void { $table->id(); $table->foreignId('ozon_taxonomy_node_id')->constrained()->cascadeOnDelete(); $table->string('attribute_id'); $table->string('name'); $table->string('type')->nullable(); $table->string('dictionary_id')->nullable(); $table->boolean('is_required')->default(false); $table->boolean('is_collection')->default(false); $table->json('values_payload')->nullable(); $table->json('raw_payload')->nullable(); $table->timestamp('synced_at'); $table->timestamps(); $table->unique(['ozon_taxonomy_node_id','attribute_id']); });
    }
    public function down(): void { Schema::dropIfExists('ozon_taxonomy_attributes'); Schema::dropIfExists('ozon_taxonomy_nodes'); }
};
