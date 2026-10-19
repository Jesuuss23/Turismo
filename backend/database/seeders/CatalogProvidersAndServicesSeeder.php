<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogProvidersAndServicesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Secuencias correlativas PRV- y SRV- (§3)
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'provider', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Proveedor', 'prefix' => 'PRV-', 'pad_width' => 3, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'service', 'series' => 'default'],
            ['id' => Str::uuid7()->toString(), 'label' => 'Servicio', 'prefix' => 'SRV-', 'pad_width' => 3, 'next_number' => 1, 'year_reset' => false, 'created_at' => now(), 'updated_at' => now()]
        );

        // 2. Proveedores Base (Hotelería, Ferrocarril y Operación de Transporte)
        $providers = [
            [
                'ref' => 'PRV-001',
                'name' => 'Inca Rail SAC',
                'type' => 'Tren',
                'ruc' => '20514867901',
                'city' => 'Cusco',
                'stars' => null,
                'contact_name' => 'Carlos Mendoza',
                'phone' => '+51 84 581860',
                'email' => 'reservas@incarail.com',
                'contract_start' => now()->startOfYear()->toDateString(),
                'contract_end' => now()->addYear()->endOfYear()->toDateString(),
                'currency' => 'USD',
                'payment_terms' => 'Crédito 15 días',
                'status' => 'activo',
                'rating' => 4.8,
            ],
            [
                'ref' => 'PRV-002',
                'name' => 'Hotel Casa Andina Standard Cusco',
                'type' => 'Hotel',
                'ruc' => '20428399182',
                'city' => 'Cusco',
                'stars' => 3,
                'contact_name' => 'Lucía Paredes',
                'phone' => '+51 84 252633',
                'email' => 'corporativo@casa-andina.com',
                'contract_start' => now()->startOfYear()->toDateString(),
                'contract_end' => now()->addYear()->endOfYear()->toDateString(),
                'currency' => 'USD',
                'payment_terms' => 'Prepago 48h antes',
                'status' => 'activo',
                'rating' => 4.6,
            ],
            [
                'ref' => 'PRV-003',
                'name' => 'Transportes Turísticos del Sur EIRL',
                'type' => 'Transporte',
                'ruc' => '20601234567',
                'city' => 'Cusco',
                'stars' => null,
                'contact_name' => 'Hernán Quispe',
                'phone' => '+51 984 112233',
                'email' => 'operaciones@turismosur.pe',
                'contract_start' => now()->startOfYear()->toDateString(),
                'contract_end' => now()->addYear()->endOfYear()->toDateString(),
                'currency' => 'USD',
                'payment_terms' => 'Semanal contra factura',
                'status' => 'activo',
                'rating' => 4.5,
            ],
        ];

        foreach ($providers as $prov) {
            $provId = Str::uuid7()->toString();
            DB::table('catalog_providers')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'ref' => $prov['ref']],
                array_merge($prov, [
                    'id' => $provId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // 3. Servicios Base de los proveedores
        $prv1 = DB::table('catalog_providers')->where('tenant_id', $tenant->id)->where('ref', 'PRV-001')->first();
        $prv2 = DB::table('catalog_providers')->where('tenant_id', $tenant->id)->where('ref', 'PRV-002')->first();
        $prv3 = DB::table('catalog_providers')->where('tenant_id', $tenant->id)->where('ref', 'PRV-003')->first();

        $services = [
            [
                'ref' => 'SRV-001',
                'provider_id' => $prv1->id,
                'name' => 'Ticket Tren Voyager: Ollantaytambo - Machu Picchu Pueblo (Ida y Vuelta)',
                'type' => 'Tren',
                'unit' => 'pax',
                'cost' => 110.00,
                'currency' => 'USD',
                'capacity' => 42,
                'valid_from' => now()->startOfYear()->toDateString(),
                'valid_to' => now()->addYear()->endOfYear()->toDateString(),
                'status' => 'activo',
            ],
            [
                'ref' => 'SRV-002',
                'provider_id' => $prv2->id,
                'name' => 'Habitación Doble Estándar con Desayuno Buffet',
                'type' => 'Alojamiento',
                'unit' => 'noche',
                'cost' => 75.00,
                'currency' => 'USD',
                'capacity' => 20,
                'valid_from' => now()->startOfYear()->toDateString(),
                'valid_to' => now()->addYear()->endOfYear()->toDateString(),
                'status' => 'activo',
            ],
            [
                'ref' => 'SRV-003',
                'provider_id' => $prv3->id,
                'name' => 'Traslado Privado Van Cusco - Estación Ollantaytambo',
                'type' => 'Traslado',
                'unit' => 'servicio',
                'cost' => 45.00,
                'currency' => 'USD',
                'capacity' => 12,
                'valid_from' => now()->startOfYear()->toDateString(),
                'valid_to' => now()->addYear()->endOfYear()->toDateString(),
                'status' => 'activo',
            ],
        ];

        foreach ($services as $srv) {
            DB::table('catalog_services')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'ref' => $srv['ref']],
                array_merge($srv, [
                    'id' => Str::uuid7()->toString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}