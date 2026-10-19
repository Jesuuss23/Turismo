<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Season;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PricingController extends Controller
{
    /**
     * GET /api/v1/catalog/rates
     */
    public function getRates(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $query = Rate::where('tenant_id', $tenantId)->with(['product:id,name,ref,code', 'season:id,name']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->filled('rate_type')) {
            $query->where('rate_type', $request->rate_type);
        }

        if ($user && in_array($user->code, ['U03', 'U04'])) {
            $query->whereNotIn('rate_type', ['Proveedor', 'Costo', 'B2B', 'Mayorista'])
                  ->whereNotIn('channel', ['B2B', 'Mayorista']);
        }

        $rates = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $rates]);
    }

    /**
     * POST /api/v1/catalog/rates
     */
    public function storeRate(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'product_id' => 'required|uuid|exists:catalog_products,id',
            'rate_type' => 'required|string',
            'segment' => 'required|string',
            'channel' => 'required|string',
            'currency' => 'required|string|in:USD,PEN',
            'price_basis' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0.01',
            'cost_amount' => 'nullable|numeric|min:0',
            'price_amount' => 'nullable|numeric|min:0.01',
            'min_price' => 'nullable|numeric',
            'max_price' => 'nullable|numeric',
            'pax_min' => 'nullable|integer',
            'pax_max' => 'nullable|integer',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'season_id' => 'nullable|uuid|exists:catalog_seasons,id',
            'conditions' => 'nullable|string|max:300',
        ]);

        $cost = (float) ($validated['cost'] ?? $validated['cost_amount'] ?? 0);
        $price = (float) ($validated['price'] ?? $validated['price_amount'] ?? 0);

        $ref = SequenceService::nextCode($tenantId, 'rate');

        $rateData = [
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'ref' => $ref,
            'product_id' => $validated['product_id'],
            'rate_type' => $validated['rate_type'],
            'segment' => $validated['segment'],
            'channel' => $validated['channel'],
            'currency' => $validated['currency'],
            'price_basis' => $validated['price_basis'] ?? 'POR_PERSONA',
            'cost' => $cost,
            'price' => $price,
            'cost_amount' => $cost,
            'price_amount' => $price,
            'margin_pct' => $price > 0 ? round((($price - $cost) / $price) * 100, 2) : 0,
            'pax_min' => $validated['pax_min'] ?? 1,
            'pax_max' => $validated['pax_max'] ?? 99,
            'valid_from' => $validated['valid_from'] ?? '2026-01-01',
            'valid_to' => $validated['valid_to'] ?? '2027-12-31',
            'season_id' => $validated['season_id'] ?? null,
            'conditions' => $validated['conditions'] ?? null,
            'status' => 'vigente',
            'is_active' => true,
        ];

        $rate = Rate::create($rateData);

        return response()->json([
            'message' => 'Tarifa comercial registrada exitosamente.',
            'data' => $rate,
        ], 201);
    }

    /**
     * GET /api/v1/catalog/seasons
     */
    public function getSeasons(): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $seasons = Season::where('tenant_id', $tenantId)->orderBy('priority')->get();

        return response()->json(['data' => $seasons]);
    }

    /**
     * POST /api/v1/catalog/seasons
     */
    public function storeSeason(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|string|in:Alta,Media,Baja,Feriado,Fecha especial,Personalizada',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'priority' => 'required|integer|min:0',
            'factor' => 'required|numeric|between:0.01,3.00',
        ]);

        $overlap = Season::where('tenant_id', $tenantId)
            ->where('status', 'activa')
            ->where('priority', $validated['priority'])
            ->where(function ($q) use ($validated) {
                $q->whereBetween('date_from', [$validated['date_from'], $validated['date_to']])
                  ->orWhereBetween('date_to', [$validated['date_from'], $validated['date_to']])
                  ->orWhere(function ($sub) use ($validated) {
                      $sub->where('date_from', '<=', $validated['date_from'])
                          ->where('date_to', '>=', $validated['date_to']);
                  });
            })
            ->exists();

        if ($overlap) {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'message' => 'PROC-019: Ya existe una temporada activa con la misma prioridad en el rango seleccionado. Cambie la prioridad.',
            ], 422);
        }

        $ref = SequenceService::nextCode($tenantId, 'season');

        $season = Season::create(array_merge($validated, [
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'ref' => $ref,
            'status' => 'activa',
        ]));

        return response()->json([
            'message' => 'Temporada registrada exitosamente.',
            'data' => $season,
        ], 201);
    }

    /**
     * GET /api/v1/catalog/price-rules
     */
    public function getPriceRules(): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $rules = PriceRule::where('tenant_id', $tenantId)->orderBy('priority')->get();

        return response()->json(['data' => $rules]);
    }

    /**
     * POST /api/v1/catalog/pricing/simulate
     */
    public function simulate(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'product_id' => 'required|uuid|exists:catalog_products,id',
            'pax' => 'required|integer|min:1',
            'date' => 'required|date',
            'channel' => 'required|string',
            'customer_residency' => 'required|string|in:dom,nodom',
        ]);

        $pax = $validated['pax'];
        $product = Product::where('tenant_id', $tenantId)->findOrFail($validated['product_id']);

        $rate = Rate::where('tenant_id', $tenantId)
            ->where('product_id', $product->id)
            ->where('status', 'vigente')
            ->where('valid_from', '<=', $validated['date'])
            ->where('valid_to', '>=', $validated['date'])
            ->orderBy('price', 'asc')
            ->first();

        $baseUnitCost = $rate ? (float) ($rate->cost ?? $rate->cost_amount ?? 100.00) : 100.00;
        $baseUnitPrice = $rate ? (float) ($rate->price ?? $rate->price_amount ?? 180.00) : 180.00;

        $season = Season::where('tenant_id', $tenantId)
            ->where('status', 'activa')
            ->where('date_from', '<=', $validated['date'])
            ->where('date_to', '>=', $validated['date'])
            ->orderBy('priority', 'asc')
            ->first();

        $seasonFactor = $season ? (float) $season->factor : 1.00;
        $seasonAdjustment = ($baseUnitPrice * $seasonFactor) - $baseUnitPrice;

        $groupDiscountPct = $pax >= 10 ? 5.00 : 0.00;
        $priceAfterSeason = ($baseUnitPrice + $seasonAdjustment);
        $discountAmount = ($priceAfterSeason * ($groupDiscountPct / 100));

        $netBeforeTax = $priceAfterSeason - $discountAmount;

        $taxPct = $validated['customer_residency'] === 'dom' ? 18.00 : 0.00;
        $taxAmount = $netBeforeTax * ($taxPct / 100);

        $finalUnitPrice = round($netBeforeTax + $taxAmount, 2);
        $totalPrice = round($finalUnitPrice * $pax, 2);
        $totalCost = round($baseUnitCost * $pax, 2);
        $totalProfit = round(($netBeforeTax * $pax) - $totalCost, 2);
        $marginPct = $totalPrice > 0 ? round(($totalProfit / ($netBeforeTax * $pax)) * 100, 1) : 0;

        return response()->json([
            'summary' => [
                'pax' => $pax,
                'currency' => $rate ? $rate->currency : 'USD',
                'final_unit_price' => $finalUnitPrice,
                'total_price' => $totalPrice,
                'total_cost' => $totalCost,
                'total_profit' => $totalProfit,
                'margin_pct' => $marginPct,
            ],
            'breakdown' => [
                'base_unit_cost' => $baseUnitCost,
                'base_unit_price' => $baseUnitPrice,
                'season' => $season ? $season->name : 'Regular',
                'season_factor' => $seasonFactor,
                'season_adjustment_per_pax' => round($seasonAdjustment, 2),
                'discount_pct' => $groupDiscountPct,
                'discount_per_pax' => round($discountAmount, 2),
                'net_before_tax' => round($netBeforeTax, 2),
                'tax_pct' => $taxPct,
                'tax_per_pax' => round($taxAmount, 2),
            ],
        ]);
    }
}