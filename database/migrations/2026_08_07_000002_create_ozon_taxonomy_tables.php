<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ozon_taxonomy_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ozon_account_id');
            $table->foreignId('parent_id')->nullable();
            $table->string('description_category_id');
            $table->string('category_name');
            $table->string('type_id');
            $table->string('type_name');
            $table->boolean('is_disabled')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->foreign('ozon_account_id', 'oz_tax_nodes_account_fk')
                ->references('id')->on('ozon_accounts')->cascadeOnDelete();
            $table->foreign('parent_id', 'oz_tax_nodes_parent_fk')
                ->references('id')->on('ozon_taxonomy_nodes')->cascadeOnDelete();
            $table->unique(
                ['ozon_account_id', 'description_category_id', 'type_id'],
                'oz_tax_nodes_account_cat_type_uq',
            );
        });

        Schema::create('ozon_taxonomy_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ozon_taxonomy_node_id');
            $table->string('attribute_id');
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('dictionary_id')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_collection')->default(false);
            $table->json('values_payload')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->foreign('ozon_taxonomy_node_id', 'oz_tax_attr_node_fk')
                ->references('id')->on('ozon_taxonomy_nodes')->cascadeOnDelete();
            $table->unique(
                ['ozon_taxonomy_node_id', 'attribute_id'],
                'oz_tax_attr_node_attr_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ozon_taxonomy_attributes');
        Schema::dropIfExists('ozon_taxonomy_nodes');
    }
};
