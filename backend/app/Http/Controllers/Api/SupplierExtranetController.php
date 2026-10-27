<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierExtranetController extends Controller
{
    /**
     * Valida y obtiene el ID del proveedor autenticado
     */
    protected function getProvider(Request $request)
    {
        $user = $request->user();
        if (!$user || empty($user->provider_id)) {
            abort(403, 'Su usuario no está vinculado a un proveedor turístico.');
        }

        $tenantId = app('current_tenant_id') ?? $user->tenant_id;
        $provider = DB::table('catalog_providers')
            ->where('tenant_id', $tenantId)
            ->where('id', $user->provider_id)
            ->first();

        if (!$provider) {
            abort(404, 'No se encontró la ficha del proveedor asociada a su cuenta.');
        }

        return [$tenantId, $provider, $user];
    }

 /**
     * GET /api/v1/supplier/me
     */
    public function me(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'code' => $user->code ?? 'USR',
            ],
            'provider' => [
                'id' => $provider->id,
                'code' => $provider->code ?? $provider->tax_id ?? 'PRV-001',
                'name' => $provider->name ?? 'Proveedor',
                'legal_name' => $provider->legal_name ?? $provider->name ?? 'Proveedor',
                'currency' => $provider->currency ?? 'USD',
                'payment_condition' => $provider->payment_condition ?? '15 días post-servicio',
            ],
        ]);
    }

    /**
     * GET /api/v1/supplier/dashboard
     * 4 KPIs del marco
     */
    public function dashboard(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        // 1. Solicitudes por responder (solicitado o pendiente)
        $pendingRequests = DB::table('booking_services')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->whereIn('status', ['solicitado', 'pendiente'])
            ->count();

        // 2. Llegadas próximos 7 días
        $from = now()->toDateString();
        $to = now()->addDays(7)->toDateString();
        $upcomingArrivals = DB::table('booking_services')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->whereBetween('service_date', [$from, $to])
            ->whereNotIn('status', ['cancelado', 'rechazado'])
            ->count();

        // 3. Saldo por cobrar a la agencia (cálculo tolerante a esquema)
        $pendingBalance = 0;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('fin_payables')) {
                $cols = \Illuminate\Support\Facades\Schema::getColumnListing('fin_payables');
                $paidCol = in_array('paid_amount', $cols) ? 'paid_amount' : (in_array('paid', $cols) ? 'paid' : null);
                
                if ($paidCol) {
                    $pendingBalance = DB::table('fin_payables')
                        ->where('tenant_id', $tenantId)
                        ->where('provider_id', $provider->id)
                        ->where('status', '!=', 'pagada')
                        ->sum(DB::raw("amount - COALESCE({$paidCol}, 0)"));
                } else {
                    $pendingBalance = DB::table('fin_payables')
                        ->where('tenant_id', $tenantId)
                        ->where('provider_id', $provider->id)
                        ->where('status', '!=', 'pagada')
                        ->sum('amount');
                }
            }
        } catch (\Throwable $e) {
            $pendingBalance = 0;
        }

        // 4. Incidencias abiertas
        $openIncidents = 0;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('ops_incidents')) {
                $openIncidents = DB::table('ops_incidents')
                    ->where('tenant_id', $tenantId)
                    ->where('provider_id', $provider->id)
                    ->whereIn('status', ['abierta', 'en_proceso'])
                    ->count();
            }
        } catch (\Throwable $e) {
            $openIncidents = 0;
        }

        return response()->json([
            'pending_requests' => $pendingRequests,
            'upcoming_arrivals' => $upcomingArrivals,
            'pending_balance' => (float)$pendingBalance,
            'currency' => $provider->currency ?? 'USD',
            'open_incidents' => $openIncidents,
        ]);
    }

/**
     * GET /api/v1/supplier/requests
     * Lista de solicitudes para el proveedor (Whitelist: sin precios de venta)
     */
    public function requests(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $query = DB::table('booking_services')
            ->join('booking_reservations', 'booking_services.reservation_id', '=', 'booking_reservations.id')
            ->where('booking_services.tenant_id', $tenantId)
            ->where('booking_services.provider_id', $provider->id)
            ->where('booking_reservations.status', '!=', 'borrador')
            ->select(
                'booking_services.id',
                'booking_services.code as service_code',
                'booking_services.name as service_label',
                'booking_services.service_date',
                'booking_services.service_time',
                'booking_services.pax',
                'booking_services.cost_amount',
                'booking_services.status',
                'booking_services.confirmation_code',
                'booking_reservations.code as reservation_code',
                'booking_reservations.title as reservation_title',
                'booking_reservations.currency'
            )
            ->orderBy('booking_services.service_date', 'asc');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('booking_services.status', $request->status);
        }

        $results = $query->get()->map(function ($s) {
            $pax = $s->pax ?? 1;
            $title = $s->reservation_title ?? 'Sin título';
            $holder = $pax >= 6 ? "Grupo: {$title}" : "Titular: {$title}";

            return [
                'id' => $s->id,
                'service_code' => $s->service_code ?? 'SVC',
                'service_label' => $s->service_label ?? 'Servicio',
                'service_date' => $s->service_date ? substr($s->service_date, 0, 10) : date('Y-m-d'),
                'service_time' => $s->service_time ?? '08:00',
                'holder_label' => $holder,
                'pax' => $pax,
                'unit_cost' => (float)($s->cost_amount ?? 0),
                'currency' => $s->currency ?? 'USD',
                'status' => $s->status ?? 'pendiente',
                'confirmation_code' => $s->confirmation_code ?? null,
                'reservation_code' => $s->reservation_code ?? 'RES',
            ];
        });

        return response()->json(['data' => $results]);
    }

    /**
     * POST /api/v1/supplier/requests/{id}/confirm
     * Confirmar con código >= 4 caracteres (PROC-032 / SUP-BR-06)
     */
    public function confirmRequest(Request $request, string $id): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $validated = $request->validate([
            'confirmation_code' => 'required|string|min:4|max:40',
            'confirmed_by_name' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:500',
        ]);

        $service = DB::table('booking_services')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->where('id', $id)
            ->first();

        if (!$service) {
            return response()->json(['message' => 'La solicitud no corresponde a su establecimiento.'], 404);
        }

        if (in_array($service->status, ['confirmado', 'completado'])) {
            return response()->json(['message' => 'La solicitud ya fue confirmada previamente.'], 409);
        }

        $confCode = strtoupper(trim($validated['confirmation_code']));

        DB::transaction(function () use ($tenantId, $service, $provider, $user, $confCode, $validated) {
            // Actualizar servicio
            DB::table('booking_services')->where('id', $service->id)->update([
                'status' => 'confirmado',
                'confirmation_code' => $confCode,
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

            // Registrar respuesta
            DB::table('supplier_request_responses')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'reservation_service_id' => $service->id,
                'provider_id' => $provider->id,
                'type' => 'confirmacion',
                'responded_by_user_id' => $user->id,
                'responded_at' => now(),
                'confirmed_by_name' => $validated['confirmed_by_name'] ?? $user->name,
                'confirmation_code' => $confCode,
                'note' => $validated['note'] ?? null,
                'review_status' => 'aceptada',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'message' => "Servicio confirmado con código {$confCode}.",
            'status' => 'confirmado',
            'confirmation_code' => $confCode,
        ]);
    }

    /**
     * POST /api/v1/supplier/requests/{id}/reject
     */
    public function rejectRequest(Request $request, string $id): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $validated = $request->validate([
            'reason' => 'required|string|max:60',
            'note' => 'required|string|min:5|max:500',
        ]);

        $service = DB::table('booking_services')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->where('id', $id)
            ->first();

        if (!$service) return response()->json(['message' => 'Solicitud no encontrada.'], 404);

        DB::transaction(function () use ($tenantId, $service, $provider, $user, $validated) {
            DB::table('booking_services')->where('id', $service->id)->update([
                'status' => 'rechazado',
                'updated_at' => now(),
            ]);

            DB::table('supplier_request_responses')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'reservation_service_id' => $service->id,
                'provider_id' => $provider->id,
                'type' => 'rechazo',
                'responded_by_user_id' => $user->id,
                'responded_at' => now(),
                'rejection_reason' => $validated['reason'],
                'note' => $validated['note'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Rechazo registrado con éxito.', 'status' => 'rechazado']);
    }

    /**
     * POST /api/v1/supplier/requests/{id}/alternative
     */
    public function proposeAlternative(Request $request, string $id): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $validated = $request->validate([
            'kind' => 'required|string',
            'date' => 'nullable|date',
            'net_cost' => 'nullable|numeric|min:0.01',
            'hold_until' => 'nullable|date',
            'note' => 'required|string|min:5',
        ]);

        $service = DB::table('booking_services')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->where('id', $id)
            ->first();

        if (!$service) return response()->json(['message' => 'Solicitud no encontrada.'], 404);

        DB::table('supplier_request_responses')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'reservation_service_id' => $service->id,
            'provider_id' => $provider->id,
            'type' => 'alternativa',
            'responded_by_user_id' => $user->id,
            'responded_at' => now(),
            'alt_kind' => $validated['kind'],
            'alt_date' => $validated['date'] ?? null,
            'alt_net_cost' => $validated['net_cost'] ?? null,
            'currency' => $provider->currency ?? 'USD',
            'hold_until' => $validated['hold_until'] ?? null,
            'note' => $validated['note'],
            'review_status' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Alternativa enviada para revisión de Operaciones.']);
    }

    /**
     * GET /api/v1/supplier/vouchers
     */
    public function vouchers(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $vouchers = DB::table('ops_vouchers')
            ->join('booking_reservations', 'ops_vouchers.reservation_id', '=', 'booking_reservations.id')
            ->where('ops_vouchers.tenant_id', $tenantId)
            ->where('ops_vouchers.provider_id', $provider->id)
            ->select(
                'ops_vouchers.*',
                'booking_reservations.code as reservation_code',
                'booking_reservations.title as reservation_title'
            )
            ->orderBy('ops_vouchers.service_date', 'asc')
            ->get();

        return response()->json(['data' => $vouchers]);
    }

    /**
     * POST /api/v1/supplier/vouchers/redeem
     * Canje de voucher en recepción (PROC-035 / SUP-BR-30)
     */
    public function redeemVoucher(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $voucher = DB::table('ops_vouchers')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->where('code', trim($validated['code']))
            ->first();

        if (!$voucher) {
            return response()->json(['message' => 'El código no corresponde a un voucher emitido a su establecimiento.'], 404);
        }

        if ($voucher->status === 'anulado') {
            return response()->json(['message' => 'El voucher ha sido anulado y no es válido.'], 409);
        }

        if ($voucher->status === 'utilizado') {
            return response()->json(['message' => "El voucher ya fue canjeado el {$voucher->used_at}."], 409);
        }

        DB::table('ops_vouchers')->where('id', $voucher->id)->update([
            'status' => 'utilizado',
            'used_at' => now(),
            'used_by' => $user->id,
            'used_via' => 'extranet',
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Voucher {$voucher->code} validado y registrado como utilizado.",
            'service_label' => $voucher->service_label,
            'pax' => $voucher->pax,
        ]);
    }

 /**
     * GET /api/v1/supplier/payables
     * CxP y saldos por cobrar (tolerante si no existe fin_payables)
     */
    public function payables(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        if (!\Illuminate\Support\Facades\Schema::hasTable('fin_payables')) {
            return response()->json(['data' => []]);
        }

        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('fin_payables');
        $paidCol = in_array('paid_amount', $cols) ? 'paid_amount' : (in_array('paid', $cols) ? 'paid' : null);

        $payables = DB::table('fin_payables')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->orderBy('due_date', 'asc')
            ->get()
            ->map(function ($p) use ($paidCol) {
                $amount = (float)($p->amount ?? 0);
                $paid = $paidCol ? (float)($p->{$paidCol} ?? 0) : 0;
                $balance = $amount - $paid;

                return [
                    'id' => $p->id,
                    'code' => $p->code ?? 'CXP',
                    'concept' => $p->concept ?? 'Servicio operativo',
                    'amount' => $amount,
                    'paid_amount' => $paid,
                    'balance' => $balance,
                    'currency' => $p->currency ?? 'USD',
                    'due_date' => isset($p->due_date) ? substr($p->due_date, 0, 10) : date('Y-m-d'),
                    'status' => $p->status ?? 'pendiente',
                ];
            });

        return response()->json(['data' => $payables]);
    }

    /**
     * POST /api/v1/supplier/payables/{id}/invoices
     * Subir factura de proveedor (PROC-044 / SUP-BR-40)
     */
    public function uploadInvoice(Request $request, string $id): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $validated = $request->validate([
            'number' => ['required', 'string', 'regex:/^[A-Z0-9]{4}-\d{1,8}$/i'],
            'issue_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $payable = DB::table('fin_payables')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->where('id', $id)
            ->first();

        if (!$payable) return response()->json(['message' => 'Cuenta por pagar no encontrada.'], 404);

        $invoiceNumber = strtoupper(trim($validated['number']));
        $balance = (float)($payable->amount - $payable->paid_amount);
        $diff = round((float)$validated['amount'] - $balance, 2);

        $invoiceId = Str::uuid7()->toString();
        DB::table('supplier_invoices')->insert([
            'id' => $invoiceId,
            'tenant_id' => $tenantId,
            'payable_id' => $payable->id,
            'provider_id' => $provider->id,
            'number' => $invoiceNumber,
            'issue_date' => $validated['issue_date'],
            'amount' => $validated['amount'],
            'currency' => $payable->currency,
            'amount_difference' => $diff,
            'original_file_name' => 'factura-' . $invoiceNumber . '.pdf',
            'status' => 'registrada',
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Factura {$invoiceNumber} registrada exitosamente.",
            'amount_difference' => $diff,
            'warning' => ($diff != 0) ? 'El importe difiere del saldo; Finanzas revisará la diferencia.' : null,
        ], 201);
    }

     /**
     * GET /api/v1/supplier/rooming-lists
     * Rooming lists asignadas al hotel del proveedor
     */
     public function roomingLists(Request $request): JsonResponse
      {
        [$tenantId, $provider,$user] = $this->getProvider($request);

        if (!\Illuminate\Support\Facades\Schema::hasTable('ops_rooming_lists')) {
            return response()->json(['data' => []]);
        }

        $lists = DB::table('ops_rooming_lists')
            ->join('booking_reservations', 'ops_rooming_lists.reservation_id', '=', 'booking_reservations.id')
            ->where('ops_rooming_lists.tenant_id', $tenantId)
            ->where('ops_rooming_lists.hotel_provider_id', $provider->id)
            ->select(
                'ops_rooming_lists.*',
                'booking_reservations.code as reservation_code',
                'booking_reservations.title as reservation_title'
            )
            ->orderBy('ops_rooming_lists.check_in', 'asc')
            ->get()
            ->map(function ($rl) use ($tenantId) {$rooms = [];
                if (\Illuminate\Support\Facades\Schema::hasTable('ops_rooming_rooms')) {
                    $rooms = DB::table('ops_rooming_rooms')
                        ->where('tenant_id', $tenantId)
                        ->where('rooming_list_id', $rl->id)
                        ->get();
                }
                $rl->rooms =$rooms;
                return $rl;
            });

        return response()->json(['data' => $lists]);
    }

    /**
     * POST /api/v1/supplier/rooming-lists/{id}/acknowledge
     * Acuse de recibo del hotel (PROC-034)
     */
    public function acknowledgeRooming(Request $request, string$id): JsonResponse
    {
        [$tenantId, $provider,$user] = $this->getProvider($request);

        DB::table('ops_rooming_lists')
            ->where('tenant_id', $tenantId)
            ->where('hotel_provider_id', $provider->id)
            ->where('id', $id)
            ->update([
                'hotel_ack_at' => now(),
                'hotel_ack_by' => $user->id,
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Acuse de recibo registrado correctamente.']);
    }

    /**
     * POST /api/v1/supplier/rooming-lists/{id}/confirm
     * Confirmar habitaciones por parte del hotel
     */
    public function confirmRooming(Request $request, string$id): JsonResponse
    {
        [$tenantId, $provider,$user] = $this->getProvider($request);

        DB::transaction(function () use ($tenantId,$provider, $user,$id) {
            DB::table('ops_rooming_lists')
                ->where('tenant_id', $tenantId)
                ->where('hotel_provider_id', $provider->id)
                ->where('id', $id)
                ->update([
                    'hotel_confirmed_at' => now(),
                    'hotel_confirmed_by' => $user->id,
                    'status' => 'confirmada',
                    'updated_at' => now(),
                ]);

            if (\Illuminate\Support\Facades\Schema::hasTable('ops_rooming_rooms')) {
                DB::table('ops_rooming_rooms')
                    ->where('tenant_id', $tenantId)
                    ->where('rooming_list_id', $id)
                    ->where('status', '!=', 'observada')
                    ->update(['status' => 'confirmada', 'updated_at' => now()]);
            }
        });

        return response()->json(['message' => 'Habitaciones confirmadas por el hotel.']);
    }

    /**
     * POST /api/v1/supplier/rooming-rooms/{id}/observations
     * Reportar observación en habitación (genera incidencia automáticamente)
     */
    public function observeRoom(Request $request, string$id): JsonResponse
    {
        [$tenantId, $provider,$user] = $this->getProvider($request);

        $validated =$request->validate([
            'reason' => 'required|string',
            'proposal' => 'required|string',
            'impact' => 'required|string|in:baja,media,alta',
        ]);

        $room = DB::table('ops_rooming_rooms')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (!$room) return response()->json(['message' => 'Habitación no encontrada.'], 404);

        $note = "Observación Hotel: {$validated['reason']}. Propuesta: {$validated['proposal']}";

        DB::transaction(function () use ($tenantId, $provider,$user, $room,$validated, $note) {
            $incCode = 'INC-' . rand(1000, 9999);
            $incId = Str::uuid7()->toString();

            if (\Illuminate\Support\Facades\Schema::hasTable('ops_incidents')) {
                DB::table('ops_incidents')->insert([
                    'id' => $incId,
                    'tenant_id' => $tenantId,
                    'code' => $incCode,
                    'provider_id' => $provider->id,
                    'type' => 'Proveedor',
                    'severity' => $validated['impact'],
                    'title' => "Obs. Habitación {$room->room_number} - {$provider->name}",
                    'description' => $note,
                    'status' => 'abierta',
                    'reported_by' => $user->id,
                    'source' => 'extranet',
                    'sla_hours' => 24,
                    'sla_due_at' => now()->addHours(24),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('ops_rooming_rooms')->where('id', $room->id)->update([
                'status' => 'observada',
                'hotel_note' => $note,
                'incident_id' => $incId,
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Observación reportada e incidencia creada.']);
    }

        /**
     * GET /api/v1/supplier/incidents
     */
    public function incidents(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $incidents = DB::table('ops_incidents')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($provider, $user) {
                $q->where('provider_id', $provider->id)
                  ->orWhere('reported_by', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($i) {
                return [
                    'id' => $i->id,
                    'code' => $i->code ?? 'INC-001',
                    'title' => $i->title ?? 'Incidencia',
                    'description' => $i->description ?? '',
                    'severity' => $i->severity ?? 'media',
                    'type' => $i->type ?? 'Proveedor',
                    'status' => $i->status ?? 'abierta',
                    'sla_hours' => $i->sla_hours ?? 24,
                ];
            });

        return response()->json(['data' => $incidents]);
    }

    /**
     * POST /api/v1/supplier/incidents
     */
    public function reportIncident(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $validated = $request->validate([
            'type' => 'required|string',
            'severity' => 'required|string|in:baja,media,alta,critica',
            'title' => 'required|string|max:200',
            'description' => 'required|string|min:5',
        ]);

        $slaHours = match ($validated['severity']) {
            'critica' => 4,
            'alta' => 8,
            'media' => 24,
            default => 72,
        };

        $incId = \Illuminate\Support\Str::uuid7()->toString();
        $incCode = 'INC-' . rand(1000, 9999);

        DB::table('ops_incidents')->insert([
            'id' => $incId,
            'tenant_id' => $tenantId,
            'code' => $incCode,
            'provider_id' => $provider->id,
            'type' => $validated['type'],
            'severity' => $validated['severity'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'status' => 'abierta',
            'reported_by' => $user->id,
            'source' => 'extranet',
            'sla_hours' => $slaHours,
            'sla_due_at' => now()->addHours($slaHours),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Incidencia {$incCode} reportada exitosamente."]);
    }

    /**
     * GET /api/v1/supplier/contract
     * Ficha de contrato y tarifas
     */
    public function contract(Request $request): JsonResponse
    {
        [$tenantId, $provider, $user] = $this->getProvider($request);

        $services = DB::table('catalog_services')
            ->where('tenant_id', $tenantId)
            ->where('provider_id', $provider->id)
            ->select('id', 'name', 'ref as code', 'currency', 'status', 'cost')
            ->get();

        $rateRequests = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('supplier_rate_requests')) {
            $rateRequests = DB::table('supplier_rate_requests')
                ->where('tenant_id', $tenantId)
                ->where('provider_id', $provider->id)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return response()->json([
            'provider' => $provider,
            'services' => $services,
            'rate_requests' => $rateRequests,
        ]);
    }

    /**
     * POST /api/v1/supplier/rate-requests
     * Solicitud formal de actualización de tarifa (+30 días)
     */
    public function requestRateUpdate(Request $request): JsonResponse
    {
        [$tenantId, $provider,$user] = $this->getProvider($request);

        $validated =$request->validate([
            'service_id' => 'required|uuid',
            'proposed_cost' => 'required|numeric|min:0.01',
            'effective_from' => 'required|date|after_or_equal:' . now()->addDays(30)->toDateString(),
            'reason_type' => 'required|string',
            'reason_detail' => 'nullable|string',
        ]);

        $code = 'SOL-TAR-' . rand(100, 999);

        DB::table('supplier_rate_requests')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'provider_id' => $provider->id,
            'service_id' => $validated['service_id'],
            'current_cost' => 0, // Se complementa con costo previo
            'proposed_cost' => $validated['proposed_cost'],
            'effective_from' => $validated['effective_from'],
            'reason_type' => $validated['reason_type'],
            'reason_detail' => $validated['reason_detail'] ?? null,
            'status' => 'pendiente',
            'requested_by' => $user->id,
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Solicitud {\$code} enviada para revisión de Compras/Operaciones."]);
    }  
}