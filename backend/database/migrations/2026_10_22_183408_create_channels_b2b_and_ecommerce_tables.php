<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Listas de Precios B2B
        if (!Schema::hasTable('channel_b2b_price_lists')) {
            Schema::create('channel_b2b_price_lists', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('name', 80);
                $table->smallInteger('year')->nullable();
                $table->string('status', 30)->default('activa');
                $table->timestampsTz();

                $table->unique(['tenant_id', 'name']);
            });
        }

        // 2. Agencias Asociadas (Convenios B2B)
        if (!Schema::hasTable('channel_b2b_agencies')) {
            Schema::create('channel_b2b_agencies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30); // AGB-01
                $table->string('legal_name', 200);
                $table->char('ruc', 11);
                $table->string('contact_name', 150);
                $table->string('booking_email', 190)->nullable();
                $table->uuid('crm_client_id')->nullable()->index();
                $table->uuid('price_list_id')->index();
                $table->decimal('commission_pct', 5, 2)->default(10.00);
                $table->char('credit_currency', 3)->default('USD');
                $table->decimal('credit_limit', 14, 2)->default(0.00);
                $table->decimal('credit_used', 14, 2)->default(0.00);
                $table->date('valid_from');
                $table->date('valid_to');
                $table->string('status', 30)->default('activa')->index(); // activa, bloqueada, inactiva
                $table->string('block_reason', 30)->nullable(); // manual, credito_excedido
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
                $table->unique(['tenant_id', 'ruc']);
            });
        }

        // 3. Tarifas Netas B2B por Producto y Agencia
        if (!Schema::hasTable('catalog_product_b2b_rates')) {
            Schema::create('catalog_product_b2b_rates', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('product_id')->index();
                $table->uuid('agency_id')->index();
                $table->uuid('price_list_id')->nullable();
                $table->decimal('net_price', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->decimal('commission_pct', 5, 2)->default(10.00);
                $table->boolean('credit_allowed')->default(true);
                $table->date('valid_to')->nullable();
                $table->string('conditions', 300)->nullable();
                $table->string('restrictions', 300)->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'product_id', 'agency_id']);
            });
        }

        // 4. Libro de Movimientos de Línea de Crédito B2B
        if (!Schema::hasTable('channel_b2b_credit_movements')) {
            Schema::create('channel_b2b_credit_movements', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('agency_id')->index();
                $table->string('movement_type', 30); // cargo_reserva, pago_aplicado, reversion_pago, anulacion_reserva, ajuste
                $table->uuid('reservation_id')->nullable()->index();
                $table->uuid('payment_id')->nullable()->index();
                $table->decimal('amount', 14, 2); // positivo consume, negativo libera
                $table->char('currency', 3)->default('USD');
                $table->decimal('source_amount', 14, 2)->nullable();
                $table->char('source_currency', 3)->nullable();
                $table->decimal('fx_rate', 12, 6)->nullable();
                $table->decimal('balance_after', 14, 2);
                $table->string('note', 250)->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'agency_id', 'created_at']);
            });
        }

        // 5. Solicitudes de Liquidación de Comisiones B2B
        if (!Schema::hasTable('channel_b2b_settlement_requests')) {
            Schema::create('channel_b2b_settlement_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30); // LIQ-2026-00001
                $table->uuid('agency_id')->index();
                $table->decimal('amount_pen', 14, 2);
                $table->decimal('fx_rate', 12, 6)->default(1.0);
                $table->jsonb('commission_ids');
                $table->string('status', 30)->default('solicitada')->index(); // solicitada, aprobada, rechazada, pagada
                $table->uuid('requested_by');
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 6. Pedidos E-commerce (Gestión Web)
        if (!Schema::hasTable('channel_ecom_orders')) {
            Schema::create('channel_ecom_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30); // WEB-10434
                $table->timestampTz('ordered_at');
                $table->uuid('crm_client_id')->nullable()->index();
                $table->string('customer_name', 150);
                $table->string('customer_email', 190)->index();
                $table->string('customer_phone', 40)->nullable();
                $table->string('customer_country', 60)->nullable();
                $table->uuid('product_id')->index();
                $table->uuid('departure_id')->nullable()->index();
                $table->date('service_date');
                $table->smallInteger('pax_adults')->default(1);
                $table->smallInteger('pax_children')->default(0);
                $table->smallInteger('pax_total')->default(1);
                $table->jsonb('config_snapshot')->nullable();
                $table->jsonb('price_snapshot')->nullable();
                $table->string('promo_code', 40)->nullable();
                $table->uuid('promotion_id')->nullable();
                $table->decimal('discount_amount', 14, 2)->default(0.00);
                $table->decimal('total', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->decimal('cost_total', 14, 2)->default(0.00);
                $table->uuid('reservation_id')->nullable()->unique();
                $table->uuid('receivable_id')->nullable()->index();
                $table->string('status', 30)->default('pago_pendiente')->index(); // pago_pendiente, pago_rechazado, confirmado, cancelado, expirado
                $table->timestampTz('hold_expires_at')->nullable();
                $table->string('cancel_reason', 300)->nullable();
                $table->jsonb('buyer_snapshot')->nullable();
                $table->jsonb('utm')->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 7. Intentos de Pago de Pedidos Web
        if (!Schema::hasTable('channel_ecom_payment_attempts')) {
            Schema::create('channel_ecom_payment_attempts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('order_id')->index();
                $table->timestampTz('attempted_at');
                $table->string('card_masked', 20)->default('•••• 1111');
                $table->string('result', 20); // aprobado, rechazado
                $table->string('gateway_code', 20)->nullable();
                $table->string('message', 200)->nullable();
                $table->string('gateway_txn_id', 80)->nullable();
                $table->uuid('payment_id')->nullable();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'gateway_txn_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_ecom_payment_attempts');
        Schema::dropIfExists('channel_ecom_orders');
        Schema::dropIfExists('channel_b2b_settlement_requests');
        Schema::dropIfExists('channel_b2b_credit_movements');
        Schema::dropIfExists('catalog_product_b2b_rates');
        Schema::dropIfExists('channel_b2b_agencies');
        Schema::dropIfExists('channel_b2b_price_lists');
    }
};