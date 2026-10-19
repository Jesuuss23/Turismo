<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivitySequenceSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-travel')->first();
        if (!$tenant) return;

        DB::table('sys_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'doc_type' => 'activity', 'series' => 'default'],
            [
                'id' => Str::uuid7()->toString(),
                'label' => 'Actividad',
                'prefix' => 'ACT-',
                'pad_width' => 4,
                'next_number' => 1,
                'year_reset' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}