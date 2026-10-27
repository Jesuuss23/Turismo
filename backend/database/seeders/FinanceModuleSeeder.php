<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceModuleSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = DB::table('tenants')->first();
        if (!$tenant) return;
        $tenantId = $tenant->id;

        // 1. Métodos de Pago
        $methods = [
            ['code' => 'transferencia_bcp', 'label' => 'Transferencia BCP', 'requires_reference' => true],
            ['code' => 'transferencia_bbva', 'label' => 'Transferencia BBVA', 'requires_reference' => true],
            ['code' => 'transferencia_interbank', 'label' => 'Transferencia Interbank', 'requires_reference' => true],
            ['code' => 'tarjeta_pos', 'label' => 'Tarjeta Débito/Crédito (POS)', 'requires_reference' => true],
            ['code' => 'yape_plin', 'label' => 'Yape / Plin', 'requires_reference' => true],
            ['code' => 'efectivo', 'label' => 'Efectivo en Caja', 'requires_reference' => false],
            ['code' => 'credito_b2b', 'label' => 'Línea de Crédito B2B', 'requires_reference' => false, 'is_b2b_credit' => true],
        ];

        foreach ($methods as $m) {
            DB::table('fin_payment_methods')->updateOrInsert(
                ['tenant_id' => $tenantId, 'code' => $m['code']],
                [
                    'id' => Str::uuid7()->toString(),
                    'label' => $m['label'],
                    'requires_reference' => $m['requires_reference'],
                    'is_b2b_credit' => $m['is_b2b_credit'] ?? false,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 2. Cuentas Bancarias Operativas
        $accounts = [
            ['bank' => 'BCP', 'currency' => 'PEN', 'number' => '193-9821441-0-12', 'cci' => '002-193-009821441012-14', 'label' => 'BCP Cta. Cte. Soles'],
            ['bank' => 'BCP', 'currency' => 'USD', 'number' => '193-8841209-1-55', 'cci' => '002-193-008841209155-19', 'label' => 'BCP Cta. Cte. Dólares'],
            ['bank' => 'BBVA', 'currency' => 'PEN', 'number' => '0011-0231-010009412', 'cci' => '011-231-000100094121-08', 'label' => 'BBVA Recaudadora Soles'],
        ];

        foreach ($accounts as $acc) {
            DB::table('fin_bank_accounts')->updateOrInsert(
                ['tenant_id' => $tenantId, 'number' => $acc['number']],
                [
                    'id' => Str::uuid7()->toString(),
                    'bank' => $acc['bank'],
                    'account_type' => 'cta_cte',
                    'currency' => $acc['currency'],
                    'cci' => $acc['cci'],
                    'label' => $acc['label'],
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 3. Series de Facturación Electrónica SUNAT
        $series = [
            ['doc_type' => '01', 'series' => 'F001', 'next' => 1],
            ['doc_type' => '03', 'series' => 'B001', 'next' => 1],
            ['doc_type' => '07', 'series' => 'FC01', 'next' => 1], // NC Factura
            ['doc_type' => '07', 'series' => 'BC01', 'next' => 1], // NC Boleta
        ];

        foreach ($series as $s) {
            DB::table('fin_document_series')->updateOrInsert(
                ['tenant_id' => $tenantId, 'series' => $s['series']],
                [
                    'id' => Str::uuid7()->toString(),
                    'doc_type' => $s['doc_type'],
                    'next_number' => $s['next'],
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 4. Tipo de Cambio del Día
        DB::table('fin_fx_rates')->updateOrInsert(
            ['tenant_id' => $tenantId, 'rate_date' => now()->toDateString(), 'currency' => 'USD'],
            [
                'id' => Str::uuid7()->toString(),
                'buy' => 3.745,
                'sell' => 3.755,
                'source' => 'SUNAT',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 5. Reglas de Comisión Base
        $rules = [
            ['beneficiary_kind' => 'vendedor', 'condition' => 'directo_ecommerce', 'pct' => 3.00, 'base_type' => 'neto_sin_igv'],
            ['beneficiary_kind' => 'vendedor', 'condition' => 'corporativo_grupos', 'pct' => 2.50, 'base_type' => 'neto_sin_igv'],
            ['beneficiary_kind' => 'agencia_b2b', 'condition' => 'b2b_convenio', 'pct' => null, 'base_type' => 'total'],
        ];

        foreach ($rules as $r) {
            DB::table('fin_commission_rules')->updateOrInsert(
                ['tenant_id' => $tenantId, 'condition' => $r['condition']],
                [
                    'id' => Str::uuid7()->toString(),
                    'beneficiary_kind' => $r['beneficiary_kind'],
                    'pct' => $r['pct'],
                    'base_type' => $r['base_type'],
                    'valid_from' => now()->startOfYear()->toDateString(),
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}