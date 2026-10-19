<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 3.4 catalog_providers
        Schema::create('catalog_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20);
            $table->string('name', 160);
            $table->string('type', 20); // Hotel, Transporte, Tren, Guía, Conductor, Restaurante, Operador, Actividad, Entrada, Otros
            $table->char('ruc', 11);
            $table->string('city', 80)->nullable();
            $table->smallInteger('stars')->nullable();
            $table->string('contact_name', 120);
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->date('contract_start');
            $table->date('contract_end');
            $table->char('currency', 3)->default('USD');
            $table->string('payment_terms', 120)->nullable();
            $table->string('status', 20)->default('inactivo'); // activo, inactivo, vencido
            $table->decimal('rating', 2, 1)->default(0.0);
            $table->text('rating_note')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contract_end']);
        });

        Schema::create('catalog_provider_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('provider_id')->constrained('catalog_providers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('role', 80)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'provider_id']);
        });

        // 3.5 catalog_services
        Schema::create('catalog_services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('ref', 20);
            $table->foreignUuid('provider_id')->constrained('catalog_providers')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('type', 20); // Traslado, Transporte, Tren, Entrada, Guía, Alimentación, Alojamiento, Actividad, Tour, Seguro, Otro
            $table->string('unit', 20)->default('pax'); // pax, servicio, habitación, noche, vehículo, unidad
            $table->decimal('cost', 14, 2)->default(0.00);
            $table->char('currency', 3)->default('USD');
            $table->integer('capacity')->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->string('status', 20)->default('activo'); // activo, inactivo, vencido
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'ref']);
            $table->index(['tenant_id', 'provider_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'valid_to']);
        });

        // RLS estricto (§3)
        $tables = ['catalog_providers', 'catalog_provider_contacts', 'catalog_services'];
        foreach ($tables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_services');
        Schema::dropIfExists('catalog_provider_contacts');
        Schema::dropIfExists('catalog_providers');
    }
};