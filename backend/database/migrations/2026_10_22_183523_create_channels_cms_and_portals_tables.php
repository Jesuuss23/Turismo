<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Contenidos CMS (PROC-055)
        if (!Schema::hasTable('cms_contents')) {
            Schema::create('cms_contents', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30); // CMS-07
                $table->string('title', 200);
                $table->string('type', 30); // Página, Artículo, Banner, Página legal, Landing
                $table->string('channel', 40)->default('Web'); // Web, Web / E-commerce, Portal cliente, Portal pasajero
                $table->string('slug', 60);
                $table->text('body');
                $table->string('seo_title', 60)->nullable();
                $table->string('seo_description', 160)->nullable();
                $table->string('status', 30)->default('borrador')->index(); // borrador, en_revision, aprobado, publicado, despublicado
                $table->uuid('author_id')->nullable();
                $table->timestampTz('published_at')->nullable();
                $table->timestampTz('unpublished_at')->nullable();
                $table->string('placement', 40)->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->integer('lock_version')->default(0);
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code']);
                $table->unique(['tenant_id', 'slug']);
            });
        }

        // 2. Bitácora Editorial de Contenidos
        if (!Schema::hasTable('cms_content_events')) {
            Schema::create('cms_content_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('content_id')->index();
                $table->timestampTz('occurred_at');
                $table->uuid('user_id');
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->string('message', 300);
                $table->string('reason', 300)->nullable();
                $table->timestampsTz();
            });
        }

        // 3. Tickets de Soporte desde Portales (Cliente y Pasajero)
        if (!Schema::hasTable('channel_support_tickets')) {
            Schema::create('channel_support_tickets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30); // TCK-0001
                $table->uuid('crm_client_id')->nullable()->index();
                $table->uuid('reservation_id')->nullable()->index();
                $table->string('source', 30); // portal_cliente, portal_pasajero
                $table->string('subject', 200);
                $table->string('category', 40);
                $table->string('topic', 40)->nullable();
                $table->string('priority', 10)->default('media'); // baja, media, alta
                $table->text('message');
                $table->string('status', 30)->default('abierta')->index(); // abierta, en_proceso, resuelta, cerrada
                $table->uuid('owner_id')->nullable();
                $table->uuid('created_by_user_id')->nullable();
                $table->timestampTz('first_response_due_at')->nullable();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
            });
        }

        // 4. Confirmación de Lectura de Alertas por Pasajeros
        if (!Schema::hasTable('ops_incident_pax_acks')) {
            Schema::create('ops_incident_pax_acks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('incident_id')->index();
                $table->uuid('user_id')->index();
                $table->timestampTz('acknowledged_at');
                $table->timestampsTz();

                $table->unique(['tenant_id', 'incident_id', 'user_id']);
            });
        }

        // 5. Retroalimentación y Comentarios de Pasajeros (v2.1)
        if (!Schema::hasTable('channel_pax_feedback')) {
            Schema::create('channel_pax_feedback', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('reservation_id')->index();
                $table->uuid('reservation_service_id')->nullable();
                $table->string('type', 30); // comentario, sugerencia, felicitacion, reclamo, incidencia
                $table->smallInteger('rating')->nullable(); // 1 a 5
                $table->text('text');
                $table->string('channel', 30)->default('Portal pasajero');
                $table->string('contact_email', 190)->nullable();
                $table->string('contact_phone', 40)->nullable();
                $table->string('status', 30)->default('recibido');
                $table->text('agency_response')->nullable();
                $table->timestampTz('responded_at')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_pax_feedback');
        Schema::dropIfExists('ops_incident_pax_acks');
        Schema::dropIfExists('channel_support_tickets');
        Schema::dropIfExists('cms_content_events');
        Schema::dropIfExists('cms_contents');
    }
};