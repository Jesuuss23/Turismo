<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogDeparturesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Secuencias correlativas (§3)
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'departure', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Salida', 'prefix' => 'SAL-', 'pad_width' => 4, 'next_number' => 101, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'hold', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Hold Cupos', 'prefix' => 'HLD-', 'pad_width' => 4, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        // 2. Salidas para el producto PRD-001
        $prod = DB::table('catalog_products')->where('tenant_id', $tenant->id)->where('ref', 'PRD-001')->first();
        $prv = DB::table('catalog_providers')->where('tenant_id', $tenant->id)->where('ref', 'PRV-001')->first();

        if ($prod) {
            $dep1Id = Str::uuid7()->toString();
            DB::table('catalog_product_departures')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'ref' => 'SAL-0101'],
                [
                    'id' => $dep1Id,
                    'product_id' => $prod->id,
                    'departure_date' => now()->addDays(2)->toDateString(),
                    'departure_time' => '05:00:00',
                    'capacity' => 15,
                    'sold' => 4,
                    'status' => 'abierta',
                    'provider_id' => $prv ? $prv->id : null,
                    'meeting_point' => 'Lobby del Hotel o Plaza San Francisco',
                    'return_point' => 'Plaza Regocijo',
                    'notes' => 'Salida garantizada con tren Voyager 07:05 AM',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $dep2Id = Str::uuid7()->toString();
            DB::table('catalog_product_departures')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'ref' => 'SAL-0102'],
                [
                    'id' => $dep2Id,
                    'product_id' => $prod->id,
                    'departure_date' => now()->addDays(5)->toDateString(),
                    'departure_time' => '05:00:00',
                    'capacity' => 15,
                    'sold' => 15,
                    'status' => 'completa',
                    'provider_id' => $prv ? $prv->id : null,
                    'meeting_point' => 'Lobby del Hotel o Plaza San Francisco',
                    'return_point' => 'Plaza Regocijo',
                    'notes' => 'Cupos agotados',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // 3. Hold de ejemplo en SAL-0101
            DB::table('catalog_availability_holds')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'ref' => 'HLD-0001'],
                [
                    'id' => Str::uuid7()->toString(),
                    'departure_id' => $dep1Id,
                    'quantity' => 2,
                    'reference' => 'COT-2026-0042',
                    'status' => 'hold',
                    'expires_at' => now()->addMinutes(30),
                    'notes' => 'Bloqueo temporal por cotización en negociación',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}