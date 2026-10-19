<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'tenant_slug' => 'nullable|string',
        ]);

        // 1. Resolver Tenant (por slug recibido o el de demo por defecto)
        $tenantSlug = $validated['tenant_slug'] ?? 'demo-travel';
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'activo')->first();

        if (!$tenant) {
            return response()->json([
                'error' => 'NOT_FOUND',
                'message' => 'El tenant indicado no existe o se encuentra inactivo.',
            ], 404);
        }

        // 2. Buscar usuario en el tenant respectivo
        $user = User::where('tenant_id', $tenant->id)
            ->where('email', $validated['email'])
            ->where('status', 'activo')
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Credenciales inválidas.',
            ], 401);
        }

        // 3. Crear token Sanctum que vincula el tenant_id
        $token = $user->createToken('auth-token', ['*'])->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'code' => $user->code,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
            ],
        ]);
    }

    /**
     * GET /api/v1/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = app('current_tenant_id');

        return response()->json([
            'user' => $user,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * GET /api/v1/me/access
     * Especificación §6 y §7.4/7.5
     */
    public function access(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Mapeo inicial de rutas base según rol (§7.5)
        $isTenantAdmin = ($user->code === 'U01');
        
        $nav = [
            ['label' => 'Dashboard', 'path' => '/dashboard', 'icon' => 'home'],
            ['label' => 'CRM · Leads', 'path' => '/crm/leads', 'icon' => 'users'],
            ['label' => 'Catálogo', 'path' => '/catalogo/productos', 'icon' => 'book'],
            ['label' => 'Cotizaciones', 'path' => '/cotizaciones', 'icon' => 'file-text'],
            ['label' => 'Reservas', 'path' => '/reservas', 'icon' => 'calendar'],
            ['label' => 'Operaciones', 'path' => '/operaciones', 'icon' => 'compass'],
            ['label' => 'Finanzas', 'path' => '/finanzas/cobros', 'icon' => 'credit-card'],
        ];

        if ($isTenantAdmin) {
            $nav[] = ['label' => 'Administración', 'path' => '/admin/usuarios', 'icon' => 'settings'];
        }

        return response()->json([
            'experience' => 'backoffice',
            'home' => '/dashboard',
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'code' => $user->code,
                'role' => $isTenantAdmin ? 'tenant_admin' : 'sales',
            ],
            'nav' => $nav,
        ]);
    }
}
