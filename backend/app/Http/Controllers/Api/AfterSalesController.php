<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\CrmCase;
use App\Models\Survey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AfterSalesController extends Controller
{
    /**
     * GET /api/v1/crm/after-sales/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $surveys = Survey::where('tenant_id', $tenantId)->with('client:id,name,code')->orderBy('responded_at', 'desc')->get();
        $cases = CrmCase::where('tenant_id', $tenantId)->with(['client:id,name,code', 'owner:id,name,code'])->orderBy('created_at', 'desc')->get();

        $totalSurveys = $surveys->count();
        $promoters = $surveys->where('category', 'promotor')->count();
        $detractors = $surveys->where('category', 'detractor')->count();
        $npsScore = $totalSurveys > 0 ? round((($promoters - $detractors) / $totalSurveys) * 100) : 0;

        $kpis = [
            'nps_general' => $npsScore,
            'total_encuestas' => $totalSurveys,
            'casos_abiertos' => $cases->whereIn('status', ['abierta', 'en_proceso'])->count(),
            'promedio_nps' => round($surveys->avg('score') ?? 0, 1),
        ];

        return response()->json([
            'surveys' => $surveys->take(10),
            'cases' => $cases->take(10),
            'meta' => [
                'kpis' => $kpis,
            ],
        ]);
    }

    /**
     * POST /api/v1/crm/surveys
     * Reglas CRM-BR-38, CRM-BR-39, CRM-BR-40
     */
    public function storeSurvey(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'client_id' => 'required|uuid|exists:crm_clients,id',
            'score' => 'required|integer|min:0|max:10',
            'comment' => 'nullable|string',
            'channel_code' => 'required|string',
        ]);

        $score = $validated['score'];
        $category = $score >= 9 ? 'promotor' : ($score >= 7 ? 'pasivo' : 'detractor');

        return DB::transaction(function () use ($tenantId, $user, $validated, $score, $category) {
            $client = Client::where('tenant_id', $tenantId)->findOrFail($validated['client_id']);

            $surveyCode = SequenceService::nextCode($tenantId, 'survey');
            $survey = Survey::create([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'code' => $surveyCode,
                'client_id' => $client->id,
                'score' => $score,
                'category' => $category,
                'comment' => $validated['comment'] ?? null,
                'channel_code' => $validated['channel_code'],
                'recorded_by' => $user->id,
            ]);

            // Regla CRM-BR-40: Actualizar NPS último en cliente
            $client->update(['nps_last' => $score]);

            // Regla CRM-BR-39: Si es detractor (<= 6), disparar caso Reclamo y actividad Postventa
            if ($category === 'detractor') {
                $caseCode = SequenceService::nextCode($tenantId, 'case');
                $priority = $score <= 3 ? 'alta' : 'media';

                $case = CrmCase::create([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => $caseCode,
                    'client_id' => $client->id,
                    'survey_id' => $survey->id,
                    'origin' => 'nps_auto',
                    'type_code' => 'reclamo',
                    'priority' => $priority,
                    'subject' => "Detractor NPS {$score} — Atención Inmediata a {$client->name}",
                    'description' => $validated['comment'] ?? 'Encuesta con calificación de detractor registrada por el sistema.',
                    'status' => 'abierta',
                    'owner_id' => $client->owner_id,
                ]);

                // Actividad de Postventa para mañana a las 10:00 AM
                $actCode = SequenceService::nextCode($tenantId, 'activity');
                Activity::create([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => $actCode,
                    'type' => 'postventa',
                    'subject' => "Resolver {$caseCode} — Contactar a {$client->name}",
                    'description' => "Atender inconformidad reportada en encuesta NPS {$score}.",
                    'related_type' => 'client',
                    'related_id' => $client->id,
                    'owner_id' => $client->owner_id,
                    'due_at' => now()->addDay()->setHour(10)->setMinute(0)->setSecond(0),
                    'status' => 'pendiente',
                ]);
            }

            return response()->json([
                'message' => 'Encuesta NPS registrada exitosamente.',
                'data' => $survey,
            ], 201);
        });
    }

    /**
     * POST /api/v1/crm/cases/{code}/resolve
     * Regla CRM-BR-43: Resolver caso
     */
    public function resolveCase(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $case = CrmCase::where('tenant_id', $tenantId)->where('code', $code)->firstOrFail();

        $validated = $request->validate([
            'resolution' => 'required|string|min:5',
        ]);

        $case->update([
            'status' => 'resuelta',
            'resolution' => $validated['resolution'],
            'resolved_at' => now(),
            'lock_version' => $case->lock_version + 1,
        ]);

        return response()->json([
            'message' => 'Caso resuelto correctamente.',
            'data' => $case,
        ]);
    }
}