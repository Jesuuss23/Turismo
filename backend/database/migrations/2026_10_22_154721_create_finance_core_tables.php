<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Cuentas Bancarias
        if (!Schema::hasTable('fin_bank_accounts')) {
            Schema::create('fin_bank_accounts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('bank', 20);
                $table->string('account_type', 20)->default('cta_cte');
                $table->char('currency', 3)->default('PEN');
                $table->string('number', 30);
                $table->string('cci', 30)->nullable();
                $table->string('label', 80);
                $table->boolean('active')->default(true);
                $table->timestampsTz();
            });
        }

        // 2. Métodos de Pago
        if (!Schema::hasTable('fin_payment_methods')) {
            Schema::create('fin_payment_methods', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 40);
                $table->string('label', 80);
                $table->boolean('requires_reference')->default(true);
                $table->boolean('is_b2b_credit')->default(false);
                $table->boolean('active')->default(true);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 3. Cuentas por Cobrar
        if (!Schema::hasTable('fin_receivables')) {
            Schema::create('fin_receivables', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('reservation_id')->index();
                $table->uuid('client_id')->index();
                $table->string('kind', 20)->default('saldo');
                $table->string('concept', 255);
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->decimal('fx_rate', 12, 6)->nullable();
                $table->decimal('paid_amount', 14, 2)->default(0.00);
                $table->date('due_date');
                $table->string('status', 20)->default('abierta')->index();
                $table->timestampTz('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestampTz('last_reminder_at')->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 4. Recordatorios
        if (!Schema::hasTable('fin_receivable_reminders')) {
            Schema::create('fin_receivable_reminders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('receivable_id')->index();
                $table->string('channels', 60);
                $table->uuid('sent_by')->nullable();
                $table->timestampTz('sent_at');
                $table->decimal('balance_at_send', 14, 2);
                $table->timestampsTz();
            });
        }

        // 5. Pagos
        if (!Schema::hasTable('fin_payments')) {
            Schema::create('fin_payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('reservation_id')->index();
                $table->uuid('receivable_id')->nullable()->index();
                $table->string('kind', 20)->default('cobro');
                $table->string('source', 20)->default('backoffice');
                $table->string('method', 40);
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->decimal('fx_rate', 12, 6)->nullable();
                $table->date('paid_on');
                $table->string('reference', 80)->nullable();
                $table->text('note')->nullable();
                $table->uuid('evidence_document_id')->nullable();
                $table->string('status', 20)->default('pendiente')->index();
                $table->text('reject_reason')->nullable();
                $table->smallInteger('retries')->default(0);
                $table->timestampTz('reverted_at')->nullable();
                $table->uuid('reverted_by')->nullable();
                $table->text('revert_reason')->nullable();
                $table->uuid('registered_by')->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 6. Aplicaciones de Pagos
        if (!Schema::hasTable('fin_payment_applications')) {
            Schema::create('fin_payment_applications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('payment_id')->index();
                $table->uuid('receivable_id')->index();
                $table->decimal('amount', 14, 2);
                $table->timestampTz('applied_at');
                $table->uuid('applied_by');
                $table->timestampTz('reversed_at')->nullable();
                $table->uuid('reversed_by')->nullable();
                $table->timestampsTz();
            });
        }

        // 7. Cuentas por Pagar (CxP)
        if (!Schema::hasTable('fin_payables')) {
            Schema::create('fin_payables', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('provider_id')->index();
                $table->uuid('reservation_id')->nullable()->index();
                $table->uuid('reservation_service_id')->nullable()->index();
                $table->string('kind', 20)->default('servicio');
                $table->string('cost_type', 40)->nullable();
                $table->string('concept', 255);
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->decimal('fx_rate', 12, 6)->nullable();
                $table->date('due_date');
                $table->decimal('paid_amount', 14, 2)->default(0.00);
                $table->string('status', 20)->default('abierta')->index();
                $table->timestampTz('approved_at')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->string('supplier_doc_number', 30)->nullable();
                $table->text('evidence_note')->nullable();
                $table->date('scheduled_date')->nullable();
                $table->uuid('scheduled_bank_account_id')->nullable();
                $table->uuid('scheduled_by')->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 8. Egresos / Pagos a Proveedores (Payouts)
        if (!Schema::hasTable('fin_payable_payouts')) {
            Schema::create('fin_payable_payouts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('payable_id')->index();
                $table->date('paid_on');
                $table->decimal('amount', 14, 2);
                $table->decimal('fx_rate', 12, 6)->nullable();
                $table->string('method', 30);
                $table->uuid('bank_account_id')->nullable();
                $table->string('operation_ref', 80);
                $table->uuid('bank_line_id')->nullable();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_payable_payouts');
        Schema::dropIfExists('fin_payables');
        Schema::dropIfExists('fin_payment_applications');
        Schema::dropIfExists('fin_payments');
        Schema::dropIfExists('fin_receivable_reminders');
        Schema::dropIfExists('fin_payment_methods');
        Schema::dropIfExists('fin_bank_accounts');
    }
};