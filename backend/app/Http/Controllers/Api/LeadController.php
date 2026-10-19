<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    /**
     * GET /api/v1/crm/leads
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $query = Lead::where('tenant_id', $tenantId)->with('owner:id,name,code');

        // Filtro de alcance por rol (§7.6 - Ventas solo ve propios o sin asignar)
        if ($user->code === 'U03') {
            $query->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                  ->orWhereNull('owner_id');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $search = '%' . $request->q . '%';
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', $search)
                  ->orWhere('code', 'ilike', $search)
                  ->orWhere('company', 'ilike', $search)
                  ->orWhere('email', 'ilike', $search)
                  ->orWhere('phone', 'ilike', $search);
            });
        }

        $leads = $query->orderBy('created_at', 'desc')->paginate(15);

        // KPIs calculados (§6.1)
        $kpiBase = Lead::where('tenant_id', $tenantId);
        $total = (clone $kpiBase)->count();
        $converted = (clone $kpiBase)->where('status', 'convertido')->count();

        $kpis = [
            'nuevos_hoy' => (clone $kpiBase)->where('status', 'nuevo')->whereDate('created_at', today())->count(),
            'sin_contactar' => (clone $kpiBase)->whereNull('owner_id')->whereIn('status', ['nuevo', 'contactado'])->count(),
            'calificados' => (clone $kpiBase)->where('status', 'calificado')->count(),
            'tasa_conversion' => $total > 0 ? round(($converted / $total) * 100, 1) : 0,
        ];

        return response()->json([
            'data' => $leads->items(),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'total' => $leads->total(),
                'kpis' => $kpis,
            ],
        ]);
    }

    /**
     * POST /api/v1/crm/leads
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'full_name' => 'required|string|max:160',
            'company' => 'nullable|string|max:160',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'channel_code' => 'required|string',
            'source_code' => 'required|string',
            'interest_code' => 'required|string',
            'destination_label' => 'nullable|string|max:120',
            'travel_date' => 'nullable|date|after_or_equal:today',
            'pax' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        // Validación PROC-004 / CRM-BR-02: Al menos correo o teléfono
        if (empty($validated['email']) && empty($validated['phone'])) {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'Validación PROC-004: registre al menos correo o teléfono.',
            ], 422);
        }

        // Score inicial según regla CRM-BR-07
        $score = 25;
        if (!empty($validated['email'])) $score += 8;
        if (!empty($validated['phone'])) $score += 8;
        if (!empty($validated['travel_date'])) $score += 10;
        if (!empty($validated['pax']) && $validated['pax'] > 0) $score += 5;
        if (!empty($validated['company'])) $score += 4;
        if (in_array($validated['source_code'], ['referido', 'llamada_entrante'])) $score += 6;
        $score = min(70, $score);

        $code = SequenceService::nextCode($tenantId, 'lead');

        // Asignación: Ventas U03 siempre asigna a sí mismo; de lo contrario cola común (null)
        $ownerId = ($user->code === 'U03') ? $user->id : null;

        $lead = Lead::create([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'full_name' => $validated['full_name'],
            'company' => $validated['company'] ?? null,
            'email' => $validated['email'] ?? null,
            'email_norm' => !empty($validated['email']) ? strtolower(trim($validated['email'])) : null,
            'phone' => $validated['phone'] ?? null,
            'phone_norm' => !empty($validated['phone']) ? substr(preg_replace('/\D/', '', $validated['phone']), -9) : null,
            'channel_code' => $validated['channel_code'],
            'source_code' => $validated['source_code'],
            'interest_code' => $validated['interest_code'],
            'destination_label' => $validated['destination_label'] ?? null,
            'travel_date' => $validated['travel_date'] ?? null,
            'pax' => $validated['pax'] ?? 0,
            'status' => 'nuevo',
            'owner_id' => $ownerId,
            'score' => $score,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Lead registrado correctamente.',
            'data' => $lead,
        ], 201);
    }

    /**
     * POST /api/v1/crm/leads/{code}/take
     * Regla CRM-BR-08: Toma atómica de lead
     */
    public function take(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $affected = DB::table('crm_leads')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->whereNull('owner_id')
            ->whereNotIn('status', ['convertido', 'descartado'])
            ->update([
                'owner_id' => $user->id,
                'taken_by' => $user->id,
                'assigned_at' => now(),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return response()->json([
                'error' => 'CONFLICT',
                'message' => 'Otro asesor tomó el lead antes que usted o el registro no está disponible.',
            ], 409);
        }

        return response()->json([
            'message' => "Lead {$code} tomado exitosamente.",
        ]);
    }

    /**
     * GET /api/v1/crm/leads/duplicates
     * Regla CRM-BR-03: Detección de duplicados en vivo
     */
    public function checkDuplicates(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $email = $request->query('email');
        $phone = $request->query('phone');
        $excludeCode = $request->query('exclude');

        $duplicates = [];

        $emailNorm = !empty($email) ? strtolower(trim($email)) : null;
        $phoneNorm = !empty($phone) ? substr(preg_replace('/\D/', '', $phone), -9) : null;

        if ($emailNorm || $phoneNorm) {
            // Buscar en leads
            $leads = Lead::where('tenant_id', $tenantId)
                ->when($excludeCode, fn ($q) => $q->where('code', '!=', $excludeCode))
                ->where(function ($q) use ($emailNorm, $phoneNorm) {
                    if ($emailNorm) $q->orWhere('email_norm', $emailNorm);
                    if ($phoneNorm) $q->orWhere('phone_norm', $phoneNorm);
                })
                ->get();

            foreach ($leads as $l) {
                $matchedFields = [];
                if ($emailNorm && $l->email_norm === $emailNorm) $matchedFields[] = 'correo';
                if ($phoneNorm && $l->phone_norm === $phoneNorm) $matchedFields[] = 'teléfono';

                $duplicates[] = [
                    'type' => 'lead',
                    'id' => $l->id,
                    'code' => $l->code,
                    'name' => $l->full_name,
                    'status' => $l->status,
                    'matched_fields' => implode(', ', $matchedFields),
                ];
            }

            // Buscar en clientes
            $clients = \App\Models\Client::where('tenant_id', $tenantId)
                ->where(function ($q) use ($emailNorm, $phoneNorm) {
                    if ($emailNorm) $q->orWhere('email_norm', $emailNorm);
                    if ($phoneNorm) $q->orWhere('phone_norm', $phoneNorm);
                })
                ->get();

            foreach ($clients as $c) {
                $matchedFields = [];
                if ($emailNorm && $c->email_norm === $emailNorm) $matchedFields[] = 'correo';
                if ($phoneNorm && $c->phone_norm === $phoneNorm) $matchedFields[] = 'teléfono';

                $duplicates[] = [
                    'type' => 'client',
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'status' => $c->segment_code,
                    'matched_fields' => implode(', ', $matchedFields),
                ];
            }
        }

        return response()->json(['duplicates' => $duplicates]);
    }

    /**
     * GET /api/v1/crm/leads/{code}
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $lead = Lead::where('tenant_id', $tenantId)
            ->where('code', $code)
            ->with('owner:id,name,code')
            ->firstOrFail();

        // Regla SEC-BR-09: Asesor comercial solo ve los suyos o los que están en la bolsa común
        if ($user->code === 'U03' && $lead->owner_id && $lead->owner_id !== $user->id) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'code' => 'SCOPE_OWNERSHIP',
                'message' => "El lead {$code} pertenece a la cartera de otro asesor.",
            ], 403);
        }

        // Calificación BANT vigente (última registrada)
        $qualification = DB::table('crm_lead_qualifications')
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $lead->id)
            ->orderBy('qualified_at', 'desc')
            ->first();

        // Actividades vinculadas
        $activities = \App\Models\Activity::where('tenant_id', $tenantId)
            ->where('related_type', 'lead')
            ->where('related_id', $lead->id)
            ->orderBy('due_at', 'desc')
            ->get();

        return response()->json([
            'data' => $lead,
            'qualification' => $qualification,
            'activities' => $activities,
        ]);
    }

    /**
     * POST /api/v1/crm/leads/{code}/qualify
     * Reglas PROC-005, CRM-BR-10: Calificación BANT
     */
    public function qualify(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $lead = Lead::where('tenant_id', $tenantId)->where('code', $code)->firstOrFail();

        if ($user->code === 'U03' && $lead->owner_id !== $user->id) {
            return response()->json(['error' => 'FORBIDDEN', 'message' => 'No puede calificar un lead ajeno.'], 403);
        }

        $validated = $request->validate([
            'budget' => 'required|integer|in:0,10,18,25',
            'authority' => 'required|integer|in:0,10,18,25',
            'need' => 'required|integer|in:0,10,18,25',
            'timeline' => 'required|integer|in:0,10,18,25',
            'declared_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|in:PEN,USD',
            'notes' => 'nullable|string',
            'mark_qualified' => 'nullable|boolean',
        ]);

        $score = $validated['budget'] + $validated['authority'] + $validated['need'] + $validated['timeline'];

        return DB::transaction(function () use ($tenantId, $user, $lead, $validated, $score) {
            DB::table('crm_lead_qualifications')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'budget' => $validated['budget'],
                'authority' => $validated['authority'],
                'need' => $validated['need'],
                'timeline' => $validated['timeline'],
                'score' => $score,
                'declared_amount' => $validated['declared_amount'] ?? null,
                'currency' => $validated['currency'] ?? 'USD',
                'notes' => $validated['notes'] ?? null,
                'qualified_by' => $user->id,
                'qualified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $lead->score = $score;

            // CRM-BR-10: Para pasar a calificado debe alcanzar score >= 50
            if (!empty($validated['mark_qualified'])) {
                if ($score < 50) {
                    return response()->json([
                        'error' => 'VALIDATION_FAILED',
                        'message' => "PROC-005: Para marcar como calificado el score debe ser >= 50 (obtenido: {$score}).",
                    ], 422);
                }
                $lead->status = 'calificado';
            }

            $lead->save();

            return response()->json([
                'message' => 'Calificación BANT registrada exitosamente.',
                'score' => $score,
                'status' => $lead->status,
            ]);
        });
    }

    /**
     * POST /api/v1/crm/leads/{code}/convert
     * Reglas PROC-005, CRM-BR-12, CRM-BR-18, CRM-BR-19
     */
    public function convert(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $lead = Lead::where('tenant_id', $tenantId)->where('code', $code)->firstOrFail();

        // CRM-BR-12: Solo leads en estado calificado se pueden convertir
        if ($lead->status !== 'calificado') {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'PROC-005: Solo se pueden convertir leads en estado "calificado".',
            ], 422);
        }

        $validated = $request->validate([
            'client_mode' => 'required|string|in:new,existing',
            'client_id' => 'required_if:client_mode,existing|nullable|uuid',
            'client.kind' => 'required_if:client_mode,new|nullable|string|in:persona,empresa',
            'client.name' => 'required_if:client_mode,new|nullable|string|max:200',
            'client.doc_type' => 'required_if:client_mode,new|nullable|string|in:DNI,RUC,PASAPORTE,CE',
            'client.doc_number' => 'required_if:client_mode,new|nullable|string|max:20',
            'client.segment' => 'nullable|string',
            'opportunity.title' => 'required|string|max:200',
            'opportunity.amount' => 'required|numeric|min:0.01',
            'opportunity.currency' => 'required|string|in:USD,PEN',
            'opportunity.expected_close_date' => 'required|date|after_or_equal:today',
            'schedule_meeting' => 'nullable|boolean',
            'meeting_due_at' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($tenantId, $user, $lead, $validated) {
            // 1. Obtener o crear Cliente
            if ($validated['client_mode'] === 'existing') {
                $client = \App\Models\Client::where('tenant_id', $tenantId)->findOrFail($validated['client_id']);
            } else {
                $clientData = $validated['client'];
                $clientCode = SequenceService::nextCode($tenantId, 'client');
                $client = \App\Models\Client::create([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => $clientCode,
                    'kind' => $clientData['kind'],
                    'name' => $clientData['name'],
                    'doc_type' => $clientData['doc_type'],
                    'doc_number' => trim($clientData['doc_number']),
                    'email' => $lead->email,
                    'email_norm' => $lead->email_norm,
                    'phone' => $lead->phone,
                    'phone_norm' => $lead->phone_norm,
                    'country' => 'Perú',
                    'segment_code' => $clientData['segment'] ?? 'retail',
                    'owner_id' => $lead->owner_id ?? $user->id,
                    'source_lead_id' => $lead->id,
                ]);

                // Contacto principal
                \App\Models\ClientContact::create([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'name' => $lead->full_name,
                    'role' => $client->kind === 'empresa' ? 'Representante' : 'Titular',
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'is_primary' => true,
                    'source_lead_id' => $lead->id,
                ]);
            }

            // 2. Crear Oportunidad
            $oppData = $validated['opportunity'];
            $oppCode = SequenceService::nextCode($tenantId, 'opportunity');
            $opportunity = \App\Models\Opportunity::create([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'code' => $oppCode,
                'client_id' => $client->id,
                'title' => $oppData['title'],
                'stage' => 'descubrimiento',
                'amount' => $oppData['amount'],
                'currency' => $oppData['currency'],
                'probability' => 35,
                'expected_close_date' => $oppData['expected_close_date'],
                'owner_id' => $lead->owner_id ?? $user->id,
                'destination_label' => $lead->destination_label,
                'pax' => $lead->pax,
                'lead_id' => $lead->id,
                'origin' => 'Conversión de lead',
            ]);

            // 3. Crear reunión opcional si fue solicitada
            if (!empty($validated['schedule_meeting']) && !empty($validated['meeting_due_at'])) {
                $actCode = SequenceService::nextCode($tenantId, 'activity');
                \App\Models\Activity::create([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => $actCode,
                    'type' => 'reunion',
                    'subject' => "Reunión de descubrimiento — {$client->name}",
                    'description' => "Reunión agendada automáticamente tras conversión de {$lead->code}.",
                    'related_type' => 'opportunity',
                    'related_id' => $opportunity->id,
                    'owner_id' => $opportunity->owner_id,
                    'due_at' => $validated['meeting_due_at'],
                    'status' => 'pendiente',
                ]);
            }

            // 4. Actualizar Lead a "convertido" conservando historial (CRM-BR-19)
            $lead->update([
                'status' => 'convertido',
                'converted_client_id' => $client->id,
                'converted_opportunity_id' => $opportunity->id,
                'converted_at' => now(),
                'converted_by' => $user->id,
            ]);

            return response()->json([
                'message' => "Lead {$lead->code} convertido exitosamente.",
                'client' => $client,
                'opportunity' => $opportunity,
            ]);
        });
    }
}