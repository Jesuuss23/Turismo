<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityController extends Controller
{
    /**
     * GET /api/v1/crm/activities
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $query = Activity::where('tenant_id', $tenantId)->with('owner:id,name,code');

        // Restricción por rol: Asesor Ventas (U03) solo ve sus actividades (§7.6)
        if ($user->code === 'U03') {
            $query->where('owner_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $items = $query->orderBy('due_at', 'asc')->get();

        // KPIs de actividades (§6.3)
        $now = now();
        $kpiQuery = Activity::where('tenant_id', $tenantId);
        if ($user->code === 'U03') {
            $kpiQuery->where('owner_id', $user->id);
        }

        $kpis = [
            'vencidas' => (clone $kpiQuery)->where('status', 'pendiente')->where('due_at', '<', $now)->count(),
            'hoy' => (clone $kpiQuery)->where('status', 'pendiente')->whereDate('due_at', today())->count(),
            'proximos_7_dias' => (clone $kpiQuery)->where('status', 'pendiente')->whereBetween('due_at', [$now, now()->addDays(7)])->count(),
            'completadas_7_dias' => (clone $kpiQuery)->where('status', 'completada')->where('completed_at', '>=', now()->subDays(7))->count(),
        ];

        return response()->json([
            'data' => $items,
            'meta' => [
                'kpis' => $kpis,
            ],
        ]);
    }

    /**
     * POST /api/v1/crm/activities
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'type' => 'required|string|in:llamada,email,whatsapp,reunion,tarea,postventa',
            'subject' => 'required|string|max:200',
            'description' => 'nullable|string',
            'due_at' => 'required|date',
            'related_type' => 'nullable|string|in:lead,opportunity,client,quote,reservation',
            'related_id' => 'nullable|uuid',
        ]);

        $code = SequenceService::nextCode($tenantId, 'activity');

        $activity = Activity::create([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'type' => $validated['type'],
            'subject' => $validated['subject'],
            'description' => $validated['description'] ?? null,
            'related_type' => $validated['related_type'] ?? null,
            'related_id' => $validated['related_id'] ?? null,
            'owner_id' => $user->id,
            'due_at' => $validated['due_at'],
            'status' => 'pendiente',
        ]);

        return response()->json([
            'message' => 'Actividad programada exitosamente.',
            'data' => $activity,
        ], 201);
    }

    /**
     * POST /api/v1/crm/activities/{code}/complete
     * Regla CRM-BR-29 / CRM-BR-30
     */
    public function complete(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $activity = Activity::where('tenant_id', $tenantId)->where('code', $code)->firstOrFail();

        if ($user->code === 'U03' && $activity->owner_id !== $user->id) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'message' => 'Solo puede completar actividades asignadas a su cartera.',
            ], 403);
        }

        $validated = $request->validate([
            'result' => 'required|string|min:3',
            'next_subject' => 'nullable|string|max:200',
            'next_type' => 'nullable|string|in:llamada,email,whatsapp,reunion,tarea,postventa',
            'next_due_at' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($tenantId, $user, $activity, $validated) {
            $activity->update([
                'status' => 'completada',
                'result' => $validated['result'],
                'completed_at' => now(),
                'completed_by' => $user->id,
            ]);

            // Regla CRM-BR-30: Si está vinculada a un lead en "nuevo", pasarlo a "contactado"
            if ($activity->related_type === 'lead' && $activity->related_id) {
                $lead = Lead::where('tenant_id', $tenantId)->find($activity->related_id);
                if ($lead && $lead->status === 'nuevo' && in_array($activity->type, ['llamada', 'email', 'whatsapp', 'reunion'])) {
                    $lead->update([
                        'status' => 'contactado',
                        'first_contact_at' => now(),
                    ]);
                }
            }

            // Crear próxima actividad encadenada si se envió
            if (!empty($validated['next_subject']) && !empty($validated['next_due_at'])) {
                $nextCode = SequenceService::nextCode($tenantId, 'activity');
                Activity::create([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => $nextCode,
                    'type' => $validated['next_type'] ?? $activity->type,
                    'subject' => $validated['next_subject'],
                    'related_type' => $activity->related_type,
                    'related_id' => $activity->related_id,
                    'owner_id' => $user->id,
                    'due_at' => $validated['next_due_at'],
                    'status' => 'pendiente',
                    'previous_activity_id' => $activity->id,
                ]);
            }

            return response()->json([
                'message' => 'Actividad completada exitosamente.',
                'data' => $activity,
            ]);
        });
    }

    /**
     * POST /api/v1/crm/activities/{code}/reschedule
     * Regla CRM-BR-32: Reprogramar actividad
     */
    public function reschedule(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $activity = Activity::where('tenant_id', $tenantId)->where('code', $code)->firstOrFail();

        if ($user->code === 'U03' && $activity->owner_id !== $user->id) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'message' => 'Solo puede reprogramar actividades asignadas a su cartera.',
            ], 403);
        }

        $validated = $request->validate([
            'due_at' => 'required|date|after_or_equal:now',
            'reason' => 'nullable|string',
        ]);

        $activity->update([
            'due_at' => $validated['due_at'],
            'status' => 'pendiente',
        ]);

        return response()->json([
            'message' => 'Actividad reprogramada exitosamente.',
            'data' => $activity,
        ]);
    }
}