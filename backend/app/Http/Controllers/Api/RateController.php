<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RateController extends Controller
{
    /**
     * GET /api/v1/catalog/rates
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('catalog_rates')
            ->join('catalog_products', 'catalog_rates.product_id', '=', 'catalog_products.id')
            ->where('catalog_rates.tenant_id', $tenantId)
            ->select(
                'catalog_rates.id',
                'catalog_rates.ref',
                'catalog_rates.channel',
                'catalog_rates.rate_type',
                'catalog_rates.segment',
                'catalog_rates.currency',
                'catalog_rates.cost_amount as cost',
                'catalog_rates.price_amount as price',
                'catalog_rates.margin_pct',
                'catalog_rates.valid_from',
                'catalog_rates.valid_to',
                'catalog_rates.is_active',
                'catalog_products.id as product_id',
                'catalog_products.name as product_name',
                'catalog_products.code as product_code'
            )
            ->orderBy('catalog_rates.created_at', 'desc');

        if ($request->filled('product_id')) {
            $query->where('catalog_rates.product_id', $request->product_id);
        }

        $rates = $query->get()->map(function ($r) {
            return [
                'id' => $r->id,
                'ref' => $r->ref,
                'channel' => $r->channel,
                'rate_type' => $r->rate_type,
                'segment' => $r->segment,
                'currency' => $r->currency,
                'cost' => (float) $r->cost,
                'price' => (float) $r->price,
                'margin_pct' => (float) $r->margin_pct,
                'valid_from' => $r->valid_from ? substr($r->valid_from, 0, 10) : '2026-01-01',
                'valid_to' => $r->valid_to ? substr($r->valid_to, 0, 10) : '2027-12-31',
                'is_active' => (bool) $r->is_active,
                'product' => [
                    'id' => $r->product_id,
                    'name' => $r->product_name,
                    'code' => $r->product_code,
                ],
            ];
        });

        return response()->json(['data' => $rates]);
    }

    /**
     * POST /api/v1/catalog/rates
     * Guardar nueva tarifa de producto
     */
/**
     * POST /api/v1/catalog/rates
     * Guardar nueva tarifa de producto
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'product_id' => 'required|uuid|exists:catalog_products,id',
            'channel' => 'required|string',
            'rate_type' => 'required|string',
            'segment' => 'required|string',
            'currency' => 'required|string|in:USD,PEN',
            'cost' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0.01',
            'cost_amount' => 'nullable|numeric|min:0',
            'price_amount' => 'nullable|numeric|min:0.01',
            'price_basis' => 'nullable|string',
            'pax_min' => 'nullable|integer',
            'pax_max' => 'nullable|integer',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date',
        ]);

        $cost = (float) ($validated['cost'] ?? $validated['cost_amount'] ?? 0);
        $price = (float) ($validated['price'] ?? $validated['price_amount'] ?? 0);
        $marginPct = $price > 0 ? round((($price - $cost) / $price) * 100, 2) : 0;

        $count = DB::table('catalog_rates')->where('tenant_id', $tenantId)->count() + 1;
        $ref = 'TAR-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        $cost = (float) $validated['cost_amount'];
        $price = (float) $validated['price_amount'];
        $marginPct = $price > 0 ? round((($price - $cost) / $price) * 100, 2) : 0;

        $id = Str::uuid7()->toString();

        DB::table('catalog_rates')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'ref' => $ref,
            'product_id' => $validated['product_id'],
            'channel' => $validated['channel'],
            'rate_type' => $validated['rate_type'],
            'segment' => $validated['segment'],
            'price_basis' => $validated['price_basis'] ?? 'POR_PERSONA',
            'pax_min' => $validated['pax_min'] ?? 1,
            'pax_max' => $validated['pax_max'] ?? 99,
            'currency' => $validated['currency'],
            'cost_amount' => $cost,
            'price_amount' => $price,
            'margin_pct' => $marginPct,
            'valid_from' => $validated['valid_from'] ?? '2026-01-01',
            'valid_to' => $validated['valid_to'] ?? '2027-12-31',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Tarifa registrada exitosamente.',
            'data' => [
                'id' => $id,
                'ref' => $ref,
                'price' => $price,
                'cost' => $cost,
                'margin_pct' => $marginPct,
            ],
        ], 201);
    }
}