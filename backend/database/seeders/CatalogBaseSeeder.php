<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogBaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Secuencias correlativas (§3)
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'destination', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Destino', 'prefix' => 'D', 'pad_width' => 2, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'category', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Categoría', 'prefix' => 'CAT-', 'pad_width' => 1, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        // 2. 13 Tipos de producto (§3.1)
        $types = [
            ['name' => 'TOUR', 'requires_itinerary' => true, 'requires_components' => true, 'sort_order' => 1],
            ['name' => 'EXCURSIÓN', 'requires_itinerary' => true, 'requires_components' => true, 'sort_order' => 2],
            ['name' => 'PAQUETE', 'requires_itinerary' => true, 'requires_components' => true, 'sort_order' => 3],
            ['name' => 'CIRCUITO', 'requires_itinerary' => true, 'requires_components' => true, 'sort_order' => 4],
            ['name' => 'PROGRAMA TURÍSTICO', 'requires_itinerary' => true, 'requires_components' => true, 'sort_order' => 5],
            ['name' => 'TRASLADO', 'requires_itinerary' => false, 'requires_components' => true, 'sort_order' => 6],
            ['name' => 'ALOJAMIENTO', 'requires_itinerary' => false, 'requires_components' => true, 'sort_order' => 7],
            ['name' => 'ACTIVIDAD', 'requires_itinerary' => false, 'requires_components' => true, 'sort_order' => 8],
            ['name' => 'ENTRADA', 'requires_itinerary' => false, 'requires_components' => true, 'sort_order' => 9],
            ['name' => 'TRANSPORTE', 'requires_itinerary' => false, 'requires_components' => true, 'sort_order' => 10],
            ['name' => 'ALIMENTACIÓN', 'requires_itinerary' => false, 'requires_components' => true, 'sort_order' => 11],
            ['name' => 'GUÍAJE', 'requires_itinerary' => false, 'requires_components' => true, 'sort_order' => 12],
            ['name' => 'PRODUCTO PERSONALIZADO', 'requires_itinerary' => false, 'requires_components' => false, 'sort_order' => 13],
        ];

        foreach ($types as $t) {
            DB::table('catalog_product_types')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'name' => $t['name']],
                array_merge($t, ['id' => Str::uuid7()->toString(), 'created_at' => now(), 'updated_at' => now()])
            );
        }

        // 3. Categorías y Subcategorías
        $categories = [
            'Aventura' => ['Trekking', 'Canotaje', 'Ciclismo'],
            'Cultural' => ['Arqueología', 'Historia', 'Museos'],
            'Naturaleza' => ['Observación de Aves', 'Selva', 'Paisajes'],
            'Gastronómico' => ['Rutas Culinarias', 'Talleres'],
            'Vivencial' => ['Comunidades', 'Tradiciones'],
        ];

        $catNum = 1;
        foreach ($categories as $catName => $subcats) {
            $catId = Str::uuid7()->toString();
            DB::table('catalog_product_categories')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'name' => $catName],
                [
                    'id' => $catId,
                    'ref' => "CAT-{$catNum}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $catRecord = DB::table('catalog_product_categories')->where('tenant_id', $tenant->id)->where('name', $catName)->first();

            foreach ($subcats as $subName) {
                DB::table('catalog_product_subcategories')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'category_id' => $catRecord->id, 'name' => $subName],
                    ['id' => Str::uuid7()->toString(), 'created_at' => now(), 'updated_at' => now()]
                );
            }
            $catNum++;
        }

        // 4. Árbol de Destinos PROC-011 (Perú -> Cusco -> Valle Sagrado -> Machu Picchu)
        $peruId = Str::uuid7()->toString();
        DB::table('catalog_destinations')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'name' => 'Perú'],
            [
                'id' => $peruId,
                'ref' => 'D01',
                'parent_id' => null,
                'type' => 'País',
                'level' => 0,
                'lat' => -9.189967,
                'lng' => -75.015152,
                'path' => $peruId,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $peru = DB::table('catalog_destinations')->where('tenant_id', $tenant->id)->where('name', 'Perú')->first();

        $cuscoId = Str::uuid7()->toString();
        DB::table('catalog_destinations')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'name' => 'Cusco'],
            [
                'id' => $cuscoId,
                'ref' => 'D02',
                'parent_id' => $peru->id,
                'type' => 'Departamento',
                'level' => 2,
                'lat' => -13.531950,
                'lng' => -71.967463,
                'path' => "{$peru->id}/{$cuscoId}",
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $cusco = DB::table('catalog_destinations')->where('tenant_id', $tenant->id)->where('name', 'Cusco')->first();

        $mpId = Str::uuid7()->toString();
        DB::table('catalog_destinations')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'name' => 'Machu Picchu'],
            [
                'id' => $mpId,
                'ref' => 'D03',
                'parent_id' => $cusco->id,
                'type' => 'Atracción',
                'level' => 6,
                'lat' => -13.163141,
                'lng' => -72.544963,
                'path' => "{$peru->id}/{$cusco->id}/{$mpId}",
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}