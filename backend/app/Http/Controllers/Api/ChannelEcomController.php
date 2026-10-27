<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChannelEcomController extends Controller
{
    private function getTenantId(Request $request): string
    {
        return app()->bound('current_tenant_id')
            ? app('current_tenant_id')
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
    }

    /**
     * GET /api/v1/channels/ecommerce/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $orders = DB::table('channel_ecom_orders')->where('tenant_id', $tenantId)->get();
        $totalOrders = $orders->count();
        $confirmedOrders = $orders->where('status', 'confirmado')->count();
        $totalRevenue = $orders->where('status', 'confirmado')->sum('total');

        return response()->json([
            'total_orders' => $totalOrders,
            'confirmed_orders' => $confirmedOrders,
            'conversion_rate' => $totalOrders > 0 ? round(($confirmedOrders / $totalOrders) * 100, 1) : 0,
            'total_revenue' => round((float)$totalRevenue, 2),
            'currency' => 'USD',
        ]);
    }

    /**
     * GET /api/v1/channels/ecommerce/orders
     */
    public function orders(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $query = DB::table('channel_ecom_orders')
            ->join('catalog_products', 'channel_ecom_orders.product_id', '=', 'catalog_products.id')
            ->where('channel_ecom_orders.tenant_id', $tenantId)
            ->select(
                'channel_ecom_orders.*',
                'catalog_products.name as product_name'
            )
            ->orderBy('channel_ecom_orders.ordered_at', 'desc');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('channel_ecom_orders.status', $request->status);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/v1/channels/ecommerce/orders/{code}/cancel
     */
    public function cancelOrder(Request $request, string $code): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $order = DB::table('channel_ecom_orders')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (!$order) return response()->json(['message' => 'Pedido no encontrado.'], 404);

        if (!in_array($order->status, ['pago_pendiente', 'pago_rechazado'])) {
            return response()->json(['message' => 'Solo se pueden anular pedidos pendientes de pago o rechazados.'], 409);
        }

        DB::table('channel_ecom_orders')
            ->where('id', $order->id)
            ->update([
                'status' => 'cancelado',
                'cancel_reason' => $request->input('reason', 'Anulado por gestión administrativa'),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => "Pedido {$code} anulado exitosamente."]);
    }
}