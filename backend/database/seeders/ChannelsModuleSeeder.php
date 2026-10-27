<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChannelsModuleSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::table('tenants')->value('id');
        if (!$tenantId) return;

        $user = DB::table('iam_users')->where('tenant_id', $tenantId)->first();
        $product = DB::table('catalog_products')->where('tenant_id', $tenantId)->first();
        $client = DB::table('crm_clients')->where('tenant_id', $tenantId)->first();
        $reservation = DB::table('booking_reservations')->where('tenant_id', $tenantId)->first();

        // 1. Lista de Precios B2B
        $priceListId = (string) Str::uuid7();
        DB::table('channel_b2b_price_lists')->updateOrInsert(
            ['tenant_id' => $tenantId, 'name' => 'Mayorista 2026'],
            [
                'id' => $priceListId,
                'year' => 2026,
                'status' => 'activa',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 2. Agencia B2B Asociada
        $agencyId = (string) Str::uuid7();
        DB::table('channel_b2b_agencies')->updateOrInsert(
            ['tenant_id' => $tenantId, 'code' => 'AGB-01'],
            [
                'id' => $agencyId,
                'legal_name' => 'Viajes del Sol Mayoristas S.A.C.',
                'ruc' => '20501982731',
                'contact_name' => 'Valeria Mendoza',
                'booking_email' => 'reservas@viajesdelsol.com',
                'crm_client_id' => $client?->id,
                'price_list_id' => $priceListId,
                'commission_pct' => 12.00,
                'credit_currency' => 'USD',
                'credit_limit' => 5000.00,
                'credit_used' => 1200.00,
                'valid_from' => now()->startOfYear()->toDateString(),
                'valid_to' => now()->addYear()->toDateString(),
                'status' => 'activa',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 3. Tarifa B2B Asignada al Producto Demo
        if ($product) {
            DB::table('catalog_product_b2b_rates')->updateOrInsert(
                ['tenant_id' => $tenantId, 'product_id' => $product->id, 'agency_id' => $agencyId],
                [
                    'id' => (string) Str::uuid7(),
                    'price_list_id' => $priceListId,
                    'net_price' => 280.00,
                    'currency' => 'USD',
                    'commission_pct' => 12.00,
                    'credit_allowed' => true,
                    'conditions' => 'Tarifa neta no endosable con 15 días de crédito',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 4. Contenidos CMS (PROC-055)
        $cmsContents = [
            [
                'code' => 'CMS-01',
                'title' => 'Términos y Condiciones del Servicio Turístico',
                'type' => 'Página legal',
                'channel' => 'Web',
                'slug' => 'terminos-y-condiciones',
                'body' => '## Políticas Generales\nTodo servicio contratado se rige bajo la normativa de MINCETUR y SUNAT.',
                'seo_title' => 'Términos y Condiciones Oficiales | Qhapaq',
                'status' => 'publicado',
                'published_at' => now(),
            ],
            [
                'code' => 'CMS-02',
                'title' => 'Banner Promocional: Temporada Seca en Cusco',
                'type' => 'Banner',
                'channel' => 'Web / E-commerce',
                'slug' => 'banner-cusco-2026',
                'placement' => 'shop_top',
                'body' => 'Aprovecha hasta un 15% de descuento en salidas confirmadas a Valle Sagrado y Machu Picchu.',
                'seo_title' => 'Ofertas Cusco 2026',
                'status' => 'publicado',
                'published_at' => now(),
            ],
        ];

        foreach ($cmsContents as $c) {
            DB::table('cms_contents')->updateOrInsert(
                ['tenant_id' => $tenantId, 'code' => $c['code']],
                [
                    'id' => (string) Str::uuid7(),
                    'title' => $c['title'],
                    'type' => $c['type'],
                    'channel' => $c['channel'],
                    'slug' => $c['slug'],
                    'placement' => $c['placement'] ?? null,
                    'body' => $c['body'],
                    'seo_title' => $c['seo_title'],
                    'status' => $c['status'],
                    'published_at' => $c['published_at'],
                    'author_id' => $user?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 5. Pedido Web E-commerce Demo
        if ($product) {
            $orderId = (string) Str::uuid7();
            DB::table('channel_ecom_orders')->updateOrInsert(
                ['tenant_id' => $tenantId, 'code' => 'WEB-10434'],
                [
                    'id' => $orderId,
                    'ordered_at' => now()->subHours(2),
                    'customer_name' => 'Michael Scott',
                    'customer_email' => 'mscott@dundermifflin.com',
                    'customer_phone' => '+1 555-0192',
                    'customer_country' => 'Estados Unidos',
                    'product_id' => $product->id,
                    'service_date' => now()->addDays(20)->toDateString(),
                    'pax_adults' => 2,
                    'pax_children' => 0,
                    'pax_total' => 2,
                    'total' => 640.00,
                    'currency' => 'USD',
                    'status' => 'confirmado',
                    'reservation_id' => $reservation?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command->info('¡Datos base del Módulo 08 sembrados con éxito!');
    }
}
