<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 3.6 catalog_products
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20); // PRD-001
            $table->string('code', 30);
            $table->string('sku', 40);
            $table->string('name', 160);
            $table->string('internal_name', 160);
            $table->foreignUuid('product_type_id')->constrained('catalog_product_types')->restrictOnDelete();
            $table->foreignUuid('category_id')->constrained('catalog_product_categories')->restrictOnDelete();
            $table->foreignUuid('subcategory_id')->nullable()->constrained('catalog_product_subcategories')->nullOnDelete();
            $table->foreignUuid('primary_destination_id')->constrained('catalog_destinations')->restrictOnDelete();
            $table->string('status', 20)->default('DRAFT'); // DRAFT, REVIEW, APPROVED, ACTIVE, SUSPENDED, INACTIVE, ARCHIVED
            $table->text('short_description')->nullable();
            $table->text('long_description')->nullable();
            $table->decimal('duration_value', 6, 1)->default(1.0);
            $table->string('duration_unit', 10)->default('días'); // horas, días, noches
            $table->string('modality', 15)->default('Grupal'); // Privado, Compartido, Grupal
            $table->foreignUuid('main_provider_id')->nullable()->constrained('catalog_providers')->nullOnDelete();
            $table->smallInteger('minimum_passengers')->default(1);
            $table->smallInteger('maximum_passengers')->default(20);
            $table->smallInteger('minimum_age')->default(0);
            $table->smallInteger('maximum_age')->default(99);
            $table->smallInteger('booking_advance_min_days')->default(1);
            $table->smallInteger('booking_advance_max_days')->default(365);
            $table->boolean('is_bookable')->default(true);
            $table->boolean('is_quoteable')->default(true);
            $table->boolean('is_b2b')->default(false);
            $table->boolean('is_ecommerce')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->decimal('markup_pct', 6, 2)->default(25.00);
            $table->string('availability_model', 20)->default('POR_SALIDA');
            $table->integer('availability_capacity')->nullable();
            $table->string('availability_source', 10)->default('interna');
            $table->jsonb('features')->default('[]');
            $table->string('slug', 160)->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'ref']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'primary_destination_id']);
        });

        // 3.7.1 catalog_product_destinations (Destinos secundarios)
        Schema::create('catalog_product_destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUuid('destination_id')->constrained('catalog_destinations')->cascadeOnDelete();
            $table->string('role', 10)->default('secondary');
            $table->timestampsTz();

            $table->unique(['product_id', 'destination_id']);
        });

        // 3.7.2 catalog_product_items (Componentes operativos PROC-015)
        Schema::create('catalog_product_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('catalog_services')->nullOnDelete();
            $table->foreignUuid('component_product_id')->nullable()->constrained('catalog_products')->nullOnDelete();
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers')->nullOnDelete();
            $table->decimal('quantity', 8, 2)->default(1.00);
            $table->string('unit', 20)->default('pax');
            $table->smallInteger('sort_order')->default(1);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_included')->default(true);
            $table->decimal('unit_cost_override', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['product_id', 'sort_order']);
        });

        // 3.7.3 Itinerarios detallados (PROC-016)
        Schema::create('catalog_itineraries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('name', 120)->default('Principal');
            $table->boolean('is_default')->default(true);
            $table->timestampsTz();

            $table->unique(['product_id', 'is_default']);
        });

        Schema::create('catalog_itinerary_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('itinerary_id')->constrained('catalog_itineraries')->cascadeOnDelete();
            $table->smallInteger('day_number');
            $table->string('title', 160);
            $table->timestampsTz();

            $table->unique(['itinerary_id', 'day_number']);
        });

        Schema::create('catalog_itinerary_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('day_id')->constrained('catalog_itinerary_days')->cascadeOnDelete();
            $table->time('start_time')->nullable();
            $table->string('activity', 200);
            $table->string('item_type', 20)->default('Actividad');
            $table->string('location', 160)->nullable();
            $table->foreignUuid('service_id')->nullable()->constrained('catalog_services')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestampsTz();
        });

        // 3.7.4 Inclusiones y Exclusiones
        Schema::create('catalog_product_inclusions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('kind', 10); // include, exclude
            $table->string('text', 200);
            $table->smallInteger('sort_order')->default(1);
            $table->timestampsTz();
        });

        // 3.7.5 Políticas Comerciales
        Schema::create('catalog_product_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->text('cancellation');
            $table->text('modification');
            $table->text('no_show');
            $table->text('refund');
            $table->text('penalties')->nullable();
            $table->timestampsTz();

            $table->unique(['product_id']);
        });

        // 3.7.6 Versiones con Snapshot Inmutable (PRD-BR-017)
        Schema::create('catalog_product_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('version', 10); // 0.1, 1.0...
            $table->string('status', 15)->default('borrador'); // borrador, en_revision, vigente, historica
            $table->date('valid_from');
            $table->text('reason');
            $table->jsonb('snapshot');
            $table->foreignUuid('created_by')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['product_id', 'version']);
        });

        // RLS estricto en todas las tablas creadas
        $tables = [
            'catalog_products',
            'catalog_product_destinations',
            'catalog_product_items',
            'catalog_itineraries',
            'catalog_itinerary_days',
            'catalog_itinerary_items',
            'catalog_product_inclusions',
            'catalog_product_policies',
            'catalog_product_versions',
        ];

        foreach ($tables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_product_versions');
        Schema::dropIfExists('catalog_product_policies');
        Schema::dropIfExists('catalog_product_inclusions');
        Schema::dropIfExists('catalog_itinerary_items');
        Schema::dropIfExists('catalog_itinerary_days');
        Schema::dropIfExists('catalog_itineraries');
        Schema::dropIfExists('catalog_product_items');
        Schema::dropIfExists('catalog_product_destinations');
        Schema::dropIfExists('catalog_products');
    }
};