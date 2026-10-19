<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Cabecera de Reservas
        if (!Schema::hasTable('booking_reservations')) {
            Schema::create('booking_reservations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('quote_id')->nullable()->index();
                $table->uuid('quote_version_id')->nullable();
                $table->smallInteger('quote_version_no')->nullable();
                $table->uuid('client_id')->index();
                $table->uuid('owner_id')->index();
                $table->string('title', 200);
                $table->string('status', 30)->default('confirmada')->index();
                $table->string('channel', 15)->default('Directo');
                $table->date('travel_from');
                $table->date('travel_to');
                $table->char('currency', 3)->default('USD');
                $table->decimal('fx_rate', 12, 6)->default(3.74);
                $table->decimal('total_amount', 14, 2);
                $table->string('snapshot_label', 300)->nullable();
                $table->jsonb('catalog_snapshot')->nullable();
                $table->jsonb('policies_snapshot')->nullable();
                $table->text('notes')->nullable();
                $table->timestampTz('rescheduled_at')->nullable();
                $table->timestampTz('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->decimal('penalty_amount', 14, 2)->nullable();
                $table->decimal('refund_amount', 14, 2)->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 2. Pasajeros
        if (!Schema::hasTable('booking_passengers')) {
            Schema::create('booking_passengers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('reservation_id')->index();
                $table->smallInteger('seq')->default(1);
                $table->string('full_name', 150);
                $table->string('doc_type', 12)->nullable();
                $table->string('doc_number', 30)->nullable();
                $table->char('pax_type', 3)->default('ADT');
                $table->string('nationality', 60)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('diet_notes', 200)->nullable();
                $table->boolean('is_placeholder')->default(true);
                $table->timestampsTz();
            });
        }

        // 3. Servicios desglosados
        if (!Schema::hasTable('booking_services')) {
            Schema::create('booking_services', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('reservation_id')->index();
                $table->string('code', 20);
                $table->uuid('product_id')->nullable();
                $table->uuid('quote_item_id')->nullable();
                $table->uuid('provider_id')->nullable();
                $table->string('name', 200);
                $table->date('service_date');
                $table->time('service_time')->nullable();
                $table->integer('pax')->default(1);
                $table->string('status', 20)->default('pendiente');
                $table->decimal('cost_amount', 14, 2)->default(0);
                $table->decimal('price_amount', 14, 2)->default(0);
                $table->boolean('is_required')->default(true);
                $table->string('confirmation_code', 40)->nullable();
                $table->string('confirmed_by_name', 120)->nullable();
                $table->timestampsTz();
            });
        }

        // 4. Cuentas por Cobrar (CxC 40/60)
        if (!Schema::hasTable('fin_receivables')) {
            Schema::create('fin_receivables', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('reservation_id')->index();
                $table->uuid('client_id')->index();
                $table->string('concept', 200);
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->date('due_date');
                $table->decimal('paid_amount', 14, 2)->default(0);
                $table->string('status', 20)->default('abierta');
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_receivables');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('booking_passengers');
        Schema::dropIfExists('booking_reservations');
    }
};