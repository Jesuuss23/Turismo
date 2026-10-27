<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsResourceSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = DB::table('tenants')->first();
        if (!$tenant) return;
        $tenantId = $tenant->id;

        // 1. Guías
        $guides = [
            ['code' => 'G01', 'full_name' => 'Carlos Mendoza Quispe', 'languages' => json_encode(['ES', 'EN', 'QU']), 'license_number' => 'DIR-CUS-10492', 'phone' => '+51 984 112 233'],
            ['code' => 'G02', 'full_name' => 'Lucía Huamán Flores', 'languages' => json_encode(['ES', 'EN', 'FR']), 'license_number' => 'DIR-CUS-10581', 'phone' => '+51 984 223 344'],
            ['code' => 'G03', 'full_name' => 'Hans Schmidt Vargas', 'languages' => json_encode(['ES', 'EN', 'DE']), 'license_number' => 'DIR-CUS-10604', 'phone' => '+51 984 334 455'],
        ];

        foreach ($guides as $g) {
            DB::table('ops_guides')->updateOrInsert(
                ['tenant_id' => $tenantId, 'code' => $g['code']],
                array_merge($g, ['id' => Str::uuid7()->toString(), 'status' => 'disponible', 'rating' => 4.9, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        // 2. Conductores
        $drivers = [
            ['code' => 'D01', 'full_name' => 'Mario Ccama Condori', 'license_number' => 'Q4829104', 'license_class' => 'A-IIIb', 'license_expires_on' => '2028-12-31', 'phone' => '+51 984 556 677'],
            ['code' => 'D02', 'full_name' => 'Rómulo Abarca Peña', 'license_number' => 'Q1948201', 'license_class' => 'A-IIb', 'license_expires_on' => '2027-08-15', 'phone' => '+51 984 667 788'],
        ];

        foreach ($drivers as $d) {
            DB::table('ops_drivers')->updateOrInsert(
                ['tenant_id' => $tenantId, 'code' => $d['code']],
                array_merge($d, ['id' => Str::uuid7()->toString(), 'status' => 'disponible', 'created_at' => now(), 'updated_at' => now()])
            );
        }

        // 3. Vehículos
        $vehicles = [
            ['plate' => 'X3B-960', 'vehicle_type' => 'Mercedes Sprinter 19 pax', 'capacity' => 19, 'soat_expires_on' => '2027-05-30'],
            ['plate' => 'X4C-821', 'vehicle_type' => 'Hyundai H1 10 pax', 'capacity' => 10, 'soat_expires_on' => '2027-09-12'],
            ['plate' => 'X1A-102', 'vehicle_type' => 'Toyota HiAce 14 pax', 'capacity' => 14, 'soat_expires_on' => '2027-11-20'],
        ];

        foreach ($vehicles as $v) {
            DB::table('ops_vehicles')->updateOrInsert(
                ['tenant_id' => $tenantId, 'plate' => $v['plate']],
                array_merge($v, ['id' => Str::uuid7()->toString(), 'status' => 'disponible', 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}