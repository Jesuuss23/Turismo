<?php

namespace App\Http\Controllers\Api;

use App\Domain\Quotes\Services\QuotePricingService;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Season;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotePricingPreviewController extends Controller
{
    /**
     * POST /api/v1/quotes/pricing/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.pax' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'discount_pct' => 'nullable|numeric|min:0|max:100',
            'tax_pct' => 'nullable|numeric|min:0|max:100',
            'currency' => 'nullable|string|in:USD,PEN',
        ]);

        $discountPct = (float) ($validated['discount_pct'] ?? 0.00);
        $taxPct = (float) ($validated['tax_pct'] ?? 0.00);

        $result = QuotePricingService::calculateTotals(
            $validated['items'],
            $discountPct,
            $taxPct
        );

        return response()->json($result);
    }

    /**
     * GET /api/v1/catalog/products/{id}/rate-suggestion
     */
    public function suggestRate(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $serviceDate = $request->query('date', now()->toDateString());
        $currency = $request->query('currency', 'USD');
        $segment = $request->query('segment', 'Adulto');

        // 1. Obtener producto
        $product = Product::where('tenant_id', $tenantId)
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('ref', $id);
            })
            ->first();

        if (!$product) {
            return response()->json([
                'has_rate' => false,
                'message' => 'Producto no encontrado.',
            ], 404);
        }

        // 2. Query de tarifas: usando 'status' en lugar de 'is_active'
        $rateQuery = Rate::where('tenant_id', $tenantId)
            ->where('product_id', $product->id)
            ->where('currency', $currency)
            ->where(function ($q) {
                $q->whereNull('status')
                  ->orWhere('status', 'vigente')
                  ->orWhere('status', 'activa')
                  ->orWhere('status', 'active');
            })
            ->where(function ($q) use ($serviceDate) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $serviceDate);
            })
            ->where(function ($q) use ($serviceDate) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $serviceDate);
            });

        // Buscar primero por el segmento solicitado (Adulto, Niño, etc.)
        $rate = (clone $rateQuery)->where('segment', $segment)->orderBy('cost', 'asc')->first()
            ?? $rateQuery->orderBy('cost', 'asc')->first();

        if (!$rate) {
            return response()->json([
                'has_rate' => false,
                'message' => 'No hay tarifa vigente para este segmento y fecha.',
            ]);
        }

        // 3. Temporada aplicable
        $season = Season::where('tenant_id', $tenantId)
            ->where('date_from', '<=', $serviceDate)
            ->where('date_to', '>=', $serviceDate)
            ->orderBy('priority', 'asc')
            ->first();

        $factor = $season ? (float) $season->factor : 1.0;

        $baseCost = (float) ($rate->cost ?? $rate->cost_amount ?? 0);
        $basePrice = (float) ($rate->price ?? $rate->price_amount ?? 0);

        return response()->json([
            'has_rate' => true,
            'rate_id' => $rate->id,
            'rate_ref' => $rate->ref,
            'segment' => $rate->segment,
            'unit_cost' => round($baseCost * $factor, 2),
            'unit_price' => round($basePrice * $factor, 2),
            'season_factor' => $factor,
            'season_name' => $season ? $season->name : 'Regular',
        ]);
    }
}