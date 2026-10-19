<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. catalog_seasons (PROC-019)
        Schema::create('catalog_seasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20); // TMP-01
            $table->string('name', 120);
            $table->string('type', 20); // Alta, Media, Baja, Feriado, Fecha especial, Personalizada
            $table->date('date_from');
            $table->date('date_to');
            $table->smallInteger('priority')->default(100); // 0 = máxima
            $table->decimal('factor', 4, 2)->default(1.00);
            $table->string('status', 10)->default('activa'); // activa, inactiva
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'date_from', 'date_to']);
        });

        // 2. catalog_rates (PROC-017)
        Schema::create('catalog_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20); // TAR-001
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('rate_type', 15)->default('Venta');
            $table->string('segment', 10)->default('Adulto');
            $table->string('channel', 12)->default('Directo');
            $table->char('currency', 3)->default('USD');
            $table->string('price_basis', 15)->default('Por persona');
            $table->decimal('cost', 14, 2)->default(0.00);
            $table->decimal('price', 14, 2);
            $table->decimal('min_price', 14, 2)->nullable();
            $table->decimal('max_price', 14, 2)->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->foreignUuid('season_id')->nullable()->constrained('catalog_seasons')->nullOnDelete();
            $table->string('conditions', 300)->nullable();
            $table->string('status', 10)->default('vigente'); // vigente, inactiva
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'product_id']);
            $table->index(['tenant_id', 'valid_to']);
            $table->index(['product_id', 'segment', 'channel', 'status']);
        });

        // 3. catalog_price_rules (PROC-018)
        Schema::create('catalog_price_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20); // PR-01
            $table->string('name', 120);
            $table->string('kind', 20); // Markup %, Markup fijo, Descuento %, Descuento fijo, Impuesto %, Redondeo, Precio mínimo
            $table->decimal('value', 12, 4);
            $table->string('scope', 200)->default('Global');
            $table->string('channel', 12)->nullable();
            $table->smallInteger('min_pax')->nullable();
            $table->string('customer_residency', 10)->nullable(); // dom, nodom
            $table->smallInteger('priority')->default(100);
            $table->string('status', 10)->default('activa');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'status']);
        });

        // Habilitar RLS estricto
        $tables = ['catalog_seasons', 'catalog_rates', 'catalog_price_rules'];
        foreach ($tables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_price_rules');
        Schema::dropIfExists('catalog_rates');
        Schema::dropIfExists('catalog_seasons');
    }
};