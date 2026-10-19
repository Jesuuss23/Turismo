<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 3.8 crm_activities
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('type', 20); // llamada, email, whatsapp, reunion, tarea, postventa
            $table->string('subject', 200);
            $table->text('description')->nullable();
            $table->string('related_type', 20)->nullable(); // lead, opportunity, client, quote, reservation
            $table->uuid('related_id')->nullable();
            $table->foreignUuid('owner_id')->constrained('iam_users')->cascadeOnDelete();
            $table->timestampTz('due_at');
            $table->string('status', 20)->default('pendiente'); // pendiente, vencida, completada
            $table->text('result')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->boolean('auto_closed')->default(false);
            $table->uuid('previous_activity_id')->nullable();
            $table->uuid('merged_from_lead_id')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'owner_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'related_type', 'related_id']);
        });

        // RLS obligatorio (§3.11)
        DB::statement("ALTER TABLE crm_activities ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE crm_activities FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON crm_activities USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};