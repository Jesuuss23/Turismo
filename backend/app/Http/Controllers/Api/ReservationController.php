<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    /**
     * POST /api/v1/quotes/{code}/convert
     * Conversión idempotente de cotización aprobada a reserva (PROC-025)
     */
    public function convertFromQuote(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        // 1. Buscar cotización
        $query = DB::table('quote_quotes')->where('tenant_id', $tenantId);
        if (Str::isUuid($code)) {
            $query->where('id', $code);
        } else {
            $query->where('code', $code);
        }
        $quote = $query->first();

        if (!$quote) {
            return response()->json(['message' => 'Cotización no encontrada.'], 404);
        }

        // Idempotencia de negocio: Si ya está convertida, devolver la reserva existente (RT-22)
        if ($quote->status === 'convertida' || !empty($quote->reservation_id)) {
            $existing = DB::table('booking_reservations')
                ->where('tenant_id', $tenantId)
                ->where('quote_id', $quote->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => "La cotización ya fue convertida previamente en la reserva {$existing->code}.",
                    'data' => $existing,
                    'is_duplicate' => true,
                ], 200);
            }
        }

        // RT-3: Solo una cotización en estado 'aprobada' puede generar reserva
        if ($quote->status !== 'aprobada') {
            return response()->json([
                'error' => 'RT-3',
                'message' => "Solo una cotización aprobada puede generar reserva (estado actual: {$quote->status}).",
            ], 409);
        }

        $version = DB::table('quote_versions')->where('id', $quote->current_version_id)->first();
        $items = DB::table('quote_items')
            ->join('catalog_products', 'quote_items.product_id', '=', 'catalog_products.id')
            ->where('quote_items.quote_version_id', $version->id)
            ->select('quote_items.*', 'catalog_products.name as product_name')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'La propuesta no tiene ítems de servicios.'], 422);
        }

        return DB::transaction(function () use ($tenantId, $user, $quote, $version, $items) {
            $resId = Str::uuid7()->toString();
            $resCode = SequenceService::nextCode($tenantId, 'reservation');
            $today = now()->toDateString();
            $totalAmount = (float) $version->grand_total;

            // 1. Insertar Cabecera de Reserva (booking_reservations)
            DB::table('booking_reservations')->insert([
                'id' => $resId,
                'tenant_id' => $tenantId,
                'code' => $resCode,
                'quote_id' => $quote->id,
                'quote_version_id' => $version->id,
                'quote_version_no' => $version->version_no,
                'client_id' => $quote->client_id,
                'owner_id' => $quote->owner_id,
                'title' => $quote->title,
                'status' => 'confirmada',
                'channel' => 'Directo',
                'travel_from' => $quote->travel_from,
                'travel_to' => $quote->travel_to,
                'currency' => $quote->currency,
                'fx_rate' => $quote->fx_rate ?? 3.74,
                'total_amount' => $totalAmount,
                'snapshot_label' => "Convertida de {$quote->code} v{$version->version_no}",
                'catalog_snapshot' => $version->catalog_snapshot,
                'policies_snapshot' => $version->policies_snapshot,
                'notes' => "Generada desde {$quote->code} v{$version->version_no}. " . ($version->notes ?? ''),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Fichas de Pasajeros Placeholder (booking_passengers)
            $paxSeq = 1;
            for ($i = 1; $i <= $quote->pax_adt; $i++) {
                DB::table('booking_passengers')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'reservation_id' => $resId,
                    'seq' => $paxSeq++,
                    'full_name' => "Pasajero Adulto {$i} (por completar)",
                    'pax_type' => 'ADT',
                    'is_placeholder' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            for ($i = 1; $i <= $quote->pax_chd; $i++) {
                DB::table('booking_passengers')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'reservation_id' => $resId,
                    'seq' => $paxSeq++,
                    'full_name' => "Pasajero Niño {$i} (por completar)",
                    'pax_type' => 'CHD',
                    'is_placeholder' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            for ($i = 1; $i <= $quote->pax_inf; $i++) {
                DB::table('booking_passengers')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'reservation_id' => $resId,
                    'seq' => $paxSeq++,
                    'full_name' => "Pasajero Infante {$i} (por completar)",
                    'pax_type' => 'INF',
                    'is_placeholder' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 3. Desglose de Servicios Operativos (booking_services)
            foreach ($items as $index => $it) {
                DB::table('booking_services')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'reservation_id' => $resId,
                    'code' => 'RS-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                    'product_id' => $it->product_id,
                    'quote_item_id' => $it->id,
                    'name' => "{$it->product_name} ({$it->segment})",
                    'service_date' => $it->service_date,
                    'pax' => $it->pax,
                    'status' => 'pendiente',
                    'cost_amount' => $it->cost_total,
                    'price_amount' => $it->sale_total,
                    'is_required' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 4. Generación de Cuentas por Cobrar 40/60 (fin_receivables)
            $anticipo = round($totalAmount * 0.40, 2);
            $saldo = round($totalAmount - $anticipo, 2);

            $dueAnticipo = min(
                now()->addDays(3)->toDateString(),
                max($today, $quote->travel_from)
            );
            $travelFromDate = new \DateTime($quote->travel_from);
            $dueSaldo = max($today, $travelFromDate->modify('-15 days')->format('Y-m-d'));

            DB::table('fin_receivables')->insert([
                [
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => SequenceService::nextCode($tenantId, 'receivable'),
                    'reservation_id' => $resId,
                    'client_id' => $quote->client_id,
                    'concept' => "Anticipo 40% — {$quote->title}",
                    'amount' => $anticipo,
                    'currency' => $quote->currency,
                    'due_date' => $dueAnticipo,
                    'paid_amount' => 0,
                    'status' => 'abierta',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => SequenceService::nextCode($tenantId, 'receivable'),
                    'reservation_id' => $resId,
                    'client_id' => $quote->client_id,
                    'concept' => "Saldo 60% — {$quote->title}",
                    'amount' => $saldo,
                    'currency' => $quote->currency,
                    'due_date' => $dueSaldo,
                    'paid_amount' => 0,
                    'status' => 'abierta',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // 5. Actualizar Cotización a 'convertida'
            DB::table('quote_quotes')->where('id', $quote->id)->update([
                'status' => 'convertida',
                'reservation_id' => $resId,
                'converted_at' => now(),
                'lock_version' => $quote->lock_version + 1,
                'updated_at' => now(),
            ]);

            // 6. Actualizar Oportunidad a 'ganada' (100%)
            if (!empty($quote->opportunity_id)) {
                DB::table('crm_opportunities')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $quote->opportunity_id)
                    ->update([
                        'stage' => 'ganada',
                        'probability' => 100,
                        'close_date' => $today,
                        'updated_at' => now(),
                    ]);
            }

            return response()->json([
                'message' => "Cotización convertida exitosamente en la reserva {$resCode}.",
                'data' => [
                    'id' => $resId,
                    'code' => $resCode,
                ],
            ], 201);
        });
    }

    /**
     * GET /api/v1/reservations
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $query = DB::table('booking_reservations')
            ->join('crm_clients', 'booking_reservations.client_id', '=', 'crm_clients.id')
            ->where('booking_reservations.tenant_id', $tenantId)
            ->select(
                'booking_reservations.*',
                'crm_clients.name as client_name',
                'crm_clients.doc_number as client_doc'
            )
            ->orderBy('booking_reservations.created_at', 'desc');

        if ($user && in_array($user->role ?? '', ['sales', 'asesor', 'vendedor'])) {
            $query->where('booking_reservations.owner_id', $user->id);
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('booking_reservations.status', $request->status);
        }

        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $query->where(function ($sub) use ($s) {
                $sub->where('booking_reservations.code', 'ilike', $s)
                    ->orWhere('booking_reservations.title', 'ilike', $s)
                    ->orWhere('crm_clients.name', 'ilike', $s);
            });
        }

        $res = $query->get()->map(function ($r) {
            return [
                'id' => $r->id,
                'code' => $r->code,
                'title' => $r->title,
                'status' => $r->status,
                'client_name' => $r->client_name,
                'channel' => $r->channel,
                'travel_from' => substr($r->travel_from, 0, 10),
                'travel_to' => substr($r->travel_to, 0, 10),
                'currency' => $r->currency,
                'total_amount' => (float) $r->total_amount,
                'created_at' => substr($r->created_at, 0, 19),
            ];
        });

        return response()->json(['data' => $res]);
    }

    /**
     * GET /api/v1/reservations/kpis
     * 4 KPIs oficiales (§2): confirmadas, en operación, pendientes de pago/proveedor, pasajeros en viaje hoy
     */
    public function kpis(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();
        $today = now()->toDateString();

        $base = DB::table('booking_reservations')->where('tenant_id', $tenantId);

        if ($user && in_array($user->role ?? '', ['sales', 'asesor', 'vendedor'])) {
            $base->where('owner_id', $user->id);
        }

        $confirmadas = (clone $base)->where('status', 'confirmada')->count();
        $enOperacion = (clone $base)->where('status', 'en_operacion')->count();
        $pendientes = (clone $base)->whereIn('status', ['pendiente_pago', 'pendiente_proveedor', 'pendiente_disponibilidad'])->count();

        // Pasajeros en viaje hoy
        $viajandoHoy = DB::table('booking_reservations')
            ->join('booking_passengers', 'booking_reservations.id', '=', 'booking_passengers.reservation_id')
            ->where('booking_reservations.tenant_id', $tenantId)
            ->where('booking_reservations.status', '!=', 'cancelada')
            ->where('booking_reservations.travel_from', '<=', $today)
            ->where('booking_reservations.travel_to', '>=', $today)
            ->count();

        return response()->json([
            'confirmadas' => $confirmadas,
            'en_operacion' => $enOperacion,
            'pendientes' => $pendientes,
            'viajando_hoy' => $viajandoHoy,
        ]);
    }

    /**
     * GET /api/v1/reservations/{code}
     * Detalle 360 de la Reserva
     */
    public function show(string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('booking_reservations')
            ->join('crm_clients', 'booking_reservations.client_id', '=', 'crm_clients.id')
            ->where('booking_reservations.tenant_id', $tenantId);

        if (Str::isUuid($code)) {
            $query->where('booking_reservations.id', $code);
        } else {
            $query->where('booking_reservations.code', $code);
        }

        $res = $query->select(
            'booking_reservations.*',
            'crm_clients.name as client_name',
            'crm_clients.email as client_email',
            'crm_clients.phone as client_phone',
            'crm_clients.doc_number as client_doc'
        )->first();

        if (!$res) {
            return response()->json(['message' => 'Reserva no encontrada.'], 404);
        }

        $passengers = DB::table('booking_passengers')
            ->where('reservation_id', $res->id)
            ->orderBy('seq', 'asc')
            ->get();

        $services = DB::table('booking_services')
            ->where('reservation_id', $res->id)
            ->orderBy('service_date', 'asc')
            ->get();

        $receivables = DB::table('fin_receivables')
            ->where('reservation_id', $res->id)
            ->orderBy('due_date', 'asc')
            ->get();

        return response()->json([
            'reservation' => $res,
            'passengers' => $passengers,
            'services' => $services,
            'receivables' => $receivables,
        ]);
    }
    /**
     * PUT /api/v1/reservations/{code}/passengers/{pid}
     * Completa datos nominativos de un pasajero (RES-BR-09)
     */
    public function updatePassenger(Request $request, string $code, string $pid): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'full_name' => 'required|string|max:150',
            'doc_type' => 'required|string|in:DNI,Pasaporte,CE,Passport',
            'doc_number' => 'required|string|max:30',
            'nationality' => 'nullable|string|max:60',
            'birth_date' => 'nullable|date',
            'phone' => 'nullable|string|max:30',
            'diet_notes' => 'nullable|string|max:200',
        ]);

        DB::table('booking_passengers')
            ->where('tenant_id', $tenantId)
            ->where('id', $pid)
            ->update([
                'full_name' => $validated['full_name'],
                'doc_type' => $validated['doc_type'],
                'doc_number' => $validated['doc_number'],
                'nationality' => $validated['nationality'] ?? null,
                'birth_date' => $validated['birth_date'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'diet_notes' => $validated['diet_notes'] ?? null,
                'is_placeholder' => false,
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Datos del pasajero actualizados exitosamente.']);
    }

    /**
     * POST /api/v1/reservations/{code}/services/{sid}/confirm
     * Confirma el servicio con código de confirmación de proveedor (RES-BR-12)
     */
    public function confirmService(Request $request, string $code, string $sid): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'confirmation_code' => 'required|string|max:40',
            'confirmed_by_name' => 'nullable|string|max:120',
            'service_time' => 'nullable',
        ]);

        DB::table('booking_services')
            ->where('tenant_id', $tenantId)
            ->where('id', $sid)
            ->update([
                'confirmation_code' => $validated['confirmation_code'],
                'confirmed_by_name' => $validated['confirmed_by_name'] ?? 'Operaciones',
                'service_time' => $validated['service_time'] ?? null,
                'status' => 'confirmado',
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Servicio operativo confirmado con éxito.']);
    }

    /**
     * POST /api/v1/reservations/{code}/receivables/{rid}/pay
     * Registro rápido de pago aplicado a una CxC (PROC-042 / RES-BR-40)
     */
    public function payReceivable(Request $request, string $code, string $rid): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $cxc = DB::table('fin_receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $rid)
            ->first();

        if (!$cxc) {
            return response()->json(['message' => 'Cuenta por cobrar no encontrada.'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string',
            'reference' => 'nullable|string|max:100',
        ]);

        $paymentAmount = (float) $validated['amount'];
        $newPaidAmount = round((float) $cxc->paid_amount + $paymentAmount, 2);
        $totalAmount = (float) $cxc->amount;
        $status = $newPaidAmount >= ($totalAmount - 0.05) ? 'pagada' : 'parcial';

        DB::transaction(function () use ($tenantId, $user, $cxc, $paymentAmount, $newPaidAmount, $status, $validated) {
            // 1. Actualizar cuenta por cobrar
            DB::table('fin_receivables')
                ->where('id', $cxc->id)
                ->update([
                    'paid_amount' => $newPaidAmount,
                    'status' => $status,
                    'updated_at' => now(),
                ]);

            // 2. Registrar movimiento de pago seguro en fin_payments
            $payCode = 'PAG-' . strtoupper(Str::random(6));
            try {
                if (class_exists(\App\Domain\Shared\Services\SequenceService::class)) {
                    $payCode = \App\Domain\Shared\Services\SequenceService::nextCode($tenantId, 'payment');
                }
            } catch (\Throwable $e) {
                // Fallback de código si la secuencia no está sembrada
            }

            if (\Illuminate\Support\Facades\Schema::hasTable('fin_payments')) {
                DB::table('fin_payments')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => $payCode,
                    'receivable_id' => $cxc->id,
                    'reservation_id' => $cxc->reservation_id,
                    'method' => $validated['method'],
                    'amount' => $paymentAmount,
                    'currency' => $cxc->currency,
                    'paid_on' => now()->toDateString(),
                    'status' => 'aplicado',
                    'reference' => $validated['reference'] ?? 'Cobro aplicado en Reserva 360',
                    'kind' => 'cobro',
                    'registered_by' => $user?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'message' => 'Cobro registrado exitosamente.',
            'status' => $status,
            'paid_amount' => $newPaidAmount,
        ]);
    }
    /**
     * POST /api/v1/reservations
     * Reserva Directa sin cotización (PROC-026)
     */
    public function storeDirect(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'client_id' => 'required|uuid|exists:crm_clients,id',
            'product_id' => 'required|uuid|exists:catalog_products,id',
            'channel' => 'required|string|in:Directo,Corporativo,B2B,E-commerce',
            'service_date' => 'required|date|after_or_equal:today',
            'pax_adt' => 'required|integer|min:1',
            'pax_chd' => 'nullable|integer|min:0',
            'pax_inf' => 'nullable|integer|min:0',
            'unit_price' => 'required|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'currency' => 'required|string|in:USD,PEN',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($tenantId, $user, $validated) {
            $product = DB::table('catalog_products')->where('id', $validated['product_id'])->first();
            $resId = Str::uuid7()->toString();
            $resCode = SequenceService::nextCode($tenantId, 'reservation');
            
            $paxAdt = (int) $validated['pax_adt'];
            $paxChd = (int) ($validated['pax_chd'] ?? 0);
            $paxInf = (int) ($validated['pax_inf'] ?? 0);
            $totalPax = $paxAdt + $paxChd + $paxInf;

            $unitPrice = (float) $validated['unit_price'];
            $unitCost = (float) ($validated['unit_cost'] ?? ($unitPrice * 0.7));
            $totalAmount = round($unitPrice * ($paxAdt + $paxChd), 2); // Infantes sin cargo

            // Fechas estimadas de viaje
            $durationDays = max(1, (int) ($product->duration_value ?? 1));
            $travelFrom = $validated['service_date'];
            $travelTo = date('Y-m-d', strtotime("{$travelFrom} + " . ($durationDays - 1) . " days"));

            // 1. Cabecera
            DB::table('booking_reservations')->insert([
                'id' => $resId,
                'tenant_id' => $tenantId,
                'code' => $resCode,
                'client_id' => $validated['client_id'],
                'owner_id' => $user->id,
                'title' => "{$product->name} (Reserva Directa)",
                'status' => 'confirmada',
                'channel' => $validated['channel'],
                'travel_from' => $travelFrom,
                'travel_to' => $travelTo,
                'currency' => $validated['currency'],
                'fx_rate' => 3.74,
                'total_amount' => $totalAmount,
                'notes' => $validated['notes'] ?? 'Reserva directa creada en mostrador.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Pasajeros placeholder
            $seq = 1;
            for ($i = 1; $i <= $paxAdt; $i++) {
                DB::table('booking_passengers')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'reservation_id' => $resId,
                    'seq' => $seq++,
                    'full_name' => "Pasajero Adulto {$i} (por completar)",
                    'pax_type' => 'ADT',
                    'is_placeholder' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            for ($i = 1; $i <= $paxChd; $i++) {
                DB::table('booking_passengers')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'reservation_id' => $resId,
                    'seq' => $seq++,
                    'full_name' => "Pasajero Niño {$i} (por completar)",
                    'pax_type' => 'CHD',
                    'is_placeholder' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 3. Servicio principal
            DB::table('booking_services')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'reservation_id' => $resId,
                'code' => 'RS-0001',
                'product_id' => $product->id,
                'name' => $product->name,
                'service_date' => $travelFrom,
                'pax' => $totalPax,
                'status' => 'pendiente',
                'cost_amount' => round($unitCost * ($paxAdt + $paxChd), 2),
                'price_amount' => $totalAmount,
                'is_required' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. CxC 40/60
            $anticipo = round($totalAmount * 0.40, 2);
            $saldo = round($totalAmount - $anticipo, 2);
            $today = now()->toDateString();
            $dueSaldo = max($today, date('Y-m-d', strtotime("{$travelFrom} - 15 days")));

            DB::table('fin_receivables')->insert([
                [
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => SequenceService::nextCode($tenantId, 'receivable'),
                    'reservation_id' => $resId,
                    'client_id' => $validated['client_id'],
                    'concept' => "Anticipo 40% — {$product->name}",
                    'amount' => $anticipo,
                    'currency' => $validated['currency'],
                    'due_date' => min(date('Y-m-d', strtotime('+3 days')), $travelFrom),
                    'paid_amount' => 0,
                    'status' => 'abierta',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'code' => SequenceService::nextCode($tenantId, 'receivable'),
                    'reservation_id' => $resId,
                    'client_id' => $validated['client_id'],
                    'concept' => "Saldo 60% — {$product->name}",
                    'amount' => $saldo,
                    'currency' => $validated['currency'],
                    'due_date' => $dueSaldo,
                    'paid_amount' => 0,
                    'status' => 'abierta',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            return response()->json([
                'message' => "Reserva directa {$resCode} creada y confirmada exitosamente.",
                'data' => ['id' => $resId, 'code' => $resCode],
            ], 201);
        });
    }

    /**
     * POST /api/v1/reservations/{code}/start-operation
     */
    public function startOperation(string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('booking_reservations')->where('tenant_id', $tenantId);
        if (Str::isUuid($code)) {
            $query->where('id', $code);
        } else {
            $query->where('code', $code);
        }
        $res = $query->first();

        if (!$res) return response()->json(['message' => 'Reserva no encontrada.'], 404);

        DB::table('booking_reservations')->where('id', $res->id)->update([
            'status' => 'en_operacion',
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Operación de viaje iniciada.', 'status' => 'en_operacion']);
    }

    /**
     * POST /api/v1/reservations/{code}/complete
     */
    public function completeTrip(string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('booking_reservations')->where('tenant_id', $tenantId);
        if (Str::isUuid($code)) {
            $query->where('id', $code);
        } else {
            $query->where('code', $code);
        }
        $res = $query->first();

        if (!$res) return response()->json(['message' => 'Reserva no encontrada.'], 404);

        DB::table('booking_reservations')->where('id', $res->id)->update([
            'status' => 'completada',
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Viaje completado exitosamente.', 'status' => 'completada']);
    }

    /**
     * POST /api/v1/reservations/{code}/cancel
     * Cancelación con cálculo de penalidad (PROC-028)
     */
    public function cancelReservation(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $validated = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $query = DB::table('booking_reservations')->where('tenant_id', $tenantId);
        if (Str::isUuid($code)) {
            $query->where('id', $code);
        } else {
            $query->where('code', $code);
        }
        $res = $query->first();

        if (!$res) return response()->json(['message' => 'Reserva no encontrada.'], 404);

        // Días restantes para el viaje
        $daysUntilTrip = (int) ((strtotime($res->travel_from) - strtotime(now()->toDateString())) / 86400);

        // Regla estándar: si faltan >= 15 días: sin penalidad (0%); 7 a 14 días: 50%; < 7 días: 100%
        $penaltyPct = 100;
        if ($daysUntilTrip >= 15) {
            $penaltyPct = 0;
        } elseif ($daysUntilTrip >= 7) {
            $penaltyPct = 50;
        }

        $total = (float) $res->total_amount;
        $penaltyAmount = round($total * ($penaltyPct / 100.0), 2);
        
        // Pagos realizados
        $paid = (float) DB::table('fin_receivables')
            ->where('tenant_id', $tenantId)
            ->where('reservation_id', $res->id)
            ->sum('paid_amount');

        $refundAmount = max(0.0, round($paid - $penaltyAmount, 2));

        DB::transaction(function () use ($res, $validated, $penaltyAmount, $refundAmount) {
            // Cancelar reserva
            DB::table('booking_reservations')->where('id', $res->id)->update([
                'status' => 'cancelada',
                'cancelled_at' => now(),
                'cancel_reason' => $validated['reason'],
                'penalty_amount' => $penaltyAmount,
                'refund_amount' => $refundAmount,
                'updated_at' => now(),
            ]);

            // Cancelar servicios
            DB::table('booking_services')->where('reservation_id', $res->id)->update([
                'status' => 'cancelado',
                'updated_at' => now(),
            ]);

            // Cancelar CxC abiertas no pagadas
            DB::table('fin_receivables')
                ->where('reservation_id', $res->id)
                ->where('status', '!=', 'pagada')
                ->update([
                    'status' => 'cancelada',
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'message' => 'Reserva cancelada.',
            'penalty_amount' => $penaltyAmount,
            'refund_amount' => $refundAmount,
            'status' => 'cancelada',
        ]);
    }
}