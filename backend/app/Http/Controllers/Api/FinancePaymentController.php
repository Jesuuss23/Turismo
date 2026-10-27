<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancePaymentController extends Controller
{
    /**
     * GET /api/v1/finance/payments
     * Listado de pagos y cobros
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
            ? app('current_tenant_id') 
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));

        $query = DB::table('fin_payments')
            ->join('booking_reservations', 'fin_payments.reservation_id', '=', 'booking_reservations.id')
            ->leftJoin('fin_receivables', 'fin_payments.receivable_id', '=', 'fin_receivables.id')
            ->where('fin_payments.tenant_id', $tenantId)
            ->select(
                'fin_payments.*',
                'booking_reservations.code as reservation_code',
                'booking_reservations.title as reservation_title',
                'fin_receivables.code as receivable_code'
            )
            ->orderBy('fin_payments.paid_on', 'desc');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('fin_payments.status', $request->status);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/v1/finance/payments
     * Registrar y aplicar pago de forma atómica en Back-Office (PROC-042 / FIN-BR-07..15)
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
            ? app('current_tenant_id') 
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
        $user = $request->user();

        $validated = $request->validate([
            'receivable_id' => 'required|uuid|exists:fin_receivables,id',
            'method' => 'required|string',
            'paid_on' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:500',
        ]);

        $cxc = DB::table('fin_receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $validated['receivable_id'])
            ->first();

        if (in_array($cxc->status, ['pagada', 'cancelada'])) {
            return response()->json(['message' => 'La obligación ya se encuentra liquidada o cancelada.'], 422);
        }

        $balance = round((float)$cxc->amount - (float)$cxc->paid_amount, 2);
        $amount = (float)$validated['amount'];

        if ($amount > ($balance + 0.005)) {
            return response()->json(['message' => "El importe ({$amount}) excede el saldo pendiente ({$balance})."], 422);
        }

        // Validación de referencia requerida (FIN-BR-08)
        $methodConfig = DB::table('fin_payment_methods')
            ->where('tenant_id', $tenantId)
            ->where('code', $validated['method'])
            ->first();

        if ($methodConfig && $methodConfig->requires_reference && empty($validated['reference'])) {
            return response()->json(['message' => 'Este método de pago exige número de operación o referencia.'], 422);
        }

        return DB::transaction(function () use ($tenantId, $user, $cxc, $amount, $balance, $validated) {
            $paymentId = Str::uuid7()->toString();
            $paymentCode = 'PAG-' . str_pad((string)rand(100, 99999), 5, '0', STR_PAD_LEFT);

            // 1. Insertar Pago como Aplicado
            DB::table('fin_payments')->insert([
                'id' => $paymentId,
                'tenant_id' => $tenantId,
                'code' => $paymentCode,
                'reservation_id' => $cxc->reservation_id,
                'receivable_id' => $cxc->id,
                'kind' => 'cobro',
                'source' => 'backoffice',
                'method' => $validated['method'],
                'amount' => $amount,
                'currency' => $cxc->currency,
                'paid_on' => $validated['paid_on'],
                'reference' => $validated['reference'] ?? null,
                'note' => $validated['note'] ?? null,
                'status' => 'aplicado',
                'registered_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Registrar Aplicación
            DB::table('fin_payment_applications')->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenantId,
                'payment_id' => $paymentId,
                'receivable_id' => $cxc->id,
                'amount' => $amount,
                'applied_at' => now(),
                'applied_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Recalcular Saldo y Estado de CxC
            $newPaid = round((float)$cxc->paid_amount + $amount, 2);
            $newStatus = ($newPaid >= ((float)$cxc->amount - 0.005)) ? 'pagada' : 'parcial';

            DB::table('fin_receivables')->where('id', $cxc->id)->update([
                'paid_amount' => $newPaid,
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

            // 4. Sincronizar estado de reserva si todas las CxC quedaron pagadas (FIN-BR-15)
            $unpaidCount = DB::table('fin_receivables')
                ->where('tenant_id', $tenantId)
                ->where('reservation_id', $cxc->reservation_id)
                ->where('status', '!=', 'pagada')
                ->where('status', '!=', 'cancelada')
                ->count();

            if ($unpaidCount === 0) {
                DB::table('booking_reservations')
                    ->where('id', $cxc->reservation_id)
                    ->where('status', 'pendiente_pago')
                    ->update(['status' => 'confirmada', 'updated_at' => now()]);
            }

            return response()->json([
                'message' => "Pago {$paymentCode} registrado y aplicado exitosamente.",
                'payment_code' => $paymentCode,
                'new_status' => $newStatus,
                'new_balance' => round((float)$cxc->amount - $newPaid, 2),
            ], 201);
        });
    }

    /**
     * POST /api/v1/finance/payments/{code}/revert
     * Revertir pago aplicado y recalcular saldo (PROC-043 / FIN-BR-16)
     */
    public function revert(Request $request, string $code): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
            ? app('current_tenant_id') 
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
        $user = $request->user();

        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:255',
        ]);

        $payment = DB::table('fin_payments')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (!$payment) return response()->json(['message' => 'Pago no encontrado.'], 404);

        if ($payment->status === 'revertido') {
            return response()->json(['message' => 'El pago ya se encuentra revertido.'], 409);
        }

        return DB::transaction(function () use ($tenantId, $user, $payment, $validated) {
            // Marcar pago como revertido
            DB::table('fin_payments')->where('id', $payment->id)->update([
                'status' => 'revertido',
                'reverted_at' => now(),
                'reverted_by' => $user->id,
                'revert_reason' => $validated['reason'],
                'updated_at' => now(),
            ]);

            // Desaplicar
            DB::table('fin_payment_applications')
                ->where('payment_id', $payment->id)
                ->update([
                    'reversed_at' => now(),
                    'reversed_by' => $user->id,
                    'updated_at' => now(),
                ]);

            // Recalcular saldo de la CxC
            if ($payment->receivable_id) {
                $cxc = DB::table('fin_receivables')->where('id', $payment->receivable_id)->first();
                if ($cxc) {
                    $activePaid = DB::table('fin_payment_applications')
                        ->where('receivable_id', $cxc->id)
                        ->whereNull('reversed_at')
                        ->sum('amount');

                    $newStatus = ($activePaid <= 0) ? 'abierta' : (($activePaid >= (float)$cxc->amount) ? 'pagada' : 'parcial');
                    if ($newStatus === 'abierta' && $cxc->due_date < now()->toDateString()) {
                        $newStatus = 'vencida';
                    }

                    DB::table('fin_receivables')->where('id', $cxc->id)->update([
                        'paid_amount' => $activePaid,
                        'status' => $newStatus,
                        'updated_at' => now(),
                    ]);
                }
            }

            return response()->json(['message' => "Pago {$payment->code} revertido con éxito."]);
        });
    }
}