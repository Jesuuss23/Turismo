<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 3.9 crm_surveys
        Schema::create('crm_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->foreignUuid('client_id')->constrained('crm_clients')->cascadeOnDelete();
            $table->uuid('reservation_id')->nullable();
            $table->smallInteger('score'); // 0 - 10
            $table->string('category', 10); // promotor, pasivo, detractor
            $table->text('comment')->nullable();
            $table->string('channel_code', 40)->default('llamada');
            $table->timestampTz('responded_at')->useCurrent();
            $table->foreignUuid('recorded_by')->constrained('iam_users');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'client_id']);
        });

        // 3.10 crm_cases
        Schema::create('crm_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->foreignUuid('client_id')->constrained('crm_clients')->cascadeOnDelete();
            $table->uuid('reservation_id')->nullable();
            $table->foreignUuid('survey_id')->nullable()->constrained('crm_surveys')->nullOnDelete();
            $table->string('origin', 20)->default('manual'); // manual, nps_auto
            $table->string('type_code', 40)->default('reclamo'); // reclamo, queja, sugerencia, felicitacion
            $table->string('priority', 10)->default('media'); // baja, media, alta, critica
            $table->string('subject', 200);
            $table->text('description');
            $table->string('status', 20)->default('abierta'); // abierta, en_proceso, resuelta, cerrada
            $table->foreignUuid('owner_id')->constrained('iam_users')->cascadeOnDelete();
            $table->text('resolution')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'client_id']);
        });

        // 3.11 crm_case_events (bitácora del caso)
        Schema::create('crm_case_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('case_id')->constrained('crm_cases')->cascadeOnDelete();
            $table->string('event', 20); // registro, estado, seguimiento
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('message');
            $table->foreignUuid('user_id')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'case_id']);
        });

        // RLS obligatorio
        DB::statement("ALTER TABLE crm_surveys ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_surveys FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_surveys USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");

        DB::statement("ALTER TABLE crm_cases ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_cases FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_cases USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");

        DB::statement("ALTER TABLE crm_case_events ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_case_events FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_case_events USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_case_events');
        Schema::dropIfExists('crm_cases');
        Schema::dropIfExists('crm_surveys');
    }
};