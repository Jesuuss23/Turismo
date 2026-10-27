<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChannelB2BController extends Controller
{
    private function getTenantId(Request $request): string
    {
        if (app()->bound('current_tenant_id')) {
            return (string) app('current_tenant_id');
        }
        return (string) ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
    }

    public function summary(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $today = now()->toDateString();

        $agencies = DB::table('channel_b2b_agencies')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get();

        $activeCount = 0;
        $totalCreditLimit = 0;
        $totalCreditUsed = 0;
        $restrictedCount = 0;

        foreach ($agencies as $ag) {
            $isExpired = $ag->valid_to < $today;
            $isOverCredit = ((float)$ag->credit_limit > 0) && ((float)$ag->credit_used > (float)$ag->credit_limit);

            if ($ag->status === 'activa' && !$isExpired && !$isOverCredit) {
                $activeCount++;
            } else {
                $restrictedCount++;
            }

            $totalCreditLimit += (float)$ag->credit_limit;
            $totalCreditUsed += (float)$ag->credit_used;
        }

        $b2bSales = DB::table('booking_reservations')
            ->where('tenant_id', $tenantId)
            ->where('channel', 'B2B')
            ->whereNotIn('status', ['borrador', 'cancelada'])
            ->sum('total_amount');

        return response()->json([
            'active_agencies' => $activeCount,
            'restricted_agencies' => $restrictedCount,
            'total_credit_limit' => round($totalCreditLimit, 2),
            'total_credit_used' => round($totalCreditUsed, 2),
            'credit_available' => round(max(0, $totalCreditLimit - $totalCreditUsed), 2),
            'b2b_sales_total' => round((float)$b2bSales, 2),
            'currency' => 'USD',
        ]);
    }

    public function agencies(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $today = now()->toDateString();

        $items = DB::table('channel_b2b_agencies')
            ->leftJoin('channel_b2b_price_lists', 'channel_b2b_agencies.price_list_id', '=', 'channel_b2b_price_lists.id')
            ->where('channel_b2b_agencies.tenant_id', $tenantId)
            ->whereNull('channel_b2b_agencies.deleted_at')
            ->select(
                'channel_b2b_agencies.*',
                'channel_b2b_price_lists.name as price_list_name'
            )
            ->orderBy('channel_b2b_agencies.created_at', 'desc')
            ->get()
            ->map(function ($ag) use ($today) {
                $effectiveStatus = $ag->status;
                if ($ag->valid_to < $today) {
                    $effectiveStatus = 'vencido';
                } elseif ((float)$ag->credit_limit > 0 && (float)$ag->credit_used > (float)$ag->credit_limit) {
                    $effectiveStatus = 'bloqueada';
                }

                $availableCredit = max(0, (float)$ag->credit_limit - (float)$ag->credit_used);

                return [
                    'id' => $ag->id,
                    'code' => $ag->code,
                    'legal_name' => $ag->legal_name,
                    'ruc' => $ag->ruc,
                    'contact_name' => $ag->contact_name,
                    'booking_email' => $ag->booking_email,
                    'price_list_name' => $ag->price_list_name ?? 'General',
                    'commission_pct' => (float)$ag->commission_pct,
                    'credit_currency' => $ag->credit_currency,
                    'credit_limit' => (float)$ag->credit_limit,
                    'credit_used' => (float)$ag->credit_used,
                    'credit_available' => round($availableCredit, 2),
                    'valid_from' => $ag->valid_from,
                    'valid_to' => $ag->valid_to,
                    'status' => $ag->status,
                    'effective_status' => $effectiveStatus,
                ];
            });

        return response()->json(['data' => $items]);
    }

    public function storeAgency(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $validated = $request->validate([
            'legal_name' => 'required|string|max:200',
            'ruc' => ['required', 'regex:/^\d{11}$/'],
            'contact_name' => 'required|string|max:150',
            'booking_email' => 'nullable|email|max:190',
            'price_list_id' => 'required|uuid',
            'commission_pct' => 'required|numeric|between:0,30',
            'credit_limit' => 'required|numeric|min:0',
            'credit_currency' => 'required|string|size:3',
            'valid_from' => 'required|date',
            'valid_to' => 'required|date|after_or_equal:valid_from',
        ]);

        $code = 'AGB-' . str_pad((string)(DB::table('channel_b2b_agencies')->where('tenant_id', $tenantId)->count() + 1), 2, '0', STR_PAD_LEFT);

        $id = (string) Str::uuid7();
        DB::table('channel_b2b_agencies')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => $code,
            'legal_name' => $validated['legal_name'],
            'ruc' => $validated['ruc'],
            'contact_name' => $validated['contact_name'],
            'booking_email' => $validated['booking_email'] ?? null,
            'price_list_id' => $validated['price_list_id'],
            'commission_pct' => $validated['commission_pct'],
            'credit_limit' => $validated['credit_limit'],
            'credit_used' => 0.00,
            'credit_currency' => $validated['credit_currency'],
            'valid_from' => $validated['valid_from'],
            'valid_to' => $validated['valid_to'],
            'status' => 'activa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Agencia {$code} registrada exitosamente."], 201);
    }

    public function blockAgency(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        DB::table('channel_b2b_agencies')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'status' => 'bloqueada',
                'block_reason' => 'manual',
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Agencia bloqueada exitosamente.']);
    }

    public function unblockAgency(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);

        $agency = DB::table('channel_b2b_agencies')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (!$agency) return response()->json(['message' => 'Agencia no encontrada.'], 404);

        if ((float)$agency->credit_limit > 0 && (float)$agency->credit_used > (float)$agency->credit_limit) {
            return response()->json([
                'message' => 'No se puede desbloquear: el crédito utilizado supera el límite asignado (B2B-BR-07).'
            ], 409);
        }

        DB::table('channel_b2b_agencies')
            ->where('id', $id)
            ->update([
                'status' => 'activa',
                'block_reason' => null,
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Agencia desbloqueada exitosamente.']);
    }

    public function priceLists(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $lists = DB::table('channel_b2b_price_lists')->where('tenant_id', $tenantId)->get();
        return response()->json(['data' => $lists]);
    }
}
