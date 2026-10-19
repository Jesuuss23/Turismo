<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetPostgresTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // 1. Resolver tenant desde el token autenticado (SEC-BR-02)
        $tenantId = null;
        if ($user && isset($user->tenant_id)) {
            $tenantId = $user->tenant_id;
        }

        // Si no hay tenant autenticado, fallar cerrado si la ruta lo exige
        if ($tenantId) {
            app()->instance('current_tenant_id', $tenantId);

            // Inyectar contexto en la sesión de PostgreSQL para RLS (§3.11)
            DB::statement("SELECT set_config('app.tenant_id', :tenant_id, true)", [
                'tenant_id' => $tenantId
            ]);
        }

        return $next($request);
    }
}