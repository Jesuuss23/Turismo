<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. quote_quotes (Cabecera sin constraint auto-referencial)
        Schema::create('quote_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->foreignUuid('client_id')->constrained('crm_clients')->restrictOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->foreignUuid('owner_id')->constrained('iam_users')->restrictOnDelete();
            $table->string('title', 200);
            $table->string('status', 30)->default('borrador');
            $table->smallInteger('current_version_no')->default(1);
            $table->uuid('current_version_id')->nullable();
            $table->date('travel_from');
            $table->date('travel_to');
            $table->date('valid_until');
            $table->char('currency', 3)->default('USD');
            $table->decimal('fx_rate', 12, 6)->default(3.740000);
            $table->smallInteger('pax_adt')->default(1);
            $table->smallInteger('pax_chd')->default(0);
            $table->smallInteger('pax_inf')->default(0);
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->string('approved_by_name', 150)->nullable();
            $table->string('approval_channel', 60)->nullable();
            $table->string('approval_ref', 200)->nullable();
            $table->foreignUuid('approval_recorded_by')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->ipAddress('approval_ip')->nullable();
            $table->string('approval_hash', 80)->nullable();
            $table->smallInteger('approval_version_no')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->uuid('reservation_id')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->uuid('duplicated_from_id')->nullable(); // Clave foránea auto-referencial postergada
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'valid_until']);
            $table->index(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'opportunity_id']);
        });

        // 2. quote_versions (Versiones inmutables)
        Schema::create('quote_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('quote_quotes')->cascadeOnDelete();
            $table->smallInteger('version_no');
            $table->string('status', 30)->default('borrador');
            $table->date('issued_on');
            $table->foreignUuid('author_id')->constrained('iam_users')->restrictOnDelete();
            $table->decimal('discount_pct', 5, 2)->default(0.00);
            $table->decimal('tax_pct', 5, 2)->default(18.00);
            $table->decimal('markup_pct', 7, 2)->default(25.00);
            $table->text('notes')->nullable();
            $table->text('conditions')->nullable();
            $table->string('snapshot_label', 300)->default('Cotización Estándar');
            $table->jsonb('catalog_snapshot')->default('{}');
            $table->jsonb('policies_snapshot')->default('{}');
            $table->decimal('fx_rate', 12, 6)->default(3.740000);
            $table->decimal('cost_total', 14, 2)->default(0.00);
            $table->decimal('sale_total', 14, 2)->default(0.00);
            $table->decimal('discount_total', 14, 2)->default(0.00);
            $table->decimal('net_total', 14, 2)->default(0.00);
            $table->decimal('tax_total', 14, 2)->default(0.00);
            $table->decimal('grand_total', 14, 2)->default(0.00);
            $table->decimal('profit_total', 14, 2)->default(0.00);
            $table->decimal('margin_pct', 7, 2)->default(0.00);
            $table->uuid('internal_approval_id')->nullable();
            $table->timestampTz('frozen_at')->nullable();
            $table->string('content_hash', 80)->nullable();
            $table->uuid('pdf_document_id')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'quote_id', 'version_no']);
            $table->index(['tenant_id', 'quote_id']);
        });

        // 3. quote_items
        Schema::create('quote_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_version_id')->constrained('quote_versions')->cascadeOnDelete();
            $table->smallInteger('line_no')->default(1);
            $table->foreignUuid('product_id')->constrained('catalog_products')->restrictOnDelete();
            $table->string('product_version', 10)->default('1.0');
            $table->foreignUuid('rate_id')->nullable()->constrained('catalog_rates')->nullOnDelete();
            $table->string('segment', 15)->default('Adulto');
            $table->date('service_date');
            $table->integer('qty')->default(1);
            $table->integer('pax')->default(1);
            $table->decimal('unit_cost', 14, 2)->default(0.00);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('cost_total', 14, 2)->default(0.00);
            $table->decimal('sale_total', 14, 2)->default(0.00);
            $table->foreignUuid('season_id')->nullable()->constrained('catalog_seasons')->nullOnDelete();
            $table->decimal('season_factor', 6, 3)->default(1.000);
            $table->string('price_source', 10)->default('rate');
            $table->jsonb('rate_snapshot')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'quote_version_id']);
        });

        // 4. quote_internal_approvals
        Schema::create('quote_internal_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_version_id')->constrained('quote_versions')->cascadeOnDelete();
            $table->jsonb('reasons');
            $table->boolean('over_cap')->default(false);
            $table->foreignUuid('requested_by')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->timestampTz('requested_at')->nullable();
            $table->text('request_note')->nullable();
            $table->string('status', 20)->default('pendiente');
            $table->foreignUuid('decided_by')->nullable()->constrained('iam_users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->boolean('is_override')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });

        // 5. quote_sends
        Schema::create('quote_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('quote_quotes')->cascadeOnDelete();
            $table->smallInteger('version_no');
            $table->string('channel', 15);
            $table->string('recipient', 200);
            $table->text('message')->nullable();
            $table->foreignUuid('sent_by')->constrained('iam_users')->restrictOnDelete();
            $table->timestampTz('sent_at');
            $table->uuid('notification_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'quote_id']);
        });

        // 6. quote_access_tokens
        Schema::create('quote_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('quote_quotes')->cascadeOnDelete();
            $table->string('token_hash', 80);
            $table->foreignUuid('created_by')->constrained('iam_users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->date('expires_at');
            $table->timestampTz('revoked_at')->nullable();

            $table->unique(['token_hash']);
            $table->index(['tenant_id', 'quote_id']);
        });

        // 7. Auto-referencia para quote_quotes.duplicated_from_id
        Schema::table('quote_quotes', function (Blueprint $table) {
            $table->foreign('duplicated_from_id')->references('id')->on('quote_quotes')->nullOnDelete();
        });

        // 8. Habilitar RLS estricto
        $tables = [
            'quote_quotes',
            'quote_versions',
            'quote_items',
            'quote_internal_approvals',
            'quote_sends',
            'quote_access_tokens',
        ];

        foreach ($tables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY;");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY;");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING (tenant_id = current_setting('app.tenant_id', true)::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id', true)::uuid);");
        }
    }

    public function down(): void
    {
        Schema::table('quote_quotes', function (Blueprint $table) {
            $table->dropForeign(['duplicated_from_id']);
        });

        Schema::dropIfExists('quote_access_tokens');
        Schema::dropIfExists('quote_sends');
        Schema::dropIfExists('quote_internal_approvals');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quote_versions');
        Schema::dropIfExists('quote_quotes');
    }
};