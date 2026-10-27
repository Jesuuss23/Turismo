<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::table('tenants')->value('id');
        $user = DB::table('iam_users')->where('tenant_id', $tenantId)->first();
        $client = DB::table('crm_clients')->where('tenant_id', $tenantId)->first();
        $reservation = DB::table('booking_reservations')->where('tenant_id', $tenantId)->first();
        $provider = DB::table('catalog_providers')->where('tenant_id', $tenantId)->first();

        if (!$client || !$reservation || !$provider) {
            $this->command->error("Faltan entidades base (cliente, reserva o proveedor).");
            return;
        }

        // 1. Cuentas por Cobrar (CxC)
        $cxc1Id = (string) Str::uuid7();
        DB::table('fin_receivables')->updateOrInsert(
            ['tenant_id' => $tenantId, 'code' => 'CXC-00810'],
            [
                'id' => $cxc1Id,
                'reservation_id' => $reservation->id,
                'client_id' => $client->id,
                'kind' => 'anticipo',
                'concept' => 'Anticipo 50% — Tour Machu Picchu Clásico',
                'amount' => 1200.00,
                'currency' => 'USD',
                'paid_amount' => 600.00,
                'due_date' => now()->addDays(5)->toDateString(),
                'status' => 'parcial',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $cxc2Id = (string) Str::uuid7();
        DB::table('fin_receivables')->updateOrInsert(
            ['tenant_id' => $tenantId, 'code' => 'CXC-00811'],
            [
                'id' => $cxc2Id,
                'reservation_id' => $reservation->id,
                'client_id' => $client->id,
                'kind' => 'saldo',
                'concept' => 'Saldo Liquidación — Circuito Valle Sagrado',
                'amount' => 850.00,
                'currency' => 'USD',
                'paid_amount' => 0.00,
                'due_date' => now()->subDays(3)->toDateString(),
                'status' => 'abierta',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 2. Pago aplicado a CxC-00810
        $paymentId = (string) Str::uuid7();
        DB::table('fin_payments')->updateOrInsert(
            ['tenant_id' => $tenantId, 'code' => 'PAG-02050'],
            [
                'id' => $paymentId,
                'reservation_id' => $reservation->id,
                'receivable_id' => $cxc1Id,
                'kind' => 'cobro',
                'source' => 'backoffice',
                'method' => 'transferencia_bcp',
                'amount' => 600.00,
                'currency' => 'USD',
                'paid_on' => now()->subDays(1)->toDateString(),
                'reference' => 'BCP-OP-8849102',
                'note' => 'Abono inicial confirmado vía BCP',
                'status' => 'aplicado',
                'registered_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('fin_payment_applications')->updateOrInsert(
            ['tenant_id' => $tenantId, 'payment_id' => $paymentId],
            [
                'id' => (string) Str::uuid7(),
                'receivable_id' => $cxc1Id,
                'amount' => 600.00,
                'applied_at' => now(),
                'applied_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 3. Cuentas por Pagar (CxP)
        DB::table('fin_payables')->updateOrInsert(
            ['tenant_id' => $tenantId, 'code' => 'CXP-00501'],
            [
                'id' => (string) Str::uuid7(),
                'provider_id' => $provider->id,
                'reservation_id' => $reservation->id,
                'kind' => 'servicio',
                'concept' => 'Servicio de Alojamiento — Hotel Cusco Plaza',
                'amount' => 480.00,
                'currency' => 'USD',
                'paid_amount' => 0.00,
                'due_date' => now()->addDays(10)->toDateString(),
                'status' => 'aprobada',
                'approved_at' => now(),
                'approved_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('fin_payables')->updateOrInsert(
            ['tenant_id' => $tenantId, 'code' => 'CXP-00502'],
            [
                'id' => (string) Str::uuid7(),
                'provider_id' => $provider->id,
                'reservation_id' => $reservation->id,
                'kind' => 'costo_adicional',
                'cost_type' => 'gasto_operativo_destino',
                'concept' => 'Alimentación de campo no prevista',
                'amount' => 150.00,
                'currency' => 'USD',
                'paid_amount' => 0.00,
                'due_date' => now()->addDays(4)->toDateString(),
                'status' => 'abierta',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 4. Factura SUNAT
        DB::table('fin_invoices')->updateOrInsert(
            ['tenant_id' => $tenantId, 'number' => 'F001-00000001'],
            [
                'id' => (string) Str::uuid7(),
                'doc_type' => '01',
                'series' => 'F001',
                'correlative' => 1,
                'issue_date' => now()->toDateString(),
                'period' => now()->format('Y-m'),
                'client_id' => $client->id,
                'customer_doc_type' => 'RUC',
                'customer_doc_number' => '20601847192',
                'customer_name' => 'CORPORACION TURISTICA INTERNACIONAL S.A.C.',
                'customer_domiciled' => true,
                'reservation_id' => $reservation->id,
                'receivable_id' => $cxc1Id,
                'operation_type' => 'gravada',
                'currency' => 'PEN',
                'taxable_amount' => 2203.39,
                'igv_amount' => 396.61,
                'total_amount' => 2600.00,
                'description' => 'Paquete turístico Cusco Imperial 4D/3N',
                'status' => 'aceptado',
                'fiscal_provider' => 'OSE_SIMULATOR',
                'ose_response_code' => '0',
                'ose_response_message' => 'La Factura F001-00000001 ha sido aceptada.',
                'ose_responded_at' => now(),
                'hash_code' => hash('sha256', 'F001-00000001|2600.00|' . now()->toIso8601String()),
                'issued_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->command->info("¡Datos demo sembrados con éxito!");
    }
}
