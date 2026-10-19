<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Tenant Demo
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'demo-travel'],
            [
                'id' => Str::uuid7()->toString(),
                'name' => 'Qhapaq Travel Demo',
                'ruc' => '20601234567',
                'plan' => 'enterprise',
                'status' => 'activo',
                'timezone' => 'America/Lima',
                'currency' => 'USD',
                'fx_rate' => 3.75,
                'tax_pct' => 18.00,
            ]
        );

        // 2. Administrador del Tenant (U01)
        User::firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'admin@agencia.com'],
            [
                'id' => Str::uuid7()->toString(),
                'code' => 'U01',
                'name' => 'Administrador Demo',
                'password' => Hash::make('password123'),
                'status' => 'activo',
                'locale' => 'es',
            ]
        );

        // 3. Asesor de Ventas (U03)
        User::firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => 'ventas@agencia.com'],
            [
                'id' => Str::uuid7()->toString(),
                'code' => 'U03',
                'name' => 'Asesor de Ventas Demo',
                'password' => Hash::make('password123'),
                'status' => 'activo',
                'locale' => 'es',
            ]
        );
    }
}