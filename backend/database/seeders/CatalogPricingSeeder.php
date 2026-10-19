<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogPricingSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Secuencias correlativas (§3)
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'rate', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Tarifa', 'prefix' => 'TAR-', 'pad_width' => 3, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'season', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Temporada', 'prefix' => 'TMP-', 'pad_width' => 2, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'price_rule', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Regla de Precio', 'prefix' => 'PR-', 'pad_width' => 2, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        // 2. Temporadas Base de Perú (Cusco)
        $seasons = [
            [
                'ref' => 'TMP-01',
                'name' => 'Temporada Alta Cusco (Fiestas & Inti Raymi)',
                'type' => 'Alta',
                'date_from' => now()->year . '-06-01',
                'date_to' => now()->year . '-08-31',
                'priority' => 10,
                'factor' => 1.25,
                'status' => 'activa',
            ],
            [
                'ref' => 'TMP-02',
                'name' => 'Temporada Regular / Media',
                'type' => 'Media',
                'date_from' => now()->year . '-04-01',
                'date_to' => now()->year . '-11-30',
                'priority' => 50,
                'factor' => 1.00,
                'status' => 'activa',
            ],
            [
                'ref' => 'TMP-03',
                'name' => 'Fiestas Patrias Perú',
                'type' => 'Feriado',
                'date_from' => now()->year . '-07-26',
                'date_to' => now()->year . '-07-31',
                'priority' => 5, // Mayor jerarquía
                'factor' => 1.35,
                'status' => 'activa',
            ],
        ];

        foreach ($seasons as $s) {
            DB::table('catalog_seasons')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'ref' => $s['ref']],
                array_merge($s, ['id' => Str::uuid7()->toString(), 'created_at' => now(), 'updated_at' => now()])
            );
        }

        // 3. Reglas de Pricing Oficiales (§5.2 CAT-BR-42)
        $rules = [
            [
                'ref' => 'PR-01',
                'name' => 'Markup Directo & E-commerce (25%)',
                'kind' => 'Markup %',
                'value' => 25.00,
                'scope' => 'Canal Directo y Web',
                'channel' => 'Directo',
                'priority' => 10,
                'status' => 'activa',
            ],
            [
                'ref' => 'PR-02',
                'name' => 'Markup Mayorista B2B (12%)',
                'kind' => 'Markup %',
                'value' => 12.00,
                'scope' => 'Canal Agencias B2B',
                'channel' => 'B2B',
                'priority' => 10,
                'status' => 'activa',
            ],
            [
                'ref' => 'PR-03',
                'name' => 'IGV Turismo Interno / Nacional (18%)',
                'kind' => 'Impuesto %',
                'value' => 18.00,
                'scope' => 'Pasajeros Domiciliados (Perú)',
                'customer_residency' => 'dom',
                'priority' => 20,
                'status' => 'activa',
            ],
            [
                'ref' => 'PR-04',
                'name' => 'Exoneración Receptivo D.L. 919 (0% IGV)',
                'kind' => 'Impuesto %',
                'value' => 0.00,
                'scope' => 'Turistas Extranjeros No Domiciliados',
                'customer_residency' => 'nodom',
                'priority' => 20,
                'status' => 'activa',
            ],
            [
                'ref' => 'PR-05',
                'name' => 'Descuento Grupal (≥10 pasajeros: 5%)',
                'kind' => 'Descuento %',
                'value' => 5.00,
                'scope' => 'Grupos de 10 o más personas',
                'min_pax' => 10,
                'priority' => 30,
                'status' => 'activa',
            ],
        ];

        foreach ($rules as $r) {
            DB::table('catalog_price_rules')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'ref' => $r['ref']],
                array_merge($r, ['id' => Str::uuid7()->toString(), 'created_at' => now(), 'updated_at' => now()])
            );
        }

        // 4. Tarifas base para el producto PRD-001
        $prod = DB::table('catalog_products')->where('tenant_id', $tenant->id)->where('ref', 'PRD-001')->first();
        if ($prod) {
            $rates = [
                [
                    'ref' => 'TAR-001',
                    'product_id' => $prod->id,
                    'rate_type' => 'Retail',
                    'segment' => 'Adulto',
                    'channel' => 'Directo',
                    'currency' => 'USD',
                    'price_basis' => 'Por persona',
                    'cost' => 165.00,
                    'price' => 280.00,
                    'min_price' => 240.00,
                    'valid_from' => now()->startOfYear()->toDateString(),
                    'valid_to' => now()->addYear()->endOfYear()->toDateString(),
                    'conditions' => 'Tarifa regular para pasajeros adultos en servicio compartido.',
                    'status' => 'vigente',
                ],
                [
                    'ref' => 'TAR-002',
                    'product_id' => $prod->id,
                    'rate_type' => 'Retail',
                    'segment' => 'Niño',
                    'channel' => 'Directo',
                    'currency' => 'USD',
                    'price_basis' => 'Por persona',
                    'cost' => 110.00,
                    'price' => 195.00,
                    'min_price' => 160.00,
                    'valid_from' => now()->startOfYear()->toDateString(),
                    'valid_to' => now()->addYear()->endOfYear()->toDateString(),
                    'conditions' => 'Aplica para menores entre 4 y 11 años con documento acreditado.',
                    'status' => 'vigente',
                ],
                [
                    'ref' => 'TAR-003',
                    'product_id' => $prod->id,
                    'rate_type' => 'B2B',
                    'segment' => 'Adulto',
                    'channel' => 'B2B',
                    'currency' => 'USD',
                    'price_basis' => 'Por persona',
                    'cost' => 165.00,
                    'price' => 220.00,
                    'min_price' => 200.00,
                    'valid_from' => now()->startOfYear()->toDateString(),
                    'valid_to' => now()->addYear()->endOfYear()->toDateString(),
                    'conditions' => 'Tarifa confidencial neta para agencias asociadas.',
                    'status' => 'vigente',
                ],
            ];

            foreach ($rates as $rt) {
                DB::table('catalog_rates')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'ref' => $rt['ref']],
                    array_merge($rt, ['id' => Str::uuid7()->toString(), 'created_at' => now(), 'updated_at' => now()])
                );
            }
        }
    }
}