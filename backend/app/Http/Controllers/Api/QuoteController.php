<?php

namespace App\Http\Controllers\Api;

use App\Domain\Quotes\Services\QuotePricingService;
use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuoteController extends Controller
{
    /**
     * GET /api/v1/quotes
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $query = DB::table('quote_quotes')
            ->join('crm_clients', 'quote_quotes.client_id', '=', 'crm_clients.id')
            ->leftJoin('quote_versions', 'quote_quotes.current_version_id', '=', 'quote_versions.id')
            ->where('quote_quotes.tenant_id', $tenantId)
            ->select(
                'quote_quotes.*',
                'crm_clients.name as client_name',
                'crm_clients.doc_number as client_doc',
                'quote_versions.grand_total',
                'quote_versions.margin_pct',
                'quote_versions.internal_approval_id'
            )
            ->orderBy('quote_quotes.created_at', 'desc');

        if ($user && in_array($user->role ?? '', ['sales', 'asesor', 'vendedor'])) {
            $query->where('quote_quotes.owner_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('quote_quotes.status', $request->status);
        }

        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $query->where(function ($sub) use ($s) {
                $sub->where('quote_quotes.code', 'ilike', $s)
                    ->orWhere('quote_quotes.title', 'ilike', $s)
                    ->orWhere('crm_clients.name', 'ilike', $s);
            });
        }

        $today = now()->toDateString();
        $quotes = $query->get()->map(function ($q) use ($today) {
            $isExpired = in_array($q->status, ['enviada', 'vista', 'negociacion']) && $q->valid_until < $today;
            return [
                'id' => $q->id,
                'code' => $q->code,
                'title' => $q->title,
                'status' => $isExpired ? 'vencida' : $q->status,
                'client_name' => $q->client_name,
                'current_version_no' => $q->current_version_no,
                'travel_from' => substr($q->travel_from, 0, 10),
                'travel_to' => substr($q->travel_to, 0, 10),
                'valid_until' => substr($q->valid_until, 0, 10),
                'currency' => $q->currency,
                'grand_total' => (float) ($q->grand_total ?? 0),
                'margin_pct' => (float) ($q->margin_pct ?? 0),
                'created_at' => substr($q->created_at, 0, 19),
            ];
        });

        return response()->json(['data' => $quotes]);
    }

    /**
     * GET /api/v1/quotes/kpis
     */
    public function kpis(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();
        $today = now()->toDateString();
        $inThreeDays = now()->addDays(3)->toDateString();

        $base = DB::table('quote_quotes')
            ->leftJoin('quote_versions', 'quote_quotes.current_version_id', '=', 'quote_versions.id')
            ->where('quote_quotes.tenant_id', $tenantId);

        if ($user && in_array($user->role ?? '', ['sales', 'asesor', 'vendedor'])) {
            $base->where('quote_quotes.owner_id', $user->id);
        }

        $abiertas = (clone $base)->whereIn('quote_quotes.status', ['borrador', 'enviada', 'vista', 'negociacion'])->count();

        $montoSeguimiento = (clone $base)
            ->whereIn('quote_quotes.status', ['enviada', 'vista', 'negociacion'])
            ->sum('quote_versions.grand_total') ?? 0;

        $totalCerradas = (clone $base)->whereIn('quote_quotes.status', ['aprobada', 'rechazada', 'convertida'])->count();
        $aprobadas = (clone $base)->whereIn('quote_quotes.status', ['aprobada', 'convertida'])->count();
        $tasaAprobacion = $totalCerradas > 0 ? round(($aprobadas / $totalCerradas) * 100, 1) : 0;

        $porVencer = (clone $base)
            ->whereIn('quote_quotes.status', ['enviada', 'vista', 'negociacion'])
            ->where('quote_quotes.valid_until', '>=', $today)
            ->where('quote_quotes.valid_until', '<=', $inThreeDays)
            ->count();

        $pendientesGerencia = DB::table('quote_internal_approvals')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pendiente')
            ->count();

        return response()->json([
            'abiertas' => $abiertas,
            'monto_seguimiento' => (float) $montoSeguimiento,
            'tasa_aprobacion' => $tasaAprobacion,
            'por_vencer' => $porVencer,
            'pendientes_gerencia' => $pendientesGerencia,
        ]);
    }

    /**
     * POST /api/v1/quotes
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'client_id' => 'required|uuid|exists:crm_clients,id',
            'opportunity_id' => 'nullable|uuid|exists:crm_opportunities,id',
            'title' => 'required|string|max:200',
            'travel_from' => 'required|date',
            'travel_to' => 'required|date|after_or_equal:travel_from',
            'valid_until' => 'required|date',
            'currency' => 'required|string|in:USD,PEN',
            'pax_adt' => 'required|integer|min:0',
            'pax_chd' => 'nullable|integer|min:0',
            'pax_inf' => 'nullable|integer|min:0',
            'discount_pct' => 'nullable|numeric|min:0|max:50',
            'tax_pct' => 'nullable|numeric|min:0|max:18',
            'notes' => 'nullable|string',
            'conditions' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:catalog_products,id',
            'items.*.segment' => 'nullable|string',
            'items.*.service_date' => 'required|date',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.pax' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0.01',
            'items.*.rate_id' => 'nullable|uuid',
        ]);

        $discountPct = (float) ($validated['discount_pct'] ?? 0.0);
        $taxPct = (float) ($validated['tax_pct'] ?? 0.0);
        $totals = QuotePricingService::calculateTotals($validated['items'], $discountPct, $taxPct);

        return DB::transaction(function () use ($tenantId, $user, $validated, $totals, $discountPct, $taxPct) {
            $quoteCode = SequenceService::nextCode($tenantId, 'quote');
            $quoteId = Str::uuid7()->toString();
            $versionId = Str::uuid7()->toString();

            $catalogSnapshot = [];
            $productIds = collect($validated['items'])->pluck('product_id')->unique();
            $products = Product::where('tenant_id', $tenantId)->whereIn('id', $productIds)->get()->keyBy('id');

            foreach ($products as $pId => $prod) {
                $catalogSnapshot[$pId] = [
                    'code' => $prod->code,
                    'name' => $prod->name,
                    'type' => $prod->type,
                ];
            }

            DB::table('quote_versions')->insert([
                'id' => $versionId,
                'tenant_id' => $tenantId,
                'quote_id' => $quoteId,
                'version_no' => 1,
                'status' => 'borrador',
                'issued_on' => now()->toDateString(),
                'author_id' => $user->id,
                'discount_pct' => $discountPct,
                'tax_pct' => $taxPct,
                'markup_pct' => $totals['markup_pct'],
                'margin_pct' => $totals['margin_pct'],
                'cost_total' => $totals['cost_total'],
                'sale_total' => $totals['sale_total'],
                'discount_total' => $totals['discount_total'],
                'net_total' => $totals['net_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'profit_total' => $totals['profit_total'],
                'notes' => $validated['notes'] ?? null,
                'conditions' => $validated['conditions'] ?? null,
                'snapshot_label' => "Versión Inicial v1",
                'catalog_snapshot' => json_encode($catalogSnapshot),
                'policies_snapshot' => json_encode([]),
                'fx_rate' => 3.74,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validated['items'] as $index => $item) {
                $qty = (int) $item['qty'];
                $pax = (int) $item['pax'];
                $unitCost = (float) $item['unit_cost'];
                $unitPrice = (float) $item['unit_price'];

                DB::table('quote_items')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'quote_version_id' => $versionId,
                    'line_no' => $index + 1,
                    'product_id' => $item['product_id'],
                    'product_version' => '1.0',
                    'rate_id' => $item['rate_id'] ?? null,
                    'segment' => $item['segment'] ?? 'Adulto',
                    'service_date' => $item['service_date'],
                    'qty' => $qty,
                    'pax' => $pax,
                    'unit_cost' => $unitCost,
                    'unit_price' => $unitPrice,
                    'cost_total' => round($unitCost * $qty * $pax, 2),
                    'sale_total' => round($unitPrice * $qty * $pax, 2),
                    'price_source' => !empty($item['rate_id']) ? 'rate' : 'manual',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($totals['needs_internal_approval']) {
                $approvalId = Str::uuid7()->toString();
                DB::table('quote_internal_approvals')->insert([
                    'id' => $approvalId,
                    'tenant_id' => $tenantId,
                    'quote_version_id' => $versionId,
                    'reasons' => json_encode($totals['approval_reasons']),
                    'over_cap' => $totals['over_cap'],
                    'requested_by' => $user->id,
                    'requested_at' => now(),
                    'request_note' => 'Descuento > 5% o Margen < 15%',
                    'status' => 'pendiente',
                    'is_override' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('quote_versions')->where('id', $versionId)->update([
                    'internal_approval_id' => $approvalId,
                ]);
            }

            DB::table('quote_quotes')->insert([
                'id' => $quoteId,
                'tenant_id' => $tenantId,
                'code' => $quoteCode,
                'client_id' => $validated['client_id'],
                'opportunity_id' => $validated['opportunity_id'] ?? null,
                'owner_id' => $user->id,
                'title' => $validated['title'],
                'status' => 'borrador',
                'current_version_no' => 1,
                'current_version_id' => $versionId,
                'travel_from' => $validated['travel_from'],
                'travel_to' => $validated['travel_to'],
                'valid_until' => $validated['valid_until'],
                'currency' => $validated['currency'],
                'fx_rate' => 3.74,
                'pax_adt' => $validated['pax_adt'],
                'pax_chd' => $validated['pax_chd'] ?? 0,
                'pax_inf' => $validated['pax_inf'] ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => 'Cotización creada exitosamente.',
                'code' => $quoteCode,
                'id' => $quoteId,
            ], 201);
        });
    }

    /**
     * GET /api/v1/quotes/{code}
     */
    public function show(string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('quote_quotes')
            ->join('crm_clients', 'quote_quotes.client_id', '=', 'crm_clients.id')
            ->where('quote_quotes.tenant_id', $tenantId);

        if (Str::isUuid($code)) {
            $query->where('quote_quotes.id', $code);
        } else {
            $query->where('quote_quotes.code', $code);
        }

        $quote = $query->select(
            'quote_quotes.*',
            'crm_clients.name as client_name',
            'crm_clients.email as client_email',
            'crm_clients.phone as client_phone'
        )->first();

        if (!$quote) {
            return response()->json(['message' => 'Cotización no encontrada.'], 404);
        }

        $version = DB::table('quote_versions')
            ->where('id', $quote->current_version_id)
            ->first();

        $items = DB::table('quote_items')
            ->join('catalog_products', 'quote_items.product_id', '=', 'catalog_products.id')
            ->where('quote_items.quote_version_id', $version->id)
            ->select('quote_items.*', 'catalog_products.name as product_name', 'catalog_products.code as product_code')
            ->orderBy('quote_items.line_no', 'asc')
            ->get();

        $internalApproval = null;
        if ($version->internal_approval_id) {
            $internalApproval = DB::table('quote_internal_approvals')
                ->where('id', $version->internal_approval_id)
                ->first();
            if ($internalApproval) {
                $internalApproval->reasons = json_decode($internalApproval->reasons ?? '[]');
            }
        }

        return response()->json([
            'quote' => $quote,
            'version' => $version,
            'items' => $items,
            'internal_approval' => $internalApproval,
        ]);
    }

    /**
     * POST /api/v1/quotes/{code}/send
     */
    public function send(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

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

        $version = DB::table('quote_versions')->where('id', $quote->current_version_id)->first();

        if ($version->internal_approval_id) {
            $appr = DB::table('quote_internal_approvals')->where('id', $version->internal_approval_id)->first();
            if ($appr && $appr->status === 'pendiente') {
                return response()->json([
                    'error' => 'QTE-BR-12',
                    'message' => 'Bloqueado: La cotización requiere aprobación interna de gerencia antes de ser enviada.',
                ], 409);
            }
        }

        DB::transaction(function () use ($tenantId, $user, $quote, $version, $request) {
            DB::table('quote_quotes')->where('id', $quote->id)->update([
                'status' => 'enviada',
                'updated_at' => now(),
            ]);

            DB::table('quote_versions')->where('id', $version->id)->update([
                'status' => 'enviada',
                'frozen_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('quote_sends')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'quote_id' => $quote->id,
                'version_no' => $version->version_no,
                'channel' => $request->input('channel', 'WhatsApp'),
                'recipient' => $request->input('recipient', 'Cliente'),
                'message' => $request->input('message', 'Envío de propuesta comercial'),
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Propuesta enviada con éxito.', 'status' => 'enviada']);
    }

    /**
     * POST /api/v1/quotes/{code}/versions/{v}/internal-approval
     */
    public function decideInternalApproval(Request $request, string $code, string $v): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

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

        $validated = $request->validate([
            'decision' => 'required|in:aprobada,rechazada',
            'note' => 'required|string|min:3',
        ]);

        $version = DB::table('quote_versions')
            ->where('tenant_id', $tenantId)
            ->where('quote_id', $quote->id)
            ->where('version_no', (int) $v)
            ->first();

        if (!$version || !$version->internal_approval_id) {
            return response()->json(['message' => 'No hay solicitud pendiente para esta versión.'], 404);
        }

        DB::table('quote_internal_approvals')
            ->where('id', $version->internal_approval_id)
            ->update([
                'status' => $validated['decision'],
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => $validated['note'],
                'is_override' => $validated['decision'] === 'aprobada',
                'updated_at' => now(),
            ]);

        return response()->json(['message' => "Solicitud interna {$validated['decision']} exitosamente."]);
    }

    /**
     * POST /api/v1/quotes/{code}/approve
     */
    public function approve(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

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

        $validated = $request->validate([
            'approved_by_name' => 'required|string|max:150',
            'channel' => 'required|string',
            'ref' => 'required|string|max:200',
        ]);

        DB::transaction(function () use ($quote, $validated, $user) {
            DB::table('quote_quotes')->where('id', $quote->id)->update([
                'status' => 'aprobada',
                'approved_at' => now(),
                'approved_by_name' => $validated['approved_by_name'],
                'approval_channel' => $validated['channel'],
                'approval_ref' => $validated['ref'],
                'approval_recorded_by' => $user->id,
                'approval_version_no' => $quote->current_version_no,
                'updated_at' => now(),
            ]);

            DB::table('quote_versions')->where('id', $quote->current_version_id)->update([
                'status' => 'aprobada',
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Cotización aprobada por el cliente.', 'status' => 'aprobada']);
    }

    /**
     * POST /api/v1/quotes/{code}/reject
     */
    public function reject(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');

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

        $validated = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        DB::table('quote_quotes')->where('id', $quote->id)->update([
            'status' => 'rechazada',
            'rejected_at' => now(),
            'rejected_reason' => $validated['reason'],
            'updated_at' => now(),
        ]);

        DB::table('quote_versions')->where('id', $quote->current_version_id)->update([
            'status' => 'rechazada',
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Cotización marcada como rechazada.', 'status' => 'rechazada']);
    }

    /**
     * POST /api/v1/quotes/{code}/versions
     */
    public function newVersion(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

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

        if ($quote->status === 'convertida') {
            return response()->json(['message' => 'No se puede crear nueva versión de una cotización ya convertida en reserva.'], 422);
        }

        return DB::transaction(function () use ($tenantId, $user, $quote) {
            $prevVersion = DB::table('quote_versions')->where('id', $quote->current_version_id)->first();
            $newVersionNo = $quote->current_version_no + 1;
            $newVersionId = Str::uuid7()->toString();

            DB::table('quote_versions')->where('id', $prevVersion->id)->update([
                'status' => 'reemplazada',
                'updated_at' => now(),
            ]);

            DB::table('quote_versions')->insert([
                'id' => $newVersionId,
                'tenant_id' => $tenantId,
                'quote_id' => $quote->id,
                'version_no' => $newVersionNo,
                'status' => 'borrador',
                'issued_on' => now()->toDateString(),
                'author_id' => $user->id,
                'discount_pct' => $prevVersion->discount_pct,
                'tax_pct' => $prevVersion->tax_pct,
                'markup_pct' => $prevVersion->markup_pct,
                'margin_pct' => $prevVersion->margin_pct,
                'cost_total' => $prevVersion->cost_total,
                'sale_total' => $prevVersion->sale_total,
                'discount_total' => $prevVersion->discount_total,
                'net_total' => $prevVersion->net_total,
                'tax_total' => $prevVersion->tax_total,
                'grand_total' => $prevVersion->grand_total,
                'profit_total' => $prevVersion->profit_total,
                'notes' => $prevVersion->notes,
                'conditions' => $prevVersion->conditions,
                'snapshot_label' => "Versión v{$newVersionNo}",
                'catalog_snapshot' => $prevVersion->catalog_snapshot,
                'policies_snapshot' => $prevVersion->policies_snapshot,
                'fx_rate' => $prevVersion->fx_rate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $items = DB::table('quote_items')->where('quote_version_id', $prevVersion->id)->get();
            foreach ($items as $it) {
                DB::table('quote_items')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'quote_version_id' => $newVersionId,
                    'line_no' => $it->line_no,
                    'product_id' => $it->product_id,
                    'product_version' => $it->product_version,
                    'rate_id' => $it->rate_id,
                    'segment' => $it->segment,
                    'service_date' => $it->service_date,
                    'qty' => $it->qty,
                    'pax' => $it->pax,
                    'unit_cost' => $it->unit_cost,
                    'unit_price' => $it->unit_price,
                    'cost_total' => $it->cost_total,
                    'sale_total' => $it->sale_total,
                    'price_source' => $it->price_source,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $newValidUntil = now()->addDays(10)->toDateString();
            DB::table('quote_quotes')->where('id', $quote->id)->update([
                'status' => 'borrador',
                'current_version_no' => $newVersionNo,
                'current_version_id' => $newVersionId,
                'valid_until' => $newValidUntil,
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => "Versión v{$newVersionNo} creada exitosamente en borrador.",
                'version_no' => $newVersionNo,
            ], 201);
        });
    }
}