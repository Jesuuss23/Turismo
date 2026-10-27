<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SupplierExtranetSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = DB::table('tenants')->first();
        if (!$tenant) return;
        $tenantId = $tenant->id;

        // 1. Crear o asegurar Proveedor Demo (Hotel Cusco Plaza)
        $providerId = Str::uuid7()->toString();
        $existingProvider = DB::table('catalog_providers')->where('tenant_id', $tenantId)->first();

        if ($existingProvider) {
            $providerId = $existingProvider->id;
        } else {
            DB::table('catalog_providers')->insert([
                'id' => $providerId,
                'tenant_id' => $tenantId,
                'code' => 'PRV-001',
                'name' => 'Hotel Cusco Plaza San Blas',
                'legal_name' => 'Inversiones Hoteleras del Sur S.A.C.',
                'tax_id' => '20491823901',
                'type' => 'Hotel',
                'status' => 'activo',
                'currency' => 'USD',
                'contact_name' => 'Patricia Valdivia',
                'contact_email' => 'reservas@cuscoplaza.com',
                'contact_phone' => '+51 984 771 223',
                'payment_condition' => '15 días post-servicio',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Crear o actualizar Usuario U17 (Proveedor)
        $userEmail = 'proveedor@cuscoplaza.com';
        $user = DB::table('iam_users')->where('tenant_id', $tenantId)->where('email', $userEmail)->first();

        if (!$user) {
            DB::table('iam_users')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'code' => 'U17',
                'name' => 'Recepción Hotel Cusco Plaza',
                'email' => $userEmail,
                'password' => Hash::make('proveedor123'),
                'provider_id' => $providerId,
                'status' => 'activo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('iam_users')->where('id', $user->id)->update([
                'provider_id' => $providerId,
                'password' => Hash::make('proveedor123'),
                'status' => 'activo',
                'updated_at' => now(),
            ]);
        }
    }
}