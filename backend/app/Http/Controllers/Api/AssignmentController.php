<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssignmentController extends Controller
{
    /**
     * GET /api/v1/ops/resources
     * Lista guías, conductores y vehículos disponibles
     */
    public function getResources(): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $guides = DB::table('ops_guides')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get();
        $drivers = DB::table('ops_drivers')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get();
        $vehicles = DB::table('ops_vehicles')->where('tenant_id', $tenantId)->whereNull('deleted_at')->get();

        return response()->json([
            'guides' => $guides,
            'drivers' => $drivers,
            'vehicles' => $vehicles,
        ]);
    }

    /**
     * PUT /api/v1/ops/assignments/{id}/resources
     * Asignar recursos con validación PROC-033
     */
    public function assignResources(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $assig = DB::table('ops_assignments')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$assig) return response()->json(['message' => 'Asignación no encontrada.'], 404);

        $validated = $request->validate([
            'guide_id' => 'nullable|uuid|exists:ops_guides,id',
            'driver_id' => 'nullable|uuid|exists:ops_drivers,id',
            'vehicle_id' => 'nullable|uuid|exists:ops_vehicles,id',
            'ack_warnings' => 'nullable|boolean',
        ]);

        $warnings = [];

        // 1. Validar Guía (Cruces en la misma fecha)
        if (!empty($validated['guide_id'])) {
            $conflictGuide = DB::table('ops_assignments')
                ->where('tenant_id', $tenantId)
                ->where('guide_id', $validated['guide_id'])
                ->where('service_date', $assig->service_date)
                ->where('id', '!=', $assig->id)
                ->exists();

            if ($conflictGuide && !$request->boolean('ack_warnings')) {
                $warnings[] = "El guía ya tiene otro servicio programado el {$assig->service_date}. Verifique tiempos de retorno (OPS-BR-22).";
            }
        }

        // 2. Validar Conductor (Licencia vigente)
        if (!empty($validated['driver_id'])) {
            $driver = DB::table('ops_drivers')->where('id', $validated['driver_id'])->first();
            if ($driver && $driver->license_expires_on < $assig->service_date) {
                return response()->json([
                    'error' => 'OPS-BR-26',
                    'message' => "Bloqueo: La licencia del conductor {$driver->full_name} estará vencida a la fecha del servicio.",
                ], 422);
            }
        }

        // 3. Validar Vehículo (SOAT vigente)
        if (!empty($validated['vehicle_id'])) {
            $vehicle = DB::table('ops_vehicles')->where('id', $validated['vehicle_id'])->first();
            if ($vehicle && $vehicle->soat_expires_on < $assig->service_date) {
                return response()->json([
                    'error' => 'OPS-BR-27',
                    'message' => "Bloqueo: El SOAT del vehículo {$vehicle->plate} estará vencido a la fecha del servicio.",
                ], 422);
            }
        }

        if (count($warnings) > 0 && !$request->boolean('ack_warnings')) {
            return response()->json([
                'has_warnings' => true,
                'warnings' => $warnings,
                'message' => 'Se detectaron advertencias operativas. Marque la casilla de aceptación para continuar.',
            ], 422);
        }

        // Aplicar asignación
        DB::table('ops_assignments')->where('id', $assig->id)->update([
            'guide_id' => $validated['guide_id'] ?? $assig->guide_id,
            'driver_id' => $validated['driver_id'] ?? $assig->driver_id,
            'vehicle_id' => $validated['vehicle_id'] ?? $assig->vehicle_id,
            'status' => 'asignada',
            'warnings_ack' => json_encode($warnings),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Recursos asignados con éxito.', 'status' => 'asignada']);
    }

    /**
     * POST /api/v1/ops/assignments/{id}/confirm
     */
    public function confirm(string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        DB::table('ops_assignments')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'status' => 'confirmada',
                'confirmed_at' => now(),
                'confirmed_via' => 'web',
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Asignación confirmada por el recurso.', 'status' => 'confirmada']);
    }

    /**
     * POST /api/v1/ops/assignments/{id}/release
     */
    public function release(string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        DB::table('ops_assignments')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'guide_id' => null,
                'driver_id' => null,
                'vehicle_id' => null,
                'status' => 'pendiente',
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Recursos liberados. Servicio devuelto a estado pendiente.', 'status' => 'pendiente']);
    }
}