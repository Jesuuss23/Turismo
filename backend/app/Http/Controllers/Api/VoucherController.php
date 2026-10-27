<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Services\SequenceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    /**
     * GET /api/v1/ops/vouchers
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('ops_vouchers')
            ->join('booking_reservations', 'ops_vouchers.reservation_id', '=', 'booking_reservations.id')
            ->leftJoin('catalog_providers', 'ops_vouchers.provider_id', '=', 'catalog_providers.id')
            ->where('ops_vouchers.tenant_id', $tenantId)
            ->select(
                'ops_vouchers.*',
                'booking_reservations.code as reservation_code',
                'booking_reservations.title as reservation_title',
                'catalog_providers.name as provider_name'
            )
            ->orderBy('ops_vouchers.service_date', 'asc');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('ops_vouchers.status', $request->status);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/v1/ops/vouchers
     * Emitir Voucher de servicio confirmado (PROC-035)
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'reservation_service_id' => 'required|uuid|exists:booking_services,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $service = DB::table('booking_services')->where('tenant_id', $tenantId)->where('id', $validated['reservation_service_id'])->first();

        // OPS-BR-40: Solo servicios confirmados o completados emiten voucher
        if (!in_array($service->status, ['confirmado', 'completado'])) {
            return response()->json([
                'error' => 'OPS-BR-40',
                'message' => "Solo un servicio confirmado por el proveedor puede emitir voucher (estado actual: {$service->status}).",
            ], 422);
        }

        // OPS-BR-41: Un solo voucher vigente por servicio
        $existing = DB::table('ops_vouchers')
            ->where('tenant_id', $tenantId)
            ->where('reservation_service_id', $service->id)
            ->where('status', 'emitido')
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'OPS-BR-41',
                'message' => "El servicio ya tiene un voucher vigente emitido ({$existing->code}).",
            ], 409);
        }

        $res = DB::table('booking_reservations')->where('id', $service->reservation_id)->first();
        $voucherCode = SequenceService::nextCode($tenantId, 'voucher');
        $voucherId = Str::uuid7()->toString();
        $qrToken = hash_hmac('sha256', "{$voucherCode}:{$service->id}", config('app.key', 'secret'));

        DB::table('ops_vouchers')->insert([
            'id' => $voucherId,
            'tenant_id' => $tenantId,
            'code' => $voucherCode,
            'reservation_id' => $service->reservation_id,
            'reservation_service_id' => $service->id,
            'provider_id' => $service->provider_id,
            'service_label' => $service->name,
            'service_date' => $service->service_date,
            'pax' => $service->pax,
            'status' => 'emitido',
            'version' => 1,
            'issued_at' => now(),
            'issued_by' => $user->id,
            'notes' => $validated['notes'] ?? 'Presentar este voucher al momento del abordaje o check-in.',
            'origin' => 'operaciones',
            'qr_token' => $qrToken,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Voucher {$voucherCode} emitido con éxito.",
            'data' => [
                'id' => $voucherId,
                'code' => $voucherCode,
                'qr_token' => $qrToken,
            ],
        ], 201);
    }

    /**
     * POST /api/v1/ops/vouchers/{code}/redeem
     * Marcar voucher como utilizado / canjeado
     */
    public function redeem(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $voucher = DB::table('ops_vouchers')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if (!$voucher) return response()->json(['message' => 'Voucher no encontrado.'], 404);

        if ($voucher->status !== 'emitido') {
            return response()->json(['message' => 'Solo se pueden utilizar vouchers en estado emitido.'], 422);
        }

        DB::table('ops_vouchers')->where('id', $voucher->id)->update([
            'status' => 'utilizado',
            'used_at' => now(),
            'used_by' => $user->id,
            'used_via' => 'web',
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Voucher {$voucher->code} marcado como utilizado."]);
    }

    /**
     * POST /api/v1/ops/vouchers/{code}/void
     * Anular voucher con motivo obligatorio
     */
    public function voidVoucher(Request $request, string $code): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $validated = $request->validate([
            'reason' => 'required|string|min:4',
        ]);

        $voucher = DB::table('ops_vouchers')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if (!$voucher) return response()->json(['message' => 'Voucher no encontrado.'], 404);

        DB::table('ops_vouchers')->where('id', $voucher->id)->update([
            'status' => 'anulado',
            'void_reason' => $validated['reason'],
            'void_at' => now(),
            'void_by' => $user->id,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Voucher {$voucher->code} anulado exitosamente."]);
    }
}