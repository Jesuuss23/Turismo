<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. catalog_product_types
        Schema::create('catalog_product_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('requires_itinerary')->default(false);
            $table->boolean('requires_components')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'name']);
        });

        // 2. catalog_product_categories & subcategories
        Schema::create('catalog_product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20);
            $table->string('name', 80);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'ref']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('catalog_product_subcategories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('catalog_product_categories')->restrictOnDelete();
            $table->string('name', 80);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['category_id', 'name']);
        });

        // 3. catalog_destinations (sin constraint parent_id en create)
        Schema::create('catalog_destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20);
            $table->uuid('parent_id')->nullable();
            $table->string('name', 120);
            $table->string('type', 20);
            $table->smallInteger('level');
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->text('description')->nullable();
            $table->text('path')->default('');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'type']);
        });

        // 4. Clave foránea auto-referencial para catalog_destinations
        Schema::table('catalog_destinations', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('catalog_destinations')->nullOnDelete();
        });

        // 5. Políticas RLS
        $tables = ['catalog_product_types', 'catalog_product_categories', 'catalog_product_subcategories', 'catalog_destinations'];
        foreach ($tables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
        }
    }

    public function down(): void
    {
        Schema::table('catalog_destinations', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });

        Schema::dropIfExists('catalog_destinations');
        Schema::dropIfExists('catalog_product_subcategories');
        Schema::dropIfExists('catalog_product_categories');
        Schema::dropIfExists('catalog_product_types');
    }
};