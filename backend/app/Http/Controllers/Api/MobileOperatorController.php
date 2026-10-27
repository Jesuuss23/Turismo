<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileOperatorController extends Controller
{
    /**
     * GET /api/v1/mobile/operator/bootstrap
     * Ventana de asignaciones [-2d, +14d], datos de campo y compañeros (PROC-056)
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $fromDate = now()->subDays(2)->toDateString();
        $toDate = now()->addDays(14)->toDateString();

        $guide = DB::table('ops_guides')->where('tenant_id', $tenantId)->where('user_id', $user->id)->first();
        $driver = DB::table('ops_drivers')->where('tenant_id', $tenantId)->where('user_id', $user->id)->first();

        $query = DB::table('ops_assignments')
            ->join('ops_orders', 'ops_assignments.order_id', '=', 'ops_orders.id')
            ->join('booking_reservations', 'ops_orders.reservation_id', '=', 'booking_reservations.id')
            ->leftJoin('ops_guides', 'ops_assignments.guide_id', '=', 'ops_guides.id')
            ->leftJoin('ops_drivers', 'ops_assignments.driver_id', '=', 'ops_drivers.id')
            ->leftJoin('ops_vehicles', 'ops_assignments.vehicle_id', '=', 'ops_vehicles.id')
            ->where('ops_assignments.tenant_id', $tenantId)
            ->whereBetween('ops_assignments.service_date', [$fromDate, $toDate]);

        if ($guide) {
            $query->where('ops_assignments.guide_id', $guide->id);
        } elseif ($driver) {
            $query->where('ops_assignments.driver_id', $driver->id);
        }

        $assignments = $query->select(
            'ops_assignments.*',
            'ops_orders.code as order_code',
            'booking_reservations.id as reservation_id',
            'booking_reservations.notes as reservation_notes',
            'ops_guides.full_name as guide_name',
            'ops_drivers.full_name as driver_name',
            'ops_vehicles.plate as vehicle_plate',
            'ops_vehicles.vehicle_type'
        )->get();

        // Cargar pasajeros vinculados
        $resIds = $assignments->pluck('reservation_id')->unique();
        $passengers = DB::table('booking_passengers')
            ->whereIn('reservation_id', $resIds)
            ->get();

        return response()->json([
            'resource' => $guide ?? $driver ?? ['name' => $user->name, 'type' => 'operador'],
            'assignments' => $assignments,
            'passengers' => $passengers,
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/v1/mobile/operator/actions
     * Procesamiento idempotente de acciones del outbox (PROC-058)
     */
    public function handleAction(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'client_action_id' => 'required|uuid',
            'action_type' => 'required|string|in:confirm,start,checkin,checkin_all,finish,incident',
            'assignment_id' => 'required|uuid|exists:ops_assignments,id',
            'occurred_at' => 'required|date',
            'offline' => 'nullable|boolean',
            'payload' => 'nullable|array',
        ]);

        $actionId = $validated['client_action_id'];

        // MOB-BR-01: Idempotencia por client_action_id
        $existing = DB::table('ops_sync_actions')->where('tenant_id', $tenantId)->where('id', $actionId)->first();
        if ($existing) {
            return response()->json([
                'result' => 'duplicate',
                'response' => json_decode($existing->response, true),
            ]);
        }

        return DB::transaction(function () use ($tenantId, $user, $validated, $actionId) {
            $assig = DB::table('ops_assignments')->where('id', $validated['assignment_id'])->first();
            $actionType = $validated['action_type'];
            $result = 'applied';

            switch ($actionType) {
                case 'confirm':
                    DB::table('ops_assignments')->where('id', $assig->id)->update([
                        'status' => 'confirmada',
                        'confirmed_at' => $validated['occurred_at'],
                        'confirmed_via' => 'app',
                        'updated_at' => now(),
                    ]);
                    break;

                case 'start':
                    DB::table('ops_assignments')->where('id', $assig->id)->update([
                        'status' => 'en_curso',
                        'started_at' => $validated['occurred_at'],
                        'updated_at' => now(),
                    ]);
                    DB::table('ops_orders')->where('id', $assig->order_id)->update(['status' => 'en_ejecucion', 'updated_at' => now()]);
                    break;

                case 'checkin':
                    $paxId = $validated['payload']['passenger_id'] ?? null;
                    if ($paxId) {
                        DB::table('ops_assignment_checkins')->updateOrInsert(
                            ['assignment_id' => $assig->id, 'passenger_id' => $paxId],
                            [
                                'id' => Str::uuid7()->toString(),
                                'tenant_id' => $tenantId,
                                'checked_at' => $validated['occurred_at'],
                                'source' => 'app',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                    break;

                case 'finish':
                    DB::table('ops_assignments')->where('id', $assig->id)->update([
                        'status' => 'completada',
                        'finished_at' => $validated['occurred_at'],
                        'updated_at' => now(),
                    ]);
                    break;
            }

            $responsePayload = ['status' => 'success', 'assignment_id' => $assig->id, 'action' => $actionType];

            DB::table('ops_sync_actions')->insert([
                'id' => $actionId,
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'action_type' => $actionType,
                'assignment_id' => $assig->id,
                'payload' => json_encode($validated['payload'] ?? []),
                'occurred_at' => $validated['occurred_at'],
                'captured_offline' => $validated['offline'] ?? false,
                'received_at' => now(),
                'result' => $result,
                'response' => json_encode($responsePayload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'result' => $result,
                'response' => $responsePayload,
            ], 200);
        });
    }
}