<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 3.6 Etapas configurables del pipeline
        Schema::create('crm_pipeline_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 60);
            $table->smallInteger('default_probability')->default(20);
            $table->smallInteger('sort_order')->default(1);
            $table->boolean('is_closed')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });

        // 3.7 Tabla de Oportunidades
        Schema::create('crm_opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->uuid('client_id')->nullable(); // Se vinculará formalmente en Submódulo 4
            $table->string('title', 200);
            $table->string('stage', 30)->default('descubrimiento');
            $table->decimal('amount', 14, 2)->default(0.00);
            $table->char('currency', 3)->default('USD');
            $table->smallInteger('probability')->default(35);
            $table->date('expected_close_date');
            $table->date('closed_at')->nullable();
            $table->foreignUuid('owner_id')->constrained('iam_users')->cascadeOnDelete();
            $table->string('destination_label', 120)->nullable();
            $table->smallInteger('pax')->default(0);
            $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->uuid('product_id')->nullable();
            $table->string('origin', 40)->default('Manual');
            $table->text('notes')->nullable();
            $table->string('lost_reason_code', 40)->nullable();
            $table->text('lost_detail')->nullable();
            $table->string('lost_at_stage', 30)->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'stage']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'expected_close_date']);
        });

        // Habilitar RLS obligatorio (§3.11)
        DB::statement("ALTER TABLE crm_pipeline_stages ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_pipeline_stages FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_pipeline_stages USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");

        DB::statement("ALTER TABLE crm_opportunities ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_opportunities FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_opportunities USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunities');
        Schema::dropIfExists('crm_pipeline_stages');
    }
};