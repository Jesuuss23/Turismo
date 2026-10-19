<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpportunityPipelineSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Etapas del pipeline (§3.6)
        $stages = [
            ['code' => 'calificacion', 'name' => 'Calificación', 'default_probability' => 20, 'sort_order' => 1, 'is_closed' => false],
            ['code' => 'descubrimiento', 'name' => 'Descubrimiento', 'default_probability' => 35, 'sort_order' => 2, 'is_closed' => false],
            ['code' => 'cotizacion', 'name' => 'Cotización', 'default_probability' => 55, 'sort_order' => 3, 'is_closed' => false],
            ['code' => 'negociacion', 'name' => 'Negociación', 'default_probability' => 70, 'sort_order' => 4, 'is_closed' => false],
            ['code' => 'ganada', 'name' => 'Ganada', 'default_probability' => 100, 'sort_order' => 5, 'is_closed' => true],
            ['code' => 'perdida', 'name' => 'Perdida', 'default_probability' => 0, 'sort_order' => 6, 'is_closed' => true],
        ];

        foreach ($stages as $stage) {
            DB::table('crm_pipeline_stages')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'code' => $stage['code']],
                array_merge($stage, [
                    'id' => Str::uuid7()->toString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // 2. Catálogo de motivos de pérdida (lost_reason §3.1)
        $lostReasons = [
            'precio' => 'Precio',
            'competencia' => 'Eligió a la competencia',
            'sin_presupuesto' => 'Sin presupuesto',
            'cambio_planes' => 'Cambio de planes del cliente',
            'sin_respuesta' => 'Sin respuesta del cliente',
            'sin_disponibilidad' => 'Producto o fecha sin disponibilidad',
            'otro' => 'Otro',
        ];

        $order = 1;
        foreach ($lostReasons as $code => $label) {
            DB::table('crm_catalog_items')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'kind' => 'lost_reason', 'code' => $code],
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

        // 3. Correlativo atómico OPP- (§3.7)
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'opportunity', 'series' => 'default'],
            [
                'id' => Str::uuid7()->toString(),
                'label' => 'Oportunidad',
                'prefix' => 'OPP-',
                'pad_width' => 4,
                'next_number' => 1,
                'year_reset' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}