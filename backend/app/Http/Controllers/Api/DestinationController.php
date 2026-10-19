<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DestinationController extends Controller
{
    /**
     * GET /api/v1/catalog/destinations
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        if ($request->boolean('tree')) {
            $roots = Destination::where('tenant_id', $tenantId)
                ->whereNull('parent_id')
                ->with('children.children.children')
                ->orderBy('name')
                ->get();

            return response()->json(['data' => $roots]);
        }

        $items = Destination::where('tenant_id', $tenantId)
            ->with('parent:id,name,type')
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $items]);
    }

    /**
     * POST /api/v1/catalog/destinations
     * Regla CAT-BR-39: Jerarquía válida, sin ciclos
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $levelsMap = [
            'País' => 0,
            'Región' => 1,
            'Departamento' => 2,
            'Provincia' => 3,
            'Ciudad' => 4,
            'Zona' => 4,
            'Lugar' => 5,
            'Atracción' => 6,
            'Ruta' => 6,
        ];

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|string|in:País,Región,Departamento,Provincia,Ciudad,Zona,Lugar,Atracción,Ruta',
            'parent_id' => 'nullable|uuid|exists:catalog_destinations,id',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'description' => 'nullable|string',
        ]);

        $level = $levelsMap[$validated['type']];

        // Validar coherencia jerárquica
        if ($level === 0 && !empty($validated['parent_id'])) {
            return response()->json(['error' => 'VALIDATION_FAILED', 'message' => 'Un destino tipo País debe ser raíz (sin padre).'], 422);
        }

        $path = '';
        if (!empty($validated['parent_id'])) {
            $parent = Destination::where('tenant_id', $tenantId)->findOrFail($validated['parent_id']);
            if ($parent->level >= $level) {
                return response()->json([
                    'error' => 'VALIDATION_FAILED',
                    'message' => "Jerarquía inválida: el nivel de {$validated['type']} ({$level}) debe ser mayor al del padre {$parent->type} ({$parent->level}).",
                ], 422);
            }
            $path = $parent->path . '/';
        }

        $id = Str::uuid7()->toString();
        $ref = SequenceService::nextCode($tenantId, 'destination');

        $destination = Destination::create([
            'id' => $id,
            'tenant_id' => $tenantId,
            'ref' => $ref,
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'level' => $level,
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,
            'description' => $validated['description'] ?? null,
            'path' => $path . $id,
        ]);

        return response()->json([
            'message' => 'Destino registrado exitosamente.',
            'data' => $destination,
        ], 201);
    }
}