<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Órdenes Operativas (ops_orders)
        if (!Schema::hasTable('ops_orders')) {
            Schema::create('ops_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('reservation_id')->index();
                $table->string('title', 200);
                $table->string('status', 30)->default('pendiente_asignacion')->index();
                $table->uuid('coordinator_id')->index();
                $table->date('date_from');
                $table->date('date_to');
                $table->timestampTz('closed_at')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->timestampTz('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 2. Tareas / Checklist de la Orden (ops_order_tasks)
        if (!Schema::hasTable('ops_order_tasks')) {
            Schema::create('ops_order_tasks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('order_id')->index();
                $table->string('title', 250);
                $table->smallInteger('position')->default(1);
                $table->boolean('done')->default(false);
                $table->uuid('done_by')->nullable();
                $table->timestampTz('done_at')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();
            });
        }

        // 3. Recursos: Guías (ops_guides)
        if (!Schema::hasTable('ops_guides')) {
            Schema::create('ops_guides', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 20);
                $table->uuid('user_id')->nullable()->index();
                $table->string('full_name', 150);
                $table->jsonb('languages')->default('["ES"]');
                $table->string('license_number', 40);
                $table->decimal('rating', 2, 1)->default(5.0);
                $table->string('status', 20)->default('disponible')->index();
                $table->string('phone', 30)->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 4. Recursos: Conductores (ops_drivers)
        if (!Schema::hasTable('ops_drivers')) {
            Schema::create('ops_drivers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 20);
                $table->uuid('user_id')->nullable()->index();
                $table->string('full_name', 150);
                $table->string('license_number', 40);
                $table->string('license_class', 10)->default('A-IIIb');
                $table->date('license_expires_on');
                $table->string('status', 20)->default('disponible')->index();
                $table->string('phone', 30)->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 5. Recursos: Vehículos (ops_vehicles)
        if (!Schema::hasTable('ops_vehicles')) {
            Schema::create('ops_vehicles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('plate', 12);
                $table->string('vehicle_type', 60);
                $table->smallInteger('capacity');
                $table->date('soat_expires_on');
                $table->string('status', 20)->default('disponible')->index();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'plate']);
            });
        }

        // 6. Asignaciones de Servicios (ops_assignments)
        if (!Schema::hasTable('ops_assignments')) {
            Schema::create('ops_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('order_id')->index();
                $table->uuid('reservation_service_id')->index();
                $table->date('service_date');
                $table->time('service_time')->nullable();
                $table->string('service_label', 200);
                $table->uuid('guide_id')->nullable()->index();
                $table->uuid('driver_id')->nullable()->index();
                $table->uuid('vehicle_id')->nullable()->index();
                $table->string('status', 30)->default('pendiente')->index();
                $table->timestampTz('confirmed_at')->nullable();
                $table->string('confirmed_via', 10)->nullable();
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('finished_at')->nullable();
                $table->jsonb('warnings_ack')->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'order_id', 'reservation_service_id']);
            });
        }

        // 7. Check-ins de pasajeros en campo (ops_assignment_checkins)
        if (!Schema::hasTable('ops_assignment_checkins')) {
            Schema::create('ops_assignment_checkins', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('assignment_id')->index();
                $table->uuid('passenger_id')->index();
                $table->timestampTz('checked_at');
                $table->string('source', 10)->default('web');
                $table->timestampTz('undone_at')->nullable();
                $table->uuid('undone_by')->nullable();
                $table->timestampsTz();

                $table->unique(['assignment_id', 'passenger_id']);
            });
        }

        // 8. Vouchers de Servicios (ops_vouchers)
        if (!Schema::hasTable('ops_vouchers')) {
            Schema::create('ops_vouchers', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('reservation_id')->index();
                $table->uuid('reservation_service_id')->index();
                $table->uuid('provider_id')->nullable()->index();
                $table->string('service_label', 200);
                $table->date('service_date');
                $table->smallInteger('pax')->default(1);
                $table->string('status', 20)->default('emitido')->index();
                $table->integer('version')->default(1);
                $table->timestampTz('issued_at');
                $table->uuid('issued_by');
                $table->text('notes')->nullable();
                $table->timestampTz('used_at')->nullable();
                $table->uuid('used_by')->nullable();
                $table->string('used_via', 20)->nullable();
                $table->text('void_reason')->nullable();
                $table->timestampTz('void_at')->nullable();
                $table->uuid('void_by')->nullable();
                $table->string('origin', 20)->default('operaciones');
                $table->string('qr_token', 128);
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 9. Incidencias Operativas (ops_incidents)
        if (!Schema::hasTable('ops_incidents')) {
            Schema::create('ops_incidents', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 20);
                $table->uuid('order_id')->nullable()->index();
                $table->uuid('reservation_id')->nullable()->index();
                $table->uuid('reservation_service_id')->nullable()->index();
                $table->string('type', 30);
                $table->string('severity', 10)->default('media')->index();
                $table->string('title', 200);
                $table->text('description');
                $table->string('status', 20)->default('abierta')->index();
                $table->uuid('assignee_id')->nullable()->index();
                $table->uuid('reported_by')->index();
                $table->string('source', 20)->default('web');
                $table->smallInteger('sla_hours')->default(24);
                $table->timestampTz('sla_due_at');
                $table->text('resolution')->nullable();
                $table->timestampTz('resolved_at')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->timestampTz('closed_at')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 10. Evidencias de Campo (ops_evidences)
        if (!Schema::hasTable('ops_evidences')) {
            Schema::create('ops_evidences', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('order_id')->nullable()->index();
                $table->uuid('reservation_service_id')->nullable()->index();
                $table->uuid('incident_id')->nullable()->index();
                $table->string('file_name', 200);
                $table->string('storage_path', 400);
                $table->string('mime_type', 80);
                $table->integer('size_bytes');
                $table->char('sha256', 64)->index();
                $table->string('source', 20)->default('web');
                $table->timestampTz('captured_at');
                $table->uuid('uploaded_by');
                $table->timestampTz('removed_at')->nullable();
                $table->uuid('removed_by')->nullable();
                $table->text('removed_reason')->nullable();
                $table->timestampsTz();
            });
        }

        // 11. Acciones Móviles Outbox / Sync (ops_sync_actions)
        if (!Schema::hasTable('ops_sync_actions')) {
            Schema::create('ops_sync_actions', function (Blueprint $table) {
                $table->uuid('id')->primary(); // client_action_id (UUID v7)
                $table->uuid('tenant_id')->index();
                $table->uuid('user_id')->index();
                $table->uuid('device_id')->nullable();
                $table->string('action_type', 20);
                $table->uuid('assignment_id')->nullable()->index();
                $table->string('coalesce_key', 120)->nullable();
                $table->jsonb('payload');
                $table->timestampTz('occurred_at');
                $table->boolean('captured_offline')->default(false);
                $table->timestampTz('received_at');
                $table->string('result', 20)->default('applied');
                $table->string('conflict_rule', 40)->nullable();
                $table->text('conflict_message')->nullable();
                $table->jsonb('response')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_sync_actions');
        Schema::dropIfExists('ops_evidences');
        Schema::dropIfExists('ops_incidents');
        Schema::dropIfExists('ops_vouchers');
        Schema::dropIfExists('ops_assignment_checkins');
        Schema::dropIfExists('ops_assignments');
        Schema::dropIfExists('ops_vehicles');
        Schema::dropIfExists('ops_drivers');
        Schema::dropIfExists('ops_guides');
        Schema::dropIfExists('ops_order_tasks');
        Schema::dropIfExists('ops_orders');
    }
};