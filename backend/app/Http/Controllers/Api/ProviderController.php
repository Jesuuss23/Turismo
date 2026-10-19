<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProviderController extends Controller
{
    /**
     * GET /api/v1/catalog/providers
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = Provider::where('tenant_id', $tenantId)->withCount('services');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ilike', $s)
                  ->orWhere('ref', 'ilike', $s)
                  ->orWhere('ruc', 'ilike', $s);
            });
        }

        $items = $query->orderBy('name')->get();

        // KPIs §2
        $now = today();
        $kpis = [
            'activos' => Provider::where('tenant_id', $tenantId)->where('status', 'activo')->count(),
            'por_vencer' => Provider::where('tenant_id', $tenantId)
                ->where('status', 'activo')
                ->whereBetween('contract_end', [$now, now()->addDays(95)])
                ->count(),
            'vencidos_inactivos' => Provider::where('tenant_id', $tenantId)
                ->where(function ($q) use ($now) {
                    $q->where('status', 'inactivo')->orWhere('contract_end', '<', $now);
                })
                ->count(),
        ];

        return response()->json([
            'data' => $items,
            'meta' => ['kpis' => $kpis],
        ]);
    }

    /**
     * POST /api/v1/catalog/providers
     * Regla CAT-BR-38: Validación RUC peruano
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'name' => 'required|string|max:160',
            'type' => 'required|string|in:Hotel,Transporte,Tren,Guía,Conductor,Restaurante,Operador,Actividad,Entrada,Otros',
            'ruc' => ['required', 'string', 'size:11', 'regex:/^(10|15|17|20)\d{9}$/'],
            'city' => 'nullable|string|max:80',
            'stars' => 'nullable|integer|between:0,5',
            'contact_name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:160',
            'contract_start' => 'required|date',
            'contract_end' => 'required|date|after_or_equal:contract_start',
            'currency' => 'required|string|in:USD,PEN',
            'payment_terms' => 'nullable|string|max:120',
        ]);

        $ref = SequenceService::nextCode($tenantId, 'provider');

        // Nace inactivo según PROC-012
        $provider = Provider::create(array_merge($validated, [
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'ref' => $ref,
            'status' => 'inactivo',
            'rating' => 0.0,
        ]));

        return response()->json([
            'message' => 'Proveedor registrado exitosamente.',
            'data' => $provider,
        ], 201);
    }

    /**
     * POST /api/v1/catalog/providers/{id}/activate
     */
    public function activate(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $provider = Provider::where('tenant_id', $tenantId)->findOrFail($id);

        if ($provider->contract_end < today()) {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'No se puede activar un proveedor cuyo contrato está vencido.',
            ], 422);
        }

        $provider->update(['status' => 'activo']);

        return response()->json([
            'message' => 'Proveedor activado exitosamente.',
            'data' => $provider,
        ]);
    }
}