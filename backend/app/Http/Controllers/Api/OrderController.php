<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * GET /api/v1/ops/orders
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('ops_orders')
            ->join('booking_reservations', 'ops_orders.reservation_id', '=', 'booking_reservations.id')
            ->join('crm_clients', 'booking_reservations.client_id', '=', 'crm_clients.id')
            ->leftJoin('iam_users', 'ops_orders.coordinator_id', '=', 'iam_users.id')
            ->where('ops_orders.tenant_id', $tenantId)
            ->select(
                'ops_orders.*',
                'booking_reservations.code as reservation_code',
                'crm_clients.name as client_name',
                'iam_users.name as coordinator_name'
            )
            ->orderBy('ops_orders.date_from', 'desc');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('ops_orders.status', $request->status);
        }

        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $query->where(function ($sub) use ($s) {
                $sub->where('ops_orders.code', 'ilike', $s)
                    ->orWhere('ops_orders.title', 'ilike', $s)
                    ->orWhere('crm_clients.name', 'ilike', $s);
            });
        }

        $orders = $query->get()->map(function ($o) use ($tenantId) {
            // Contar total asignaciones y las que tienen recursos
            $totalAssig = DB::table('ops_assignments')->where('order_id', $o->id)->count();
            $withResources = DB::table('ops_assignments')->where('order_id', $o->id)->whereNotNull('guide_id')->count();
            $openIncidents = DB::table('ops_incidents')->where('order_id', $o->id)->whereIn('status', ['abierta', 'en_proceso'])->count();

            return [
                'id' => $o->id,
                'code' => $o->code,
                'title' => $o->title,
                'status' => $o->status,
                'reservation_code' => $o->reservation_code,
                'client_name' => $o->client_name,
                'coordinator_name' => $o->coordinator_name ?? 'Sin asignar',
                'date_from' => substr($o->date_from, 0, 10),
                'date_to' => substr($o->date_to, 0, 10),
                'assignments_count' => $totalAssig,
                'assigned_pct' => $totalAssig > 0 ? round(($withResources / $totalAssig) * 100) : 100,
                'open_incidents' => $openIncidents,
                'created_at' => substr($o->created_at, 0, 19),
            ];
        });

        return response()->json(['data' => $orders]);
    }

    /**
     * GET /api/v1/ops/orders/kpis
     */
    public function kpis(): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $enEjecucion = DB::table('ops_orders')
            ->where('tenant_id', $tenantId)
            ->where('status', 'en_ejecucion')
            ->count();

        $pendientesAsignacion = DB::table('ops_assignments')
            ->where('tenant_id', $tenantId)
            ->whereNull('guide_id')
            ->where('status', 'pendiente')
            ->count();

        $serviciosHoyManana = DB::table('ops_assignments')
            ->where('tenant_id', $tenantId)
            ->whereIn('service_date', [$today, $tomorrow])
            ->count();

        $incidenciasCriticas = DB::table('ops_incidents')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['abierta', 'en_proceso'])
            ->where('severity', 'critica')
            ->count();

        return response()->json([
            'en_ejecucion' => $enEjecucion,
            'pendientes_asignacion' => $pendientesAsignacion,
            'servicios_hoy_manana' => $serviciosHoyManana,
            'incidencias_criticas' => $incidenciasCriticas,
        ]);
    }

    /**
     * POST /api/v1/ops/orders
     * Crear orden operativa desde reserva (PROC-031)
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'reservation_id' => 'required|uuid|exists:booking_reservations,id',
            'coordinator_id' => 'nullable|uuid|exists:iam_users,id',
        ]);

        $res = DB::table('booking_reservations')->where('tenant_id', $tenantId)->where('id', $validated['reservation_id'])->first();

        // OPS-BR-01: Solo reservas confirmadas o en operación
        if (!in_array($res->status, ['confirmada', 'en_operacion'])) {
            return response()->json([
                'error' => 'OPS-BR-01',
                'message' => "Solo una reserva confirmada o en operación puede generar orden (estado actual: {$res->status}).",
            ], 422);
        }

        // OPS-BR-02: Una orden por reserva
        $existing = DB::table('ops_orders')->where('tenant_id', $tenantId)->where('reservation_id', $res->id)->whereNull('deleted_at')->first();
        if ($existing) {
            return response()->json([
                'message' => "La reserva ya tiene la orden operativa {$existing->code}.",
                'data' => $existing,
            ], 200);
        }

        return DB::transaction(function () use ($tenantId, $user, $res, $validated) {
            $orderId = Str::uuid7()->toString();
            $orderCode = SequenceService::nextCode($tenantId, 'operation_order');
            $coordinatorId = $validated['coordinator_id'] ?? $user->id;

            // 1. Insertar orden
            DB::table('ops_orders')->insert([
                'id' => $orderId,
                'tenant_id' => $tenantId,
                'code' => $orderCode,
                'reservation_id' => $res->id,
                'title' => $res->title,
                'status' => 'pendiente_asignacion',
                'coordinator_id' => $coordinatorId,
                'date_from' => $res->travel_from,
                'date_to' => $res->travel_to,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Tareas por defecto (5)
            $defaultTasks = [
                'Enviar solicitudes a proveedores',
                'Asignar guías, conductores y vehículos',
                'Emitir vouchers de servicios confirmados',
                'Llamada de bienvenida al cliente',
                'Encuesta de cierre y liquidación',
            ];

            foreach ($defaultTasks as $idx => $t) {
                DB::table('ops_order_tasks')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'order_id' => $orderId,
                    'title' => $t,
                    'position' => $idx + 1,
                    'done' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 3. Replicar servicios como asignaciones (OPS-BR-03)
            $services = DB::table('booking_services')->where('reservation_id', $res->id)->get();
            foreach ($services as $s) {
                DB::table('ops_assignments')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'order_id' => $orderId,
                    'reservation_service_id' => $s->id,
                    'service_date' => $s->service_date,
                    'service_time' => $s->service_time ?? '08:00',
                    'service_label' => $s->name,
                    'status' => 'pendiente',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return response()->json([
                'message' => "Orden operativa {$orderCode} creada con éxito.",
                'data' => ['id' => $orderId, 'code' => $orderCode],
            ], 201);
        });
    }

    /**
     * GET /api/v1/ops/orders/{code}
     * Orden 360 interactiva
     */
    public function show(string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('ops_orders')
            ->join('booking_reservations', 'ops_orders.reservation_id', '=', 'booking_reservations.id')
            ->join('crm_clients', 'booking_reservations.client_id', '=', 'crm_clients.id')
            ->leftJoin('iam_users', 'ops_orders.coordinator_id', '=', 'iam_users.id')
            ->where('ops_orders.tenant_id', $tenantId);

        if (Str::isUuid($code)) {
            $query->where('ops_orders.id', $code);
        } else {
            $query->where('ops_orders.code', $code);
        }

        $order = $query->select(
            'ops_orders.*',
            'booking_reservations.code as reservation_code',
            'booking_reservations.total_amount',
            'booking_reservations.currency',
            'crm_clients.name as client_name',
            'crm_clients.phone as client_phone',
            'iam_users.name as coordinator_name'
        )->first();

        if (!$order) {
            return response()->json(['message' => 'Orden operativa no encontrada.'], 404);
        }

        // Tareas
        $tasks = DB::table('ops_order_tasks')->where('order_id', $order->id)->orderBy('position')->get();

        // Asignaciones con nombres de recursos
        $assignments = DB::table('ops_assignments')
            ->leftJoin('ops_guides', 'ops_assignments.guide_id', '=', 'ops_guides.id')
            ->leftJoin('ops_drivers', 'ops_assignments.driver_id', '=', 'ops_drivers.id')
            ->leftJoin('ops_vehicles', 'ops_assignments.vehicle_id', '=', 'ops_vehicles.id')
            ->where('ops_assignments.order_id', $order->id)
            ->select(
                'ops_assignments.*',
                'ops_guides.full_name as guide_name',
                'ops_drivers.full_name as driver_name',
                'ops_vehicles.plate as vehicle_plate',
                'ops_vehicles.vehicle_type'
            )
            ->orderBy('ops_assignments.service_date')
            ->get();

        // Pasajeros
        $passengers = DB::table('booking_passengers')->where('reservation_id', $order->reservation_id)->orderBy('seq')->get();

        // Incidencias
        $incidents = DB::table('ops_incidents')->where('order_id', $order->id)->orderBy('created_at', 'desc')->get();

        // Vouchers
        $vouchers = DB::table('ops_vouchers')->where('reservation_id', $order->reservation_id)->get();

        return response()->json([
            'order' => $order,
            'tasks' => $tasks,
            'assignments' => $assignments,
            'passengers' => $passengers,
            'incidents' => $incidents,
            'vouchers' => $vouchers,
        ]);
    }

    /**
     * PATCH /api/v1/ops/order-tasks/{id}
     */
    public function toggleTask(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $task = DB::table('ops_order_tasks')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$task) return response()->json(['message' => 'Tarea no encontrada.'], 404);

        $newDone = !$task->done;
        DB::table('ops_order_tasks')->where('id', $task->id)->update([
            'done' => $newDone,
            'done_by' => $newDone ? $user->id : null,
            'done_at' => $newDone ? now() : null,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Tarea actualizada.', 'done' => $newDone]);
    }

    /**
     * POST /api/v1/ops/orders/{code}/close
     * Cierre Operativo con verificación de las 4 precondiciones (PROC-039)
     */
    public function close(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $order = DB::table('ops_orders')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if (!$order) return response()->json(['message' => 'Orden no encontrada.'], 404);

        // Precondición 1: Ninguna incidencia abierta o en proceso (OPS-BR-81)
        $openIncidents = DB::table('ops_incidents')
            ->where('tenant_id', $tenantId)
            ->where('order_id', $order->id)
            ->whereIn('status', ['abierta', 'en_proceso'])
            ->count();

        if ($openIncidents > 0) {
            return response()->json([
                'error' => 'OPS-BR-81',
                'message' => "No se puede cerrar la orden: Hay {$openIncidents} incidencia(s) pendientes de resolución.",
            ], 422);
        }

        // Precondición 2: Verificar servicios
        $pendingSvcs = DB::table('ops_assignments')
            ->where('tenant_id', $tenantId)
            ->where('order_id', $order->id)
            ->whereIn('status', ['pendiente', 'asignada'])
            ->count();

        if ($pendingSvcs > 0 && !$request->boolean('force')) {
            return response()->json([
                'error' => 'OPS-BR-80',
                'message' => "Hay {$pendingSvcs} asignación(es) no completadas en campo.",
            ], 422);
        }

        DB::transaction(function () use ($order, $user) {
            DB::table('ops_orders')->where('id', $order->id)->update([
                'status' => 'completada',
                'closed_at' => now(),
                'closed_by' => $user->id,
                'updated_at' => now(),
            ]);

            DB::table('booking_reservations')->where('id', $order->reservation_id)->update([
                'status' => 'completada',
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => "Orden {$order->code} cerrada y completada con éxito."]);
    }
}