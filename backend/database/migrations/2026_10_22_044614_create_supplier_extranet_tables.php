<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Respuestas del Proveedor a Solicitudes de Servicio (supplier_request_responses)
        if (!Schema::hasTable('supplier_request_responses')) {
            Schema::create('supplier_request_responses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('supplier_request_id')->nullable()->index();
                $table->uuid('reservation_service_id')->index();
                $table->uuid('provider_id')->index();
                $table->string('type', 20); // confirmacion, rechazo, alternativa
                $table->uuid('responded_by_user_id')->index();
                $table->timestampTz('responded_at');
                $table->string('confirmed_by_name', 120)->nullable();
                $table->string('confirmation_code', 40)->nullable();
                $table->string('rejection_reason', 60)->nullable();
                $table->string('alt_kind', 40)->nullable();
                $table->date('alt_date')->nullable();
                $table->uuid('alt_service_id')->nullable();
                $table->decimal('alt_net_cost', 14, 2)->nullable();
                $table->char('currency', 3)->nullable();
                $table->date('hold_until')->nullable();
                $table->text('note')->nullable();
                $table->string('review_status', 20)->default('pendiente')->index(); // pendiente, aceptada, rechazada, expirada
                $table->uuid('reviewed_by')->nullable();
                $table->timestampTz('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();
            });
        }

        // 2. Facturas de Proveedores asociadas a CxP (supplier_invoices)
        if (!Schema::hasTable('supplier_invoices')) {
            Schema::create('supplier_invoices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('payable_id')->index();
                $table->uuid('provider_id')->index();
                $table->string('number', 20); // Serie-correlativo F001-0001234
                $table->date('issue_date');
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3);
                $table->decimal('amount_difference', 14, 2)->default(0.00);
                $table->string('file_path', 400)->nullable();
                $table->string('original_file_name', 200)->nullable();
                $table->string('status', 20)->default('registrada')->index(); // registrada, validada, observada, rechazada
                $table->uuid('uploaded_by');
                $table->timestampTz('uploaded_at');
                $table->uuid('validated_by')->nullable();
                $table->timestampTz('validated_at')->nullable();
                $table->text('validation_note')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'provider_id', 'number']);
            });
        }

        // 3. Solicitudes de Actualización de Tarifa Neta (supplier_rate_requests)
        if (!Schema::hasTable('supplier_rate_requests')) {
            Schema::create('supplier_rate_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 20); // SOL-TAR-0101
                $table->uuid('provider_id')->index();
                $table->uuid('service_id')->index();
                $table->decimal('current_cost', 14, 2);
                $table->decimal('proposed_cost', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->date('effective_from'); // >= hoy + 30 días
                $table->string('reason_type', 40);
                $table->text('reason_detail')->nullable();
                $table->string('status', 20)->default('pendiente')->index(); // pendiente, aprobada, rechazada, retirada
                $table->uuid('requested_by');
                $table->timestampTz('requested_at');
                $table->uuid('reviewed_by')->nullable();
                $table->timestampTz('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_rate_requests');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('supplier_request_responses');
    }
};