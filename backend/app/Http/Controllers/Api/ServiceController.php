<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\CatalogService;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    /**
     * GET /api/v1/catalog/services
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = CatalogService::where('tenant_id', $tenantId)->with('provider:id,name,ref,status');

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
                  ->orWhere('ref', 'ilike', $s);
            });
        }

        $items = $query->orderBy('name')->get();

        $now = today();
        $kpis = [
            'activos' => CatalogService::where('tenant_id', $tenantId)->where('status', 'activo')->count(),
            'por_vencer' => CatalogService::where('tenant_id', $tenantId)
                ->where('status', 'activo')
                ->whereBetween('valid_to', [$now, now()->addDays(90)])
                ->count(),
            'vencidos_inactivos' => CatalogService::where('tenant_id', $tenantId)
                ->where(function ($q) use ($now) {
                    $q->where('status', 'inactivo')->orWhere('valid_to', '<', $now);
                })
                ->count(),
        ];

        return response()->json([
            'data' => $items,
            'meta' => ['kpis' => $kpis],
        ]);
    }

    /**
     * POST /api/v1/catalog/services
     * Regla CAT-BR-37: Costo >= 0 y congruencia de vigencia
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'provider_id' => 'required|uuid|exists:catalog_providers,id',
            'name' => 'required|string|max:200',
            'type' => 'required|string|in:Traslado,Transporte,Tren,Entrada,Guía,Alimentación,Alojamiento,Actividad,Tour,Seguro,Otro',
            'unit' => 'required|string|in:pax,servicio,habitación,noche,vehículo,unidad',
            'cost' => 'required|numeric|min:0',
            'currency' => 'required|string|in:USD,PEN',
            'capacity' => 'nullable|integer|min:1',
            'valid_from' => 'required|date',
            'valid_to' => 'required|date|after_or_equal:valid_from',
        ]);

        $provider = Provider::where('tenant_id', $tenantId)->findOrFail($validated['provider_id']);

        // Regla CAT-BR-37: Si el proveedor no está activo, el servicio se guarda inactivo
        $initialStatus = ($provider->status === 'activo' && $validated['valid_to'] >= today()) ? 'activo' : 'inactivo';

        $ref = SequenceService::nextCode($tenantId, 'service');

        $service = CatalogService::create(array_merge($validated, [
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'ref' => $ref,
            'status' => $initialStatus,
        ]));

        return response()->json([
            'message' => 'Servicio registrado exitosamente.',
            'data' => $service,
        ], 201);
    }
}