<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fin_payments')) {
            Schema::create('fin_payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('code', 30);
                $table->uuid('receivable_id')->nullable()->index();
                $table->uuid('reservation_id')->nullable()->index();
                $table->string('method', 50);
                $table->decimal('amount', 14, 2);
                $table->char('currency', 3)->default('USD');
                $table->date('paid_on');
                $table->string('status', 20)->default('aplicado');
                $table->string('reference', 200)->nullable();
                $table->string('kind', 20)->default('cobro');
                $table->uuid('registered_by')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_payments');
    }
};