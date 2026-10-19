<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        $catalogs = [
            'lead_source' => [
                'formulario_web' => 'Formulario web',
                'whatsapp' => 'WhatsApp',
                'facebook_ads' => 'Facebook Ads',
                'instagram' => 'Instagram',
                'referido' => 'Referido',
                'feria_turismo' => 'Feria Turismo',
                'llamada_entrante' => 'Llamada entrante',
                'b2b' => 'B2B',
                'email' => 'Email',
                'oficina' => 'Visita en oficina'
            ],
            'lead_channel' => [
                'web' => 'Web',
                'whatsapp' => 'WhatsApp',
                'facebook' => 'Facebook',
                'instagram' => 'Instagram',
                'telefono' => 'Teléfono',
                'presencial' => 'Presencial',
                'email' => 'Email'
            ],
            'lead_interest' => [
                'paquete' => 'Paquete',
                'tour' => 'Tour',
                'excursion' => 'Excursión',
                'corporativo' => 'Viaje corporativo',
                'promocion' => 'Viaje de promoción',
                'luna_miel' => 'Luna de miel',
                'alojamiento' => 'Alojamiento',
                'alianza' => 'Alianza'
            ],
            'discard_reason' => [
                'no_potencial' => 'No es cliente potencial (proveedor / alianza)',
                'contacto_invalido' => 'Datos de contacto inválidos',
                'sin_interes' => 'Sin interés real',
                'fuera_cobertura' => 'Fuera de cobertura / destino no ofrecido',
                'spam' => 'Spam o prueba',
                'otro' => 'Otro'
            ]
        ];

        foreach ($catalogs as $kind => $items) {
            $order = 1;
            foreach ($items as $code => $label) {
                DB::table('crm_catalog_items')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'kind' => $kind, 'code' => $code],
                    [
                        'id' => Str::uuid7()->toString(),
                        'label' => $label,
                        'requires_detail' => ($code === 'otro'),
                        'sort_order' => $order++,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // Secuencia de correlativos atómicos para Leads
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'lead', 'series' => 'default'],
            [
                'id' => Str::uuid7()->toString(),
                'label' => 'Lead',
                'prefix' => 'LEAD-',
                'pad_width' => 4,
                'next_number' => 1,
                'year_reset' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}