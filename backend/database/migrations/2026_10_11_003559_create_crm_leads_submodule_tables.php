<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 3.1 Catálogos configurables del CRM
        Schema::create('crm_catalog_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 30); // lead_source, lead_channel, lead_interest, discard_reason, etc.
            $table->string('code', 40);
            $table->string('label', 120);
            $table->boolean('requires_detail')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'kind', 'code']);
        });

        // 3.2 Tabla principal de Leads
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('full_name', 160);
            $table->string('company', 160)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('email_norm', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_norm', 9)->nullable();
            $table->string('channel_code', 40);
            $table->string('source_code', 40);
            $table->string('interest_code', 40);
            $table->uuid('destination_id')->nullable();
            $table->string('destination_label', 120)->nullable();
            $table->uuid('product_id')->nullable();
            $table->date('travel_date')->nullable();
            $table->smallInteger('pax')->default(0);
            $table->string('status', 30)->default('nuevo'); // nuevo, contactado, calificado, convertido, duplicado, descartado
            $table->foreignUuid('owner_id')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->smallInteger('score')->default(0);
            $table->text('notes')->nullable();
            $table->string('duplicate_of_type', 10)->nullable(); // lead, client
            $table->uuid('duplicate_of_id')->nullable();
            $table->timestampTz('validated_at')->useCurrent();
            $table->timestampTz('assigned_at')->nullable();
            $table->foreignUuid('taken_by')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->timestampTz('first_contact_at')->nullable();
            $table->string('discard_reason_code', 40)->nullable();
            $table->text('discard_detail')->nullable();
            $table->timestampTz('discarded_at')->nullable();
            $table->smallInteger('discarded_at_step')->nullable();
            $table->uuid('converted_client_id')->nullable();
            $table->uuid('converted_opportunity_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->foreignUuid('converted_by')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->integer('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'email_norm']);
            $table->index(['tenant_id', 'phone_norm']);
        });

        // 3.3 Historial de Calificación BANT
        Schema::create('crm_lead_qualifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->smallInteger('budget');     // 0, 10, 18, 25
            $table->smallInteger('authority');  // 0, 10, 18, 25
            $table->smallInteger('need');       // 0, 10, 18, 25
            $table->smallInteger('timeline');   // 0, 10, 18, 25
            $table->smallInteger('score');
            $table->decimal('declared_amount', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('qualified_by')->constrained('iam_users');
            $table->timestampTz('qualified_at')->useCurrent();
            $table->timestampsTz();

            $table->index(['tenant_id', 'lead_id']);
        });

        // Habilitar RLS obligatorio (§3.11)
        DB::statement("ALTER TABLE crm_catalog_items ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_catalog_items FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_catalog_items USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");

        DB::statement("ALTER TABLE crm_leads ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_leads FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_leads USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");

        DB::statement("ALTER TABLE crm_lead_qualifications ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_lead_qualifications FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_lead_qualifications USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_qualifications');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_catalog_items');
    }
};