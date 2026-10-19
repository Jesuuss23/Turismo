<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. catalog_product_departures (PROC-020)
        Schema::create('catalog_product_departures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20); // SAL-0101
            $table->foreignUuid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->date('departure_date');
            $table->time('departure_time');
            $table->smallInteger('capacity')->default(15);
            $table->smallInteger('sold')->default(0);
            $table->string('status', 12)->default('abierta'); // abierta, completa, cerrada, cancelada
            $table->foreignUuid('provider_id')->nullable()->constrained('catalog_providers')->nullOnDelete();
            $table->string('meeting_point', 160)->default('Plaza Regocijo / Hotel');
            $table->string('return_point', 160)->nullable();
            $table->text('notes')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'departure_date']);
            $table->index(['product_id', 'departure_date', 'departure_time']);
            $table->index(['tenant_id', 'status']);
        });

        // 2. catalog_availability_holds
        Schema::create('catalog_availability_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20); // HLD-0001
            $table->foreignUuid('departure_id')->constrained('catalog_product_departures')->cascadeOnDelete();
            $table->smallInteger('quantity');
            $table->string('reference', 60); // COT-..., RES-..., WEB-...
            $table->string('reference_type', 20)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('status', 12)->default('hold'); // hold, confirmada, liberada, expirada, consumida
            $table->timestampTz('expires_at');
            $table->text('released_reason')->nullable();
            $table->text('notes')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'ref']);
            $table->index(['departure_id', 'status']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        // Habilitar RLS estricto (§3)
        $tables = ['catalog_product_departures', 'catalog_availability_holds'];
        foreach ($tables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_availability_holds');
        Schema::dropIfExists('catalog_product_departures');
    }
};