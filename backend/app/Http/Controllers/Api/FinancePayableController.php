<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancePayableController extends Controller
{
    /**
     * GET /api/v1/finance/payables
     * Listado de cuentas por pagar a proveedores y costos registrados
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
            ? app('current_tenant_id') 
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));

        $query = DB::table('fin_payables')
            ->join('catalog_providers', 'fin_payables.provider_id', '=', 'catalog_providers.id')
            ->leftJoin('booking_reservations', 'fin_payables.reservation_id', '=', 'booking_reservations.id')
            ->where('fin_payables.tenant_id', $tenantId)
            ->whereNull('fin_payables.deleted_at')
            ->select(
                'fin_payables.*',
                'catalog_providers.name as provider_name',
                'booking_reservations.code as reservation_code',
                'booking_reservations.title as reservation_title'
            )
            ->orderBy('fin_payables.due_date', 'asc');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('fin_payables.status', $request->status);
        }

        if ($request->filled('kind')) {
            $query->where('fin_payables.kind', $request->kind);
        }

        $items = $query->get()->map(function ($p) use ($tenantId) {
            $amount = (float)$p->amount;
            $paid = (float)$p->paid_amount;
            $balance = round($amount - $paid, 2);

            // Verificar si el proveedor subió su factura en la extranet
            $invoice = DB::table('supplier_invoices')
                ->where('tenant_id', $tenantId)
                ->where('payable_id', $p->id)
                ->first();

            return [
                'id' => $p->id,
                'code' => $p->code,
                'concept' => $p->concept,
                'kind' => $p->kind,
                'cost_type' => $p->cost_type,
                'provider_name' => $p->provider_name,
                'reservation_code' => $p->reservation_code,
                'reservation_title' => $p->reservation_title,
                'amount' => $amount,
                'paid_amount' => $paid,
                'balance' => $balance,
                'currency' => $p->currency,
                'due_date' => $p->due_date,
                'status' => $p->status,
                'approved_at' => $p->approved_at,
                'scheduled_date' => $p->scheduled_date,
                'supplier_invoice' => $invoice ? [
                    'number' => $invoice->number,
                    'amount' => (float)$invoice->amount,
                    'status' => $invoice->status,
                ] : null,
            ];
        });

        return response()->json(['data' => $items]);
    }

    /**
     * POST /api/v1/finance/payables/{code}/approve
     * Aprobar obligación de pago a proveedor (PROC-044)
     */
    public function approve(Request $request, string $code): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
            ? app('current_tenant_id') 
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
        $user = $request->user();

        $payable = DB::table('fin_payables')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (!$payable) return response()->json(['message' => 'Obligación no encontrada.'], 404);

        DB::table('fin_payables')->where('id', $payable->id)->update([
            'status' => 'aprobada',
            'approved_at' => now(),
            'approved_by' => $user->id,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Cuenta por pagar {$payable->code} aprobada para programación."]);
    }

    /**
     * POST /api/v1/finance/payables/{code}/schedule
     * Programar fecha y cuenta bancaria de pago (PROC-046)
     */
    public function schedule(Request $request, string $code): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
    ? app('current_tenant_id') 
    : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
        $user = $request->user();

        $validated = $request->validate([
            'scheduled_date' => 'required|date|after_or_equal:today',
            'bank_account_id' => 'required|uuid|exists:fin_bank_accounts,id',
        ]);

        $payable = DB::table('fin_payables')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (!$payable) return response()->json(['message' => 'Obligación no encontrada.'], 404);

        if (!in_array($payable->status, ['aprobada', 'parcial'])) {
            return response()->json(['message' => 'Solo se pueden programar cuentas por pagar aprobadas o con pago parcial.'], 422);
        }

        DB::table('fin_payables')->where('id', $payable->id)->update([
            'scheduled_date' => $validated['scheduled_date'],
            'scheduled_bank_account_id' => $validated['bank_account_id'],
            'scheduled_by' => $user->id,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Pago de {$payable->code} programado para el {$validated['scheduled_date']}."]);
    }

    /**
     * POST /api/v1/finance/payables/{code}/payouts
     * Ejecutar pago a proveedor (Payout)
     */
    public function payout(Request $request, string $code): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
    ? app('current_tenant_id') 
    : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
        $user = $request->user();

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'paid_on' => 'required|date',
            'method' => 'required|string',
            'bank_account_id' => 'nullable|uuid',
            'operation_ref' => 'required|string|max:80',
        ]);
        $bankAccountId = $validated['bank_account_id'] ?? DB::table('fin_bank_accounts')->where('tenant_id', $tenantId)->value('id');
        $payable = DB::table('fin_payables')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (!$payable) return response()->json(['message' => 'Obligación no encontrada.'], 404);

        $balance = round((float)$payable->amount - (float)$payable->paid_amount, 2);
        $amount = (float)$validated['amount'];

        if ($amount > ($balance + 0.005)) {
            return response()->json(['message' => "El importe a pagar ({$amount}) excede el saldo pendiente ({$balance})."], 422);
        }

        return DB::transaction(function () use ($tenantId, $user, $payable, $amount, $balance, $validated) {
            $payoutCode = 'PPR-' . rand(1000, 9999);

            DB::table('fin_payable_payouts')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'code' => $payoutCode,
                'payable_id' => $payable->id,
                'paid_on' => $validated['paid_on'],
                'amount' => $amount,
                'method' => $validated['method'],
                'bank_account_id' => $validated['bank_account_id'],
                'operation_ref' => $validated['operation_ref'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $newPaid = round((float)$payable->paid_amount + $amount, 2);
            $newStatus = ($newPaid >= ((float)$payable->amount - 0.005)) ? 'pagada' : 'parcial';

            DB::table('fin_payables')->where('id', $payable->id)->update([
                'paid_amount' => $newPaid,
                'status' => $newStatus,
                'scheduled_date' => ($newStatus === 'pagada') ? null : $payable->scheduled_date,
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => "Pago {$payoutCode} a proveedor aplicado correctamente.",
                'new_status' => $newStatus,
                'new_balance' => round((float)$payable->amount - $newPaid, 2),
            ]);
        });
    }

    /**
     * POST /api/v1/finance/costs
     * Registrar costo adicional operativo (PROC-045)
     */
    public function storeCost(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
    ? app('current_tenant_id') 
    : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));

        $validated = $request->validate([
            'reservation_id' => 'required|uuid|exists:booking_reservations,id',
            'provider_id' => 'required|uuid|exists:catalog_providers,id',
            'cost_type' => 'required|string',
            'concept' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'due_date' => 'required|date',
            'evidence_note' => 'nullable|string',
        ]);

        $code = 'CXP-' . str_pad((string)rand(100, 99999), 5, '0', STR_PAD_LEFT);

        DB::table('fin_payables')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'provider_id' => $validated['provider_id'],
            'reservation_id' => $validated['reservation_id'],
            'kind' => 'costo_adicional',
            'cost_type' => $validated['cost_type'],
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'currency' => $validated['currency'],
            'paid_amount' => 0.00,
            'due_date' => $validated['due_date'],
            'status' => 'abierta',
            'evidence_note' => $validated['evidence_note'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Costo adicional {$code} registrado. Requiere aprobación para impactar en rentabilidad.",
            'code' => $code,
        ], 201);
    }
}