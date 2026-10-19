<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 3.4 crm_clients
        Schema::create('crm_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('kind', 10)->default('persona'); // persona, empresa
            $table->string('name', 200);
            $table->string('doc_type', 10); // DNI, RUC, PASAPORTE, CE
            $table->string('doc_number', 20);
            $table->string('email', 190)->nullable();
            $table->string('email_norm', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_norm', 9)->nullable();
            $table->string('country', 80)->default('Perú');
            $table->string('city', 80)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('segment_code', 40)->default('retail');
            $table->foreignUuid('owner_id')->constrained('iam_users')->cascadeOnDelete();
            $table->jsonb('tags')->default('[]');
            $table->smallInteger('nps_last')->nullable();
            $table->foreignUuid('source_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->uuid('b2b_agency_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'doc_type', 'doc_number']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'segment_code']);
            $table->index(['tenant_id', 'email_norm']);
            $table->index(['tenant_id', 'phone_norm']);
        });

        // 3.5 crm_client_contacts
        Schema::create('crm_client_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('crm_clients')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('role', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignUuid('source_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id']);
        });

        // RLS obligatorio (§3.11)
        DB::statement("ALTER TABLE crm_clients ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_clients FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_clients USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");

        DB::statement("ALTER TABLE crm_client_contacts ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_client_contacts FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_client_contacts USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_client_contacts');
        Schema::dropIfExists('crm_clients');
    }
};