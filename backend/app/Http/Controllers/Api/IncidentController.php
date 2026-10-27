<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IncidentController extends Controller
{
    /**
     * GET /api/v1/ops/incidents
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('ops_incidents')
            ->leftJoin('ops_orders', 'ops_incidents.order_id', '=', 'ops_orders.id')
            ->leftJoin('iam_users as reporters', 'ops_incidents.reported_by', '=', 'reporters.id')
            ->where('ops_incidents.tenant_id', $tenantId)
            ->select('ops_incidents.*', 'ops_orders.code as order_code', 'reporters.name as reporter_name')
            ->orderBy('ops_incidents.created_at', 'desc');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('ops_incidents.status', $request->status);
        }

        if ($request->filled('severity') && $request->severity !== 'todos') {
            $query->where('ops_incidents.severity', $request->severity);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/v1/ops/incidents
     * Registrar incidencia (PROC-037)
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'order_id' => 'required|uuid|exists:ops_orders,id',
            'type' => 'required|string',
            'severity' => 'required|string|in:baja,media,alta,critica',
            'title' => 'required|string|max:200',
            'description' => 'required|string|min:5',
        ]);

        // SLA por severidad (OPS-BR-60)
        $slaHours = match ($validated['severity']) {
            'critica' => 4,
            'alta' => 8,
            'media' => 24,
            'baja' => 72,
        };

        $incCode = SequenceService::nextCode($tenantId, 'incident');
        $incId = Str::uuid7()->toString();
        $order = DB::table('ops_orders')->where('id', $validated['order_id'])->first();

        DB::table('ops_incidents')->insert([
            'id' => $incId,
            'tenant_id' => $tenantId,
            'code' => $incCode,
            'order_id' => $order->id,
            'reservation_id' => $order->reservation_id,
            'type' => $validated['type'],
            'severity' => $validated['severity'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => 'abierta',
            'assignee_id' => $order->coordinator_id,
            'reported_by' => $user->id,
            'source' => 'web',
            'sla_hours' => $slaHours,
            'sla_due_at' => now()->addHours($slaHours),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Incidencia {$incCode} registrada exitosamente.",
            'code' => $incCode,
            'severity' => $validated['severity'],
        ], 201);
    }

    /**
     * POST /api/v1/ops/incidents/{id}/resolve
     */
    public function resolve(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'resolution' => 'required|string|min:5',
        ]);

        DB::table('ops_incidents')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'status' => 'resuelta',
                'resolution' => $validated['resolution'],
                'resolved_at' => now(),
                'resolved_by' => $user->id,
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Incidencia marcada como resuelta.']);
    }
}