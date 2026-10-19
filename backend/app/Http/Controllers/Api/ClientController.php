<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientController extends Controller
{
    /**
     * GET /api/v1/crm/clients
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $query = Client::where('tenant_id', $tenantId)
            ->with(['owner:id,name,code', 'contacts']);

        // Alcance Ventas U03 (§7.6)
        if ($user->code === 'U03') {
            $query->where('owner_id', $user->id);
        }

        if ($request->filled('segment')) {
            $query->where('segment_code', $request->segment);
        }

        if ($request->filled('q')) {
            $search = '%' . $request->q . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', $search)
                  ->orWhere('code', 'ilike', $search)
                  ->orWhere('doc_number', 'ilike', $search)
                  ->orWhere('email', 'ilike', $search)
                  ->orWhere('phone', 'ilike', $search);
            });
        }

        $items = $query->orderBy('created_at', 'desc')->paginate(15);

        // KPIs de Clientes (§6.4)
        $kpiBase = Client::where('tenant_id', $tenantId);
        if ($user->code === 'U03') {
            $kpiBase->where('owner_id', $user->id);
        }

        $kpis = [
            'total_clientes' => (clone $kpiBase)->count(),
            'corporativo' => (clone $kpiBase)->where('segment_code', 'corporativo')->count(),
            'vip_grupos' => (clone $kpiBase)->whereIn('segment_code', ['vip', 'grupos'])->count(),
            'promedio_nps' => round((clone $kpiBase)->whereNotNull('nps_last')->avg('nps_last') ?? 0, 1),
        ];

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
                'kpis' => $kpis,
            ],
        ]);
    }

    /**
     * POST /api/v1/crm/clients
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'kind' => 'required|string|in:persona,empresa',
            'name' => 'required|string|max:200',
            'doc_type' => 'required|string|in:DNI,RUC,PASAPORTE,CE',
            'doc_number' => 'required|string|max:20',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'country' => 'nullable|string|max:80',
            'city' => 'nullable|string|max:80',
            'segment_code' => 'nullable|string',
            'contact_name' => 'nullable|string|max:160',
            'contact_role' => 'nullable|string|max:120',
        ]);

        // Validación CRM-BR-17: al menos correo o teléfono
        if (empty($validated['email']) && empty($validated['phone'])) {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'Registre al menos correo electrónico o teléfono.',
            ], 422);
        }

        // Validación CRM-BR-15 / RT-10: Formato documental peruano
        $doc = trim($validated['doc_number']);
        if ($validated['doc_type'] === 'DNI' && !preg_match('/^\d{8}$/', $doc)) {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'El DNI debe tener exactamente 8 dígitos numéricos.',
            ], 422);
        }

        if ($validated['doc_type'] === 'RUC') {
            if (!preg_match('/^(10|15|17|20)\d{9}$/', $doc)) {
                return response()->json([
                    'error' => 'VALIDATION_FAILED',
                    'message' => 'El RUC debe tener 11 dígitos e iniciar con 10, 15, 17 o 20.',
                ], 422);
            }
        }

        if ($validated['kind'] === 'empresa' && ($validated['country'] ?? 'Perú') === 'Perú' && $validated['doc_type'] !== 'RUC') {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'Toda empresa peruana debe registrarse con RUC.',
            ], 422);
        }

        // Validación CRM-BR-16: Documento único
        $existsDoc = Client::where('tenant_id', $tenantId)
            ->where('doc_type', $validated['doc_type'])
            ->where('doc_number', $doc)
            ->exists();

        if ($existsDoc) {
            return response()->json([
                'error' => 'CONFLICT',
                'message' => 'Ya existe un cliente registrado con ese número de documento.',
            ], 409);
        }

        return DB::transaction(function () use ($tenantId, $user, $validated, $doc) {
            $code = SequenceService::nextCode($tenantId, 'client');

            $client = Client::create([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'code' => $code,
                'kind' => $validated['kind'],
                'name' => $validated['name'],
                'doc_type' => $validated['doc_type'],
                'doc_number' => $doc,
                'email' => $validated['email'] ?? null,
                'email_norm' => !empty($validated['email']) ? strtolower(trim($validated['email'])) : null,
                'phone' => $validated['phone'] ?? null,
                'phone_norm' => !empty($validated['phone']) ? substr(preg_replace('/\D/', '', $validated['phone']), -9) : null,
                'country' => $validated['country'] ?? 'Perú',
                'city' => $validated['city'] ?? null,
                'segment_code' => $validated['segment_code'] ?? 'retail',
                'owner_id' => $user->id,
            ]);

            // Contacto principal (§3.5 / CRM-BR-36)
            $contactName = $validated['contact_name'] ?? $validated['name'];
            ClientContact::create([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'name' => $contactName,
                'role' => $validated['contact_role'] ?? ($validated['kind'] === 'empresa' ? 'Representante' : 'Titular'),
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'is_primary' => true,
            ]);

            return response()->json([
                'message' => 'Cliente registrado exitosamente.',
                'data' => $client->load('contacts'),
            ], 201);
        });
    }
    /**
     * GET /api/v1/crm/clients/{code}
     * Customer 360 con relaciones consolidadas
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $client = Client::where('tenant_id', $tenantId)
            ->where('code', $code)
            ->with(['owner:id,name,code', 'contacts'])
            ->firstOrFail();

        // Regla SEC-BR-09 / CRM-BR-33: Ventas solo ve su cartera
        if ($user->code === 'U03' && $client->owner_id !== $user->id) {
            return response()->json([
                'error' => 'FORBIDDEN',
                'code' => 'SCOPE_OWNERSHIP',
                'message' => 'Este cliente pertenece a la cartera de otro asesor.',
            ], 403);
        }

        // Oportunidades vinculadas
        $opportunities = \App\Models\Opportunity::where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Actividades vinculadas
        $activities = \App\Models\Activity::where('tenant_id', $tenantId)
            ->where('related_type', 'client')
            ->where('related_id', $client->id)
            ->orderBy('due_at', 'desc')
            ->get();

        // Encuestas NPS
        $surveys = \App\Models\Survey::where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderBy('responded_at', 'desc')
            ->get();

        // Casos de postventa
        $cases = \App\Models\CrmCase::where('tenant_id', $tenantId)
            ->where('client_id', $client->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'client' => $client,
            'opportunities' => $opportunities,
            'activities' => $activities,
            'surveys' => $surveys,
            'cases' => $cases,
        ]);
    }

    /**
     * POST /api/v1/crm/clients/{code}/contacts
     * Añadir contacto al cliente
     */
    public function addContact(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $client = Client::where('tenant_id', $tenantId)->where('code', $code)->firstOrFail();

        if ($user->code === 'U03' && $client->owner_id !== $user->id) {
            return response()->json(['error' => 'FORBIDDEN', 'message' => 'No puede editar un cliente ajeno.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:160',
            'role' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40',
            'is_primary' => 'nullable|boolean',
        ]);

        $contact = \App\Models\ClientContact::create([
            'id' => \Illuminate\Support\Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'name' => $validated['name'],
            'role' => $validated['role'] ?? 'Contacto',
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'is_primary' => !empty($validated['is_primary']),
        ]);

        return response()->json([
            'message' => 'Contacto añadido exitosamente.',
            'contact' => $contact,
        ], 201);
    }
}