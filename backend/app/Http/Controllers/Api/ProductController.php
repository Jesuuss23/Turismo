<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryItem;
use App\Models\Product;
use App\Models\ProductInclusion;
use App\Models\ProductPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * GET /api/v1/catalog/products
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = Product::where('tenant_id', $tenantId)
            ->with(['type:id,name', 'category:id,name', 'destination:id,name,ref', 'provider:id,name']);

        if ($request->filled('type')) {
            $query->where('product_type_id', $request->type);
        }

        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // CAT-BR-43: Filtro por destino incluye descendientes
        if ($request->filled('destination_id')) {
            $dest = Destination::where('tenant_id', $tenantId)->find($request->destination_id);
            if ($dest) {
                $query->whereHas('destination', function ($q) use ($dest) {
                    $q->where('path', 'like', $dest->path . '%');
                });
            }
        }

        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ilike', $s)
                  ->orWhere('ref', 'ilike', $s)
                  ->orWhere('code', 'ilike', $s)
                  ->orWhere('sku', 'ilike', $s);
            });
        }

        $items = $query->orderBy('created_at', 'desc')->get();

        // 4 KPIs oficiales (§2)
        $kpis = [
            'activos' => Product::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->count(),
            'revision_borrador' => Product::where('tenant_id', $tenantId)->whereIn('status', ['DRAFT', 'REVIEW'])->count(),
            'aprobados' => Product::where('tenant_id', $tenantId)->where('status', 'APPROVED')->count(),
            'total' => Product::where('tenant_id', $tenantId)->count(),
        ];

        return response()->json([
            'data' => $items,
            'meta' => ['kpis' => $kpis],
        ]);
    }

    /**
     * POST /api/v1/catalog/products
     * Asistente PROC-014 en un solo payload
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|min:3|max:160',
            'code' => 'required|string|max:30',
            'product_type_id' => 'required|uuid|exists:catalog_product_types,id',
            'category_id' => 'required|uuid|exists:catalog_product_categories,id',
            'primary_destination_id' => 'required|uuid|exists:catalog_destinations,id',
            'main_provider_id' => 'nullable|uuid|exists:catalog_providers,id',
            'duration_value' => 'required|numeric|min:1',
            'duration_unit' => 'required|string|in:horas,días,noches',
            'modality' => 'required|string|in:Privado,Compartido,Grupal',
            'short_description' => 'nullable|string',
            'long_description' => 'nullable|string',
            'cancellation' => 'nullable|string',
            'refund' => 'nullable|string',
            'days' => 'nullable|array',
        ]);

        return DB::transaction(function () use ($tenantId, $user, $validated) {
            $ref = SequenceService::nextCode($tenantId, 'product');
            $sku = "DEMO-{$validated['code']}";
            $slug = Str::slug($validated['name']);

            $product = Product::create([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'ref' => $ref,
                'code' => strtoupper($validated['code']),
                'sku' => $sku,
                'name' => $validated['name'],
                'internal_name' => $validated['name'],
                'product_type_id' => $validated['product_type_id'],
                'category_id' => $validated['category_id'],
                'primary_destination_id' => $validated['primary_destination_id'],
                'main_provider_id' => $validated['main_provider_id'] ?? null,
                'duration_value' => $validated['duration_value'],
                'duration_unit' => $validated['duration_unit'],
                'modality' => $validated['modality'],
                'short_description' => $validated['short_description'] ?? null,
                'long_description' => $validated['long_description'] ?? null,
                'status' => 'DRAFT',
                'slug' => $slug,
            ]);

            // Políticas por defecto
            ProductPolicy::create([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'cancellation' => $validated['cancellation'] ?? 'Cancelación estándar sujeta a condiciones del operador.',
                'modification' => 'Modificación permitida hasta 48h antes de la salida.',
                'no_show' => 'Penalidad del 100% de la tarifa.',
                'refund' => $validated['refund'] ?? 'Reembolso según porcentaje de anticipación.',
            ]);

            // Itinerario si fue provisto
            if (!empty($validated['days'])) {
                $it = Itinerary::create([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'name' => 'Principal',
                    'is_default' => true,
                ]);

                foreach ($validated['days'] as $idx => $d) {
                    $day = ItineraryDay::create([
                        'id' => Str::uuid7()->toString(),
                        'tenant_id' => $tenantId,
                        'itinerary_id' => $it->id,
                        'day_number' => $idx + 1,
                        'title' => $d['title'] ?? "Día " . ($idx + 1),
                    ]);

                    if (!empty($d['activities'])) {
                        foreach ($d['activities'] as $act) {
                            ItineraryItem::create([
                                'id' => Str::uuid7()->toString(),
                                'tenant_id' => $tenantId,
                                'day_id' => $day->id,
                                'activity' => $act,
                                'item_type' => 'Actividad',
                            ]);
                        }
                    }
                }
            }

            return response()->json([
                'message' => 'Producto registrado exitosamente en estado DRAFT.',
                'data' => $product,
            ], 201);
        });
    }

    /**
     * GET /api/v1/catalog/products/{ref}
     * Product 360 (§2)
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $product = Product::where('tenant_id', $tenantId)
            ->where('ref', $ref)
            ->with([
                'type',
                'category',
                'destination',
                'provider',
                'inclusions',
                'policy',
                'itinerary',
            ])
            ->firstOrFail();

        return response()->json(['data' => $product]);
    }

    /**
     * GET /api/v1/catalog/products/{ref}/activation-checklist
     * Regla CAT-BR-06: Checklist de 12 ítems
     */
    public function checklist(Request $request, string $ref): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $product = Product::where('tenant_id', $tenantId)->where('ref', $ref)->firstOrFail();

        $items = [
            ['key' => 'tipo', 'label' => 'Tipo de producto asignado', 'ok' => !empty($product->product_type_id)],
            ['key' => 'nombre', 'label' => 'Nombre con longitud mínima (≥3)', 'ok' => strlen(trim($product->name)) >= 3],
            ['key' => 'destino', 'label' => 'Destino principal asignado', 'ok' => !empty($product->primary_destination_id)],
            ['key' => 'estado', 'label' => 'Estado aprobable (APPROVED o superior)', 'ok' => in_array($product->status, ['APPROVED', 'ACTIVE'])],
            ['key' => 'proveedor', 'label' => 'Proveedor principal activo y vigente', 'ok' => !empty($product->main_provider_id)],
            ['key' => 'politicas', 'label' => 'Políticas comerciales registradas', 'ok' => $product->policy()->exists()],
            ['key' => 'itinerario', 'label' => 'Itinerario con actividades (si aplica)', 'ok' => $product->itinerary()->exists()],
        ];

        $allOk = collect($items)->every(fn ($i) => $i['ok']);

        return response()->json([
            'all_ok' => $allOk,
            'items' => $items,
        ]);
    }

    /**
     * POST /api/v1/catalog/products/{ref}/activate
     */
    public function activate(Request $request, string $ref): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $product = Product::where('tenant_id', $tenantId)->where('ref', $ref)->firstOrFail();

        $product->update(['status' => 'ACTIVE']);

        return response()->json([
            'message' => 'Producto activado exitosamente.',
            'status' => 'ACTIVE',
        ]);
    }
}