<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fin_commission_rules')) {
            Schema::create('fin_commission_rules', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('beneficiary_kind', 20);
                $table->string('condition', 30);
                $table->decimal('pct', 5, 2)->nullable();
                $table->string('base_type', 20)->default('neto_sin_igv');
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->boolean('active')->default(true);
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('fin_commissions')) {
            Schema::create('fin_commissions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('reservation_id')->index();
                $table->string('kind', 20);
                $table->uuid('beneficiary_user_id')->nullable();
                $table->uuid('beneficiary_agency_id')->nullable();
                $table->uuid('rule_id')->nullable();
                $table->string('rule_description', 255);
                $table->decimal('base_amount', 14, 2);
                $table->decimal('pct', 5, 2);
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->decimal('fx_rate', 12, 6)->nullable();
                $table->string('status', 20)->default('calculada')->index();
                $table->timestampTz('approved_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestampTz('paid_at')->nullable();
                $table->string('payment_method', 40)->nullable();
                $table->string('payment_ref', 80)->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
                $table->unique(['tenant_id', 'reservation_id', 'kind']);
            });
        }

        if (!Schema::hasTable('fin_settlements')) {
            Schema::create('fin_settlements', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('reservation_id')->unique();
                $table->timestampTz('closed_at');
                $table->uuid('closed_by');
                $table->decimal('income_pen', 14, 2);
                $table->decimal('cost_pen', 14, 2);
                $table->decimal('commissions_pen', 14, 2);
                $table->decimal('net_pen', 14, 2);
                $table->decimal('gross_margin_pct', 6, 2);
                $table->jsonb('snapshot');
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('fin_document_series')) {
            Schema::create('fin_document_series', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->char('doc_type', 2);
                $table->char('series', 4);
                $table->integer('next_number')->default(1);
                $table->uuid('sede_id')->nullable();
                $table->boolean('active')->default(true);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'series']);
            });
        }

        if (!Schema::hasTable('fin_invoices')) {
            Schema::create('fin_invoices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('number', 20);
                $table->char('doc_type', 2);
                $table->char('series', 4);
                $table->integer('correlative');
                $table->date('issue_date');
                $table->char('period', 7)->index();
                $table->uuid('client_id')->nullable()->index();
                $table->string('customer_doc_type', 10);
                $table->string('customer_doc_number', 20);
                $table->string('customer_name', 200);
                $table->string('customer_address', 255)->nullable();
                $table->boolean('customer_domiciled')->default(true);
                $table->uuid('reservation_id')->nullable()->index();
                $table->uuid('receivable_id')->nullable()->index();
                $table->string('operation_type', 20)->default('gravada');
                $table->char('currency', 3)->default('PEN');
                $table->decimal('fx_rate', 12, 6)->nullable();
                $table->decimal('taxable_amount', 14, 2)->default(0.00);
                $table->decimal('igv_amount', 14, 2)->default(0.00);
                $table->decimal('exempt_amount', 14, 2)->default(0.00);
                $table->decimal('total_amount', 14, 2);
                $table->string('amount_in_words', 255)->nullable();
                $table->string('description', 500);
                $table->uuid('ref_invoice_id')->nullable();
                $table->char('credit_note_reason_code', 2)->nullable();
                $table->string('credit_note_reason', 255)->nullable();
                $table->string('status', 20)->default('emitido')->index();
                $table->string('fiscal_provider', 30)->default('OSE_SIMULATOR');
                $table->string('ose_ticket', 80)->nullable();
                $table->string('ose_response_code', 10)->nullable();
                $table->text('ose_response_message')->nullable();
                $table->timestampTz('ose_responded_at')->nullable();
                $table->string('hash_code', 64)->nullable();
                $table->timestampTz('delivered_at')->nullable();
                $table->uuid('issued_by');
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'number']);
                $table->unique(['tenant_id', 'series', 'correlative']);
            });
        }

        if (!Schema::hasTable('fin_reconciliation_closures')) {
            Schema::create('fin_reconciliation_closures', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 20);
                $table->char('period', 7);
                $table->timestampTz('closed_at');
                $table->uuid('closed_by');
                $table->integer('lines_count');
                $table->decimal('credits', 14, 2);
                $table->decimal('debits', 14, 2);
                $table->decimal('net', 14, 2);
                $table->integer('matched_count');
                $table->integer('others_count');
                $table->timestampsTz();
            });
        }

        if (!Schema::hasTable('fin_bank_lines')) {
            Schema::create('fin_bank_lines', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 20);
                $table->string('bank', 20);
                $table->date('value_date');
                $table->string('description', 255);
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3)->default('PEN');
                $table->uuid('matched_payment_id')->nullable();
                $table->uuid('matched_payable_id')->nullable();
                $table->string('status', 20)->default('pendiente')->index();
                $table->string('resolution', 20)->nullable();
                $table->text('resolution_note')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->uuid('closure_id')->nullable();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (!Schema::hasTable('fin_period_closes')) {
            Schema::create('fin_period_closes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->char('period', 7);
                $table->string('status', 10)->default('abierto');
                $table->timestampTz('closed_at')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->jsonb('snapshot')->nullable();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'period']);
            });
        }

        if (!Schema::hasTable('fin_period_close_checks')) {
            Schema::create('fin_period_close_checks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('period_close_id')->index();
                $table->string('check_code', 20);
                $table->uuid('checked_by');
                $table->timestampTz('checked_at');
                $table->timestampsTz();

                $table->unique(['period_close_id', 'check_code']);
            });
        }

        if (!Schema::hasTable('fin_fx_rates')) {
            Schema::create('fin_fx_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->date('rate_date');
                $table->char('currency', 3)->default('USD');
                $table->decimal('buy', 12, 6);
                $table->decimal('sell', 12, 6);
                $table->string('source', 10)->default('SUNAT');
                $table->timestampsTz();

                $table->unique(['tenant_id', 'rate_date', 'currency']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_fx_rates');
        Schema::dropIfExists('fin_period_close_checks');
        Schema::dropIfExists('fin_period_closes');
        Schema::dropIfExists('fin_bank_lines');
        Schema::dropIfExists('fin_reconciliation_closures');
        Schema::dropIfExists('fin_invoices');
        Schema::dropIfExists('fin_document_series');
        Schema::dropIfExists('fin_settlements');
        Schema::dropIfExists('fin_commissions');
        Schema::dropIfExists('fin_commission_rules');
    }
};