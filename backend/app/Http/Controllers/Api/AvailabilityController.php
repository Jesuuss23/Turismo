<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\AvailabilityHold;
use App\Models\Departure;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AvailabilityController extends Controller
{
    /**
     * GET /api/v1/catalog/departures
     */
    public function getDepartures(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = Departure::where('tenant_id', $tenantId)
            ->with(['product:id,name,ref,status', 'provider:id,name,ref']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date')) {
            $query->where('departure_date', '>=', $request->from_date);
        }

        $items = $query->orderBy('departure_date', 'asc')->orderBy('departure_time', 'asc')->get();

        // Calcular cupos disponibles dinámicos (§3.9)
        $departures = $items->map(function ($dep) {
            $activeHolds = AvailabilityHold::where('departure_id', $dep->id)
                ->where('status', 'hold')
                ->where('expires_at', '>', now())
                ->sum('quantity');

            $available = in_array($dep->status, ['cancelada', 'cerrada']) 
                ? 0 
                : max(0, $dep->capacity - $dep->sold - $activeHolds);

            $dep->active_holds = (int) $activeHolds;
            $dep->available_seats = (int) $available;
            return $dep;
        });

        $kpis = [
            'total_salidas' => $departures->count(),
            'abiertas' => $departures->where('status', 'abierta')->count(),
            'completas' => $departures->where('status', 'completa')->count(),
            'total_cupos_disponibles' => $departures->sum('available_seats'),
        ];

        return response()->json([
            'data' => $departures,
            'meta' => ['kpis' => $kpis],
        ]);
    }

    /**
     * POST /api/v1/catalog/departures
     * Regla CAT-BR-20: Salida con capacidad 1 a 60
     */
    public function storeDeparture(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'product_id' => 'required|uuid|exists:catalog_products,id',
            'departure_date' => 'required|date|after_or_equal:today',
            'departure_time' => 'required',
            'capacity' => 'required|integer|between:1,60',
            'provider_id' => 'nullable|uuid|exists:catalog_providers,id',
            'meeting_point' => 'nullable|string|max:160',
            'return_point' => 'nullable|string|max:160',
            'notes' => 'nullable|string',
        ]);

        $ref = SequenceService::nextCode($tenantId, 'departure');

        $departure = Departure::create(array_merge($validated, [
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'ref' => $ref,
            'sold' => 0,
            'status' => 'abierta',
        ]));

        return response()->json([
            'message' => 'Salida programada exitosamente.',
            'data' => $departure,
        ], 201);
    }

    /**
     * GET /api/v1/catalog/holds
     */
    public function getHolds(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $holds = AvailabilityHold::where('tenant_id', $tenantId)
            ->with(['departure:id,ref,departure_date,departure_time,product_id'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $holds]);
    }

    /**
     * POST /api/v1/catalog/holds
     * Regla CAT-BR-21 / PRD-BR-012: Lock atómico de cupos
     */
    public function storeHold(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'departure_id' => 'required|uuid|exists:catalog_product_departures,id',
            'quantity' => 'required|integer|min:1',
            'minutes' => 'nullable|integer|min:5|max:1440',
            'reference' => 'required|string|max:60',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($tenantId, $validated) {
            $departure = Departure::where('tenant_id', $tenantId)
                ->where('id', $validated['departure_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($departure->status !== 'abierta') {
                return response()->json(['error' => 'VALIDATION_FAILED', 'message' => 'La salida no se encuentra abierta.'], 422);
            }

            // Calcular cupos disponibles
            $activeHolds = AvailabilityHold::where('departure_id', $departure->id)
                ->where('status', 'hold')
                ->where('expires_at', '>', now())
                ->sum('quantity');

            $available = max(0, $departure->capacity - $departure->sold - $activeHolds);

            if ($validated['quantity'] > $available) {
                return response()->json([
                    'error' => 'CONFLICT',
                    'message' => "PRD-BR-012: Cupos insuficientes. Solicitados: {$validated['quantity']}, disponibles reales: {$available}.",
                ], 409);
            }

            $ref = SequenceService::nextCode($tenantId, 'hold');
            $minutes = $validated['minutes'] ?? 30;

            $hold = AvailabilityHold::create([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'ref' => $ref,
                'departure_id' => $departure->id,
                'quantity' => $validated['quantity'],
                'reference' => $validated['reference'],
                'status' => 'hold',
                'expires_at' => now()->addMinutes($minutes),
                'notes' => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'message' => 'Hold de disponibilidad creado exitosamente.',
                'data' => $hold,
            ], 201);
        });
    }

    /**
     * POST /api/v1/catalog/holds/{id}/confirm
     * Regla CAT-BR-22: Confirmación de hold
     */
    public function confirmHold(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        return DB::transaction(function () use ($tenantId, $id) {
            $hold = AvailabilityHold::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();

            if ($hold->status !== 'hold') {
                return response()->json(['error' => 'VALIDATION_FAILED', 'message' => 'El hold ya no está en estado activo.'], 422);
            }

            if ($hold->expires_at < now()) {
                $hold->update(['status' => 'expirada']);
                return response()->json(['error' => 'VALIDATION_FAILED', 'message' => 'El hold ha expirado.'], 422);
            }

            $departure = Departure::where('id', $hold->departure_id)->lockForUpdate()->firstOrFail();

            $newSold = $departure->sold + $hold->quantity;
            $newStatus = ($newSold >= $departure->capacity) ? 'completa' : 'abierta';

            $departure->update([
                'sold' => $newSold,
                'status' => $newStatus,
            ]);

            $hold->update(['status' => 'confirmada']);

            return response()->json([
                'message' => 'Cupos confirmados exitosamente.',
                'hold' => $hold,
                'departure' => $departure,
            ]);
        });
    }

    /**
     * POST /api/v1/catalog/holds/{id}/release
     * Regla CAT-BR-24: Liberar hold con motivo
     */
    public function releaseHold(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'reason' => 'required|string|min:4',
        ]);

        return DB::transaction(function () use ($tenantId, $id, $validated) {
            $hold = AvailabilityHold::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();

            if ($hold->status === 'confirmada') {
                // Si ya estaba confirmada, devolver a sold
                $departure = Departure::where('id', $hold->departure_id)->lockForUpdate()->first();
                if ($departure) {
                    $newSold = max(0, $departure->sold - $hold->quantity);
                    $departure->update([
                        'sold' => $newSold,
                        'status' => 'abierta',
                    ]);
                }
            }

            $hold->update([
                'status' => 'liberada',
                'released_reason' => $validated['reason'],
            ]);

            return response()->json([
                'message' => 'Cupos liberados correctamente.',
                'data' => $hold,
            ]);
        });
    }
}
