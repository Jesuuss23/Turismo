<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuotesBaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Correlativo atómico COT-2026-00001 (§3)
        $year = now()->year;
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'quote', 'series' => (string)$year],
            [
                'id' => Str::uuid7()->toString(),
                'label' => 'Cotización',
                'prefix' => "COT-{$year}-",
                'pad_width' => 5,
                'next_number' => 192,
                'year_reset' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 2. Localizar Entidades para Cotización de Ejemplo (COT-2026-00191)
        $client = DB::table('crm_clients')->where('tenant_id', $tenant->id)->first();
        $user = DB::table('iam_users')->where('tenant_id', $tenant->id)->first();
        $opp = DB::table('crm_opportunities')->where('tenant_id', $tenant->id)->first();
        $product = DB::table('catalog_products')->where('tenant_id', $tenant->id)->where('ref', 'PRD-001')->first();
        $rate = DB::table('catalog_rates')->where('tenant_id', $tenant->id)->where('ref', 'TAR-001')->first();

        if (!$client || !$user || !$product) return;

        $quoteId = Str::uuid7()->toString();
        $versionId = Str::uuid7()->toString();

        // 3. Crear Cotización
        DB::table('quote_quotes')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'code' => 'COT-2026-00191'],
            [
                'id' => $quoteId,
                'client_id' => $client->id,
                'opportunity_id' => $opp ? $opp->id : null,
                'owner_id' => $user->id,
                'title' => 'Machu Picchu Mágico 2 Pax - Servicio VIP',
                'status' => 'enviada',
                'current_version_no' => 1,
                'current_version_id' => $versionId,
                'travel_from' => now()->addDays(15)->toDateString(),
                'travel_to' => now()->addDays(16)->toDateString(),
                'valid_until' => now()->addDays(10)->toDateString(),
                'currency' => 'USD',
                'fx_rate' => 3.740000,
                'pax_adt' => 2,
                'pax_chd' => 0,
                'pax_inf' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 4. Crear Versión v1
        DB::table('quote_versions')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'quote_id' => $quoteId, 'version_no' => 1],
            [
                'id' => $versionId,
                'status' => 'enviada',
                'issued_on' => now()->toDateString(),
                'author_id' => $user->id,
                'discount_pct' => 0.00,
                'tax_pct' => 0.00, // Receptivo no domiciliado
                'markup_pct' => 25.00,
                'notes' => 'Incluye guiado privado bilingüe y tren panorámico.',
                'conditions' => 'Precios sujetos a disponibilidad de boletos de tren y Llaqta.',
                'snapshot_label' => 'PRD-001 v1.0',
                'catalog_snapshot' => json_encode(['product' => $product->name, 'sku' => $product->sku]),
                'policies_snapshot' => json_encode(['cancellation' => 'Gratuita hasta 7 días antes']),
                'cost_total' => 330.00,
                'sale_total' => 560.00,
                'discount_total' => 0.00,
                'net_total' => 560.00,
                'tax_total' => 0.00,
                'grand_total' => 560.00,
                'profit_total' => 230.00,
                'margin_pct' => 41.07,
                'frozen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 5. Crear Línea de la Cotización
        DB::table('quote_items')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenant->id,
            'quote_version_id' => $versionId,
            'line_no' => 1,
            'product_id' => $product->id,
            'product_version' => '1.0',
            'rate_id' => $rate ? $rate->id : null,
            'segment' => 'Adulto',
            'service_date' => now()->addDays(15)->toDateString(),
            'qty' => 1,
            'pax' => 2,
            'unit_cost' => 165.00,
            'unit_price' => 280.00,
            'cost_total' => 330.00,
            'sale_total' => 560.00,
            'season_factor' => 1.000,
            'price_source' => 'rate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}