<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpportunityController extends Controller
{
    /**
     * GET /api/v1/crm/opportunities
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $query = Opportunity::where('tenant_id', $tenantId)->with('owner:id,name,code');

        // Restricción por rol: Asesor Ventas (U03) solo ve las suyas (§7.6)
        if ($user->code === 'U03') {
            $query->where('owner_id', $user->id);
        }

        if ($request->filled('stage')) {
            $query->where('stage', $request->stage);
        }

        if ($request->filled('q')) {
            $search = '%' . $request->q . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', $search)
                  ->orWhere('code', 'ilike', $search)
                  ->orWhere('destination_label', 'ilike', $search);
            });
        }

        $items = $query->orderBy('expected_close_date', 'asc')->get();

        // Totales y KPIs (§6.2)
        $openItems = $items->whereNotIn('stage', ['ganada', 'perdida']);
        $wonItems = $items->where('stage', 'ganada');

        $pipelineOpen = $openItems->sum('amount');
        $pipelineWeighted = $openItems->sum(fn ($o) => $o->amount * ($o->probability / 100));

        $kpis = [
            'pipeline_abierto' => round($pipelineOpen, 2),
            'pipeline_ponderado' => round($pipelineWeighted, 2),
            'ganadas_mes' => round($wonItems->sum('amount'), 2),
            'total_abiertas' => $openItems->count(),
        ];

        return response()->json([
            'data' => $items,
            'meta' => [
                'kpis' => $kpis,
            ],
        ]);
    }

    /**
     * POST /api/v1/crm/opportunities
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|in:USD,PEN',
            'expected_close_date' => 'required|date|after_or_equal:today',
            'destination_label' => 'nullable|string|max:120',
            'pax' => 'nullable|integer|min:0',
            'stage' => 'nullable|string|in:calificacion,descubrimiento,cotizacion,negociacion',
            'notes' => 'nullable|string',
        ]);

        $stage = $validated['stage'] ?? 'descubrimiento';

        // Probabilidad por defecto según la etapa (§3.6)
        $stageConfig = DB::table('crm_pipeline_stages')
            ->where('tenant_id', $tenantId)
            ->where('code', $stage)
            ->first();

        $probability = $stageConfig ? $stageConfig->default_probability : 35;
        $code = SequenceService::nextCode($tenantId, 'opportunity');

        $opp = Opportunity::create([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'title' => $validated['title'],
            'stage' => $stage,
            'amount' => $validated['amount'],
            'currency' => $validated['currency'],
            'probability' => $probability,
            'expected_close_date' => $validated['expected_close_date'],
            'owner_id' => $user->id,
            'destination_label' => $validated['destination_label'] ?? null,
            'pax' => $validated['pax'] ?? 0,
            'origin' => 'Manual',
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Oportunidad creada exitosamente.',
            'data' => $opp,
        ], 201);
    }

    /**
     * POST /api/v1/crm/opportunities/{code}/stage
     * Mover etapa (drag & drop o acción) - CRM-BR-22 / 24
     */
    public function changeStage(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $opp = Opportunity::where('tenant_id', $tenantId)->where('code', $code)->firstOrFail();

        // Validación de pertenencia si es Ventas (U03)
        if ($user->code === 'U03' && $opp->owner_id !== $user->id) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'message' => 'Esta oportunidad pertenece a la cartera de otro asesor.',
            ], 403);
        }

        $validated = $request->validate([
            'stage' => 'required|string|in:calificacion,descubrimiento,cotizacion,negociacion,ganada,perdida',
            'lost_reason_code' => 'required_if:stage,perdida|nullable|string',
            'lost_detail' => 'nullable|string',
        ]);

        $stageConfig = DB::table('crm_pipeline_stages')
            ->where('tenant_id', $tenantId)
            ->where('code', $validated['stage'])
            ->first();

        $updateData = [
            'stage' => $validated['stage'],
            'probability' => $stageConfig ? $stageConfig->default_probability : 0,
            'lock_version' => $opp->lock_version + 1,
        ];

        if ($validated['stage'] === 'ganada') {
            $updateData['closed_at'] = today();
            $updateData['lost_reason_code'] = null;
            $updateData['lost_detail'] = null;
        } elseif ($validated['stage'] === 'perdida') {
            $updateData['closed_at'] = today();
            $updateData['lost_at_stage'] = $opp->stage;
            $updateData['lost_reason_code'] = $validated['lost_reason_code'];
            $updateData['lost_detail'] = $validated['lost_detail'] ?? null;
        } else {
            // Reabrir o etapa intermedia
            $updateData['closed_at'] = null;
        }

        $opp->update($updateData);

        return response()->json([
            'message' => "Oportunidad actualizada a etapa {$validated['stage']}.",
            'data' => $opp,
        ]);
    }

    /**
     * GET /api/v1/crm/opportunities/{code}
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $opportunity = Opportunity::where('tenant_id', $tenantId)
            ->where('code', $code)
            ->with(['owner:id,name,code'])
            ->firstOrFail();

        // Validación de cartera Ventas (§7.6)
        if ($user->code === 'U03' && $opportunity->owner_id !== $user->id) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'code' => 'SCOPE_OWNERSHIP',
                'message' => 'Esta oportunidad pertenece a la cartera de otro asesor.',
            ], 403);
        }

        // Cliente asociado
        $client = null;
        if ($opportunity->client_id) {
            $client = \App\Models\Client::where('tenant_id', $tenantId)->find($opportunity->client_id);
        }

        // Lead origen si existe
        $lead = null;
        if ($opportunity->lead_id) {
            $lead = \App\Models\Lead::where('tenant_id', $tenantId)->find($opportunity->lead_id);
        }

        // Actividades vinculadas a la oportunidad
        $activities = \App\Models\Activity::where('tenant_id', $tenantId)
            ->where('related_type', 'opportunity')
            ->where('related_id', $opportunity->id)
            ->orderBy('due_at', 'desc')
            ->get();

        return response()->json([
            'opportunity' => $opportunity,
            'client' => $client,
            'lead' => $lead,
            'activities' => $activities,
        ]);
    }
}