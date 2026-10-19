<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 3.1 platform_users (Super Admin SaaS, sin tenant_id)
        Schema::create('platform_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->string('password', 255);
            $table->text('mfa_secret')->nullable(); // Cifrado
            $table->string('status', 20)->default('activo'); // activo, inactivo
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        // Tenants (Tabla raíz de multi-tenancy)
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('slug', 80)->unique();
            $table->string('ruc', 20)->nullable();
            $table->string('plan', 40)->default('starter');
            $table->string('status', 20)->default('activo'); // activo, suspendido, inactivo
            $table->string('timezone', 50)->default('America/Lima');
            $table->char('currency', 3)->default('USD');
            $table->decimal('fx_rate', 12, 6)->default(3.750000);
            $table->decimal('tax_pct', 5, 2)->default(18.00);
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // 3.2 iam_users (Usuarios del tenant)
        Schema::create('iam_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20)->nullable();
            $table->string('name', 150);
            $table->string('email', 190);
            $table->string('password', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->uuid('sede_id')->nullable();
            $table->string('status', 20)->default('activo'); // pendiente, activo, inactivo
            $table->boolean('mfa_enabled')->default(false);
            $table->boolean('mfa_required')->default(false);
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->char('avatar_color', 7)->nullable();
            $table->uuid('client_id')->nullable();
            $table->uuid('agency_id')->nullable();
            $table->uuid('provider_id')->nullable();
            $table->uuid('guide_id')->nullable();
            $table->uuid('driver_id')->nullable();
            $table->uuid('reservation_id')->nullable();
            $table->timestampTz('invited_at')->nullable();
            $table->uuid('invited_by')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->text('deactivated_reason')->nullable();
            $table->string('locale', 10)->default('es');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'status']);
        });

        // 3.6 audit_events (Inmutable, append-only)
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigIncrements('code');
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('actor_type', 20)->default('user'); // user, platform_user, system, api_client
            $table->uuid('actor_id')->nullable();
            $table->string('actor_role', 60)->nullable();
            $table->string('entity', 40);
            $table->string('entity_id', 80);
            $table->string('action', 40);
            $table->boolean('is_critical')->default(false);
            $table->text('before_value')->nullable();
            $table->text('after_value')->nullable();
            $table->jsonb('diff')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 250)->nullable();
            $table->string('correlation_id', 40);
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'entity', 'entity_id']);
            $table->index(['tenant_id', 'actor_id']);
            $table->index(['correlation_id']);
        });

        // 3.7 sys_sequences (Correlativos atómicos)
        Schema::create('sys_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('doc_type', 40);
            $table->string('label', 60);
            $table->string('series', 10)->default('default');
            $table->string('prefix', 30);
            $table->smallInteger('pad_width')->default(5);
            $table->bigInteger('next_number')->default(1);
            $table->boolean('year_reset')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'doc_type', 'series']);
        });

        // 3.8 sys_idempotency_keys
        Schema::create('sys_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 80);
            $table->uuid('user_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 250);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('processing'); // processing, completed, failed
            $table->smallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('locked_until')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at');

            $table->unique(['tenant_id', 'key']);
            $table->index(['expires_at']);
        });

        // 3.11 Habilitar RLS obligatorio
        DB::statement("ALTER TABLE iam_users ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE iam_users FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON iam_users USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");

        DB::statement("ALTER TABLE sys_sequences ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE sys_sequences FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON sys_sequences USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");

        DB::statement("ALTER TABLE audit_events ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE audit_events FORCE ROW LEVEL SECURITY;");
        DB::statement("CREATE POLICY tenant_isolation ON audit_events USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_idempotency_keys');
        Schema::dropIfExists('sys_sequences');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('iam_users');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('platform_users');
    }
};