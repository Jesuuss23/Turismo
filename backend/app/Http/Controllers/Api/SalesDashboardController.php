<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesDashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard/sales
     * Read-model consolidado para el asesor de ventas (§7.4)
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        // 1. Pipeline del asesor
        $opportunities = Opportunity::where('tenant_id', $tenantId)
            ->where('owner_id', $user->id)
            ->whereNotIn('stage', ['ganada', 'perdida'])
            ->orderBy('expected_close_date', 'asc')
            ->get();

        $pipelineTotal = $opportunities->sum('amount');
        $pipelineWeighted = $opportunities->sum(fn ($o) => $o->amount * ($o->probability / 100));

        // 2. Actividades de hoy y vencidas del asesor
        $now = now();
        $dueActivities = Activity::where('tenant_id', $tenantId)
            ->where('owner_id', $user->id)
            ->where('status', 'pendiente')
            ->where('due_at', '<=', now()->endOfDay())
            ->orderBy('due_at', 'asc')
            ->take(5)
            ->get();

        // 3. Mis Leads activos
        $myLeads = Lead::where('tenant_id', $tenantId)
            ->where('owner_id', $user->id)
            ->whereIn('status', ['nuevo', 'contactado', 'calificado'])
            ->orderBy('score', 'desc')
            ->take(5)
            ->get();

        // 4. Bolsa común de leads (disponibles para tomar)
        $unassignedLeads = Lead::where('tenant_id', $tenantId)
            ->whereNull('owner_id')
            ->whereIn('status', ['nuevo', 'contactado'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        // 5. Agrupación por etapas abiertas
        $stagesCount = [
            'calificacion' => $opportunities->where('stage', 'calificacion')->count(),
            'descubrimiento' => $opportunities->where('stage', 'descubrimiento')->count(),
            'cotizacion' => $opportunities->where('stage', 'cotizacion')->count(),
            'negociacion' => $opportunities->where('stage', 'negociacion')->count(),
        ];

        return response()->json([
            'kpis' => [
                'pipeline_total' => round($pipelineTotal, 2),
                'pipeline_weighted' => round($pipelineWeighted, 2),
                'opportunities_count' => $opportunities->count(),
                'due_activities_count' => $dueActivities->count(),
            ],
            'stages_summary' => $stagesCount,
            'due_activities' => $dueActivities,
            'my_leads' => $myLeads,
            'unassigned_leads' => $unassignedLeads,
        ]);
    }
}