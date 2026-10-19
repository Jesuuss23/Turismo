<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        // 1. Catálogo de Segmentos (§3.1)
        $segments = [
            'retail' => 'Retail',
            'corporativo' => 'Corporativo',
            'vip' => 'VIP',
            'grupos' => 'Grupos',
            'b2b' => 'B2B',
        ];

        $order = 1;
        foreach ($segments as $code => $label) {
            DB::table('crm_catalog_items')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'kind' => 'client_segment', 'code' => $code],
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

        // 2. Correlativo atómico CLI- (§3.7)
        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'client', 'series' => 'default'],
            [
                'id' => Str::uuid7()->toString(),
                'label' => 'Cliente',
                'prefix' => 'CLI-',
                'pad_width' => 4,
                'next_number' => 1,
                'year_reset' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}