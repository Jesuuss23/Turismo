<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogProductSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Correlativo atómico PRD- (§3)
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'product', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Producto', 'prefix' => 'PRD-', 'pad_width' => 3, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        // 2. Localizar IDs relacionados
        $type = DB::table('catalog_product_types')->where('tenant_id', $tenant->id)->where('name', 'TOUR')->first();
        $cat = DB::table('catalog_product_categories')->where('tenant_id', $tenant->id)->where('name', 'Cultural')->first();
        $dest = DB::table('catalog_destinations')->where('tenant_id', $tenant->id)->where('name', 'Machu Picchu')->first();
        $provider = DB::table('catalog_providers')->where('tenant_id', $tenant->id)->where('ref', 'PRV-001')->first();

        if (!$type || !$cat || !$dest || !$provider) return;

        // 3. Crear Producto Semilla (PRD-001: Machu Picchu Mágico Full Day)
        $productId = Str::uuid7()->toString();
        DB::table('catalog_products')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'ref' => 'PRD-001'],
            [
                'id' => $productId,
                'code' => 'MP-FD-01',
                'sku' => 'DEMO-MP-FD-01',
                'name' => 'Machu Picchu Mágico Full Day en Tren Voyager',
                'internal_name' => 'Machu Picchu Full Day Regular',
                'product_type_id' => $type->id,
                'category_id' => $cat->id,
                'primary_destination_id' => $dest->id,
                'status' => 'ACTIVE',
                'short_description' => 'Descubre la maravilla del mundo en una inolvidable excursión de día completo desde Cusco.',
                'long_description' => 'Recorrido completo en tren panorámico, guiado profesional en la ciudadela y traslados incluidos.',
                'duration_value' => 1.0,
                'duration_unit' => 'días',
                'modality' => 'Grupal',
                'main_provider_id' => $provider->id,
                'minimum_passengers' => 1,
                'maximum_passengers' => 15,
                'minimum_age' => 4,
                'maximum_age' => 80,
                'booking_advance_min_days' => 2,
                'booking_advance_max_days' => 180,
                'is_bookable' => true,
                'is_quoteable' => true,
                'is_b2b' => true,
                'is_ecommerce' => true,
                'markup_pct' => 25.00,
                'availability_model' => 'POR_SALIDA',
                'availability_capacity' => 15,
                'slug' => 'machu-picchu-magico-full-day',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $prod = DB::table('catalog_products')->where('tenant_id', $tenant->id)->where('ref', 'PRD-001')->first();

        // 4. Políticas Comerciales (§3.7.5)
        DB::table('catalog_product_policies')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'product_id' => $prod->id],
            [
                'id' => Str::uuid7()->toString(),
                'cancellation' => 'Cancelación gratuita hasta 7 días antes de la salida. Menor a 7 días aplica 50% de penalidad.',
                'modification' => 'Cambios de fecha permitidos hasta 48 horas antes sujeto a disponibilidad de tren y boleto.',
                'no_show' => 'Aplica penalidad del 100% de la reserva.',
                'refund' => 'Reembolsos procesados en un plazo máximo de 15 días hábiles.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 5. Inclusiones (§3.7.4)
        $inclusions = [
            ['kind' => 'include', 'text' => 'Boleto de tren Voyager ida y retorno'],
            ['kind' => 'include', 'text' => 'Bus de subida y bajada a la ciudadela (Consettur)'],
            ['kind' => 'include', 'text' => 'Boleto de ingreso a la Llaqta de Machu Picchu'],
            ['kind' => 'include', 'text' => 'Guía profesional bilingüe (español/inglés)'],
            ['kind' => 'exclude', 'text' => 'Alimentación no especificada y bebidas'],
            ['kind' => 'exclude', 'text' => 'Propinas para el personal y gastos personales'],
        ];

        foreach ($inclusions as $idx => $inc) {
            DB::table('catalog_product_inclusions')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenant->id,
                'product_id' => $prod->id,
                'kind' => $inc['kind'],
                'text' => $inc['text'],
                'sort_order' => $idx + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6. Itinerario (§3.7.3)
        $itId = Str::uuid7()->toString();
        DB::table('catalog_itineraries')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'product_id' => $prod->id],
            ['id' => $itId, 'name' => 'Principal', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );

        $dayId = Str::uuid7()->toString();
        DB::table('catalog_itinerary_days')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'itinerary_id' => $itId, 'day_number' => 1],
            ['id' => $dayId, 'title' => 'Cusco - Ollantaytambo - Machu Picchu - Cusco', 'created_at' => now(), 'updated_at' => now()]
        );

        DB::table('catalog_itinerary_items')->insert([
            ['id' => Str::uuid7()->toString(), 'tenant_id' => $tenant->id, 'day_id' => $dayId, 'start_time' => '05:00:00', 'activity' => 'Recojo en hotel y traslado en van hacia estación de tren Ollantaytambo', 'item_type' => 'Traslado', 'created_at' => now(), 'updated_at' => now()],
            ['id' => Str::uuid7()->toString(), 'tenant_id' => $tenant->id, 'day_id' => $dayId, 'start_time' => '07:05:00', 'activity' => 'Embarque y viaje en tren turístico Voyager hacia Aguas Calientes', 'item_type' => 'Tren', 'created_at' => now(), 'updated_at' => now()],
            ['id' => Str::uuid7()->toString(), 'tenant_id' => $tenant->id, 'day_id' => $dayId, 'start_time' => '09:30:00', 'activity' => 'Visita guiada oficial por el Circuito Principal de la Ciudadela Inca', 'item_type' => 'Actividad', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}