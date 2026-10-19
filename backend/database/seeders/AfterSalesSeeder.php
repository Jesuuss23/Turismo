<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AfterSalesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Tipos de Caso (§3.1)
        $caseTypes = [
            'reclamo' => 'Reclamo',
            'queja' => 'Queja',
            'sugerencia' => 'Sugerencia',
            'felicitacion' => 'Felicitación',
        ];

        $order = 1;
        foreach ($caseTypes as $code => $label) {
            DB::table('crm_catalog_items')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'kind' => 'case_type', 'code' => $code],
                [
                    'id' => Str::uuid7()->toString(),
                    'label' => $label,
                    'requires_detail' => false,
                    'sort_order' => $order++,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 2. Canales de Encuesta (§3.1)
        $surveyChannels = [
            'llamada' => 'Llamada',
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            'web' => 'Encuesta web',
            'portal' => 'Portal del cliente',
        ];

        $order = 1;
        foreach ($surveyChannels as $code => $label) {
            DB::table('crm_catalog_items')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'kind' => 'survey_channel', 'code' => $code],
                [
                    'id' => Str::uuid7()->toString(),
                    'label' => $label,
                    'requires_detail' => false,
                    'sort_order' => $order++,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 3. Correlativos atómicos ENC- y REC- (§3.7)
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'survey', 'series' => 'default'],
            [
                'id' => Str::uuid7()->toString(),
                'label' => 'Encuesta NPS',
                'prefix' => 'ENC-',
                'pad_width' => 4,
                'next_number' => 1,
                'year_reset' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'case', 'series' => 'default'],
            [
                'id' => Str::uuid7()->toString(),
                'label' => 'Caso Postventa',
                'prefix' => 'REC-',
                'pad_width' => 4,
                'next_number' => 1,
                'year_reset' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}