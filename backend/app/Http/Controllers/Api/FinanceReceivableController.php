<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceReceivableController extends Controller
{
    /**
     * GET /api/v1/finance/receivables
     * Listado filtrable de CxC con saldos calculados (PROC-040)
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
        ? app('current_tenant_id') 
        : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));

        $query = DB::table('fin_receivables')
            ->join('crm_clients', 'fin_receivables.client_id', '=', 'crm_clients.id')
            ->join('booking_reservations', 'fin_receivables.reservation_id', '=', 'booking_reservations.id')
            ->where('fin_receivables.tenant_id', $tenantId)
            ->whereNull('fin_receivables.deleted_at')
            ->select(
                'fin_receivables.*',
                'crm_clients.name as client_name',
                'booking_reservations.code as reservation_code',
                'booking_reservations.title as reservation_title'
            )
            ->orderBy('fin_receivables.due_date', 'asc');

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('fin_receivables.status', $request->status);
        }

        if ($request->filled('currency')) {
            $query->where('fin_receivables.currency', $request->currency);
        }

        $today = now()->toDateString();
        $items = $query->get()->map(function ($r) use ($today) {
            $amount = (float)$r->amount;
            $paid = (float)$r->paid_amount;
            $balance = round($amount - $paid, 2);

            // Estado dinámico si está vencida
            $status = $r->status;
            if ($status === 'abierta' && $r->due_date < $today && $balance > 0) {
                $status = 'vencida';
            }

            return [
                'id' => $r->id,
                'code' => $r->code,
                'concept' => $r->concept,
                'kind' => $r->kind,
                'client_name' => $r->client_name,
                'reservation_code' => $r->reservation_code,
                'reservation_title' => $r->reservation_title,
                'amount' => $amount,
                'paid_amount' => $paid,
                'balance' => $balance,
                'currency' => $r->currency,
                'due_date' => $r->due_date,
                'status' => $status,
                'last_reminder_at' => $r->last_reminder_at,
            ];
        });

        return response()->json(['data' => $items]);
    }

    /**
     * GET /api/v1/finance/receivables/summary
     * KPIs y resumen de antigüedad de saldos (Aging)
     */
    public function summary(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
            ? app('current_tenant_id') 
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
        $today = now()->toDateString();

        $cxc = DB::table('fin_receivables')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['pagada', 'cancelada'])
            ->get();

        $totalOpen = 0;
        $totalOverdue = 0;
        $overdueCount = 0;

        // Tramos de Aging
        $aging = [
            'por_vencer' => 0,
            'dias_1_30' => 0,
            'dias_31_60' => 0,
            'dias_61_90' => 0,
            'mas_90' => 0,
        ];

        foreach ($cxc as $r) {
            $balance = (float)$r->amount - (float)$r->paid_amount;
            $totalOpen += $balance;

            if ($r->due_date < $today) {
                $totalOverdue += $balance;
                $overdueCount++;

                $diffDays = now()->diffInDays(\Carbon\Carbon::parse($r->due_date));
                if ($diffDays <= 30) {
                    $aging['dias_1_30'] += $balance;
                } elseif ($diffDays <= 60) {
                    $aging['dias_31_60'] += $balance;
                } elseif ($diffDays <= 90) {
                    $aging['dias_61_90'] += $balance;
                } else {
                    $aging['mas_90'] += $balance;
                }
            } else {
                $aging['por_vencer'] += $balance;
            }
        }

        return response()->json([
            'total_open' => round($totalOpen, 2),
            'total_overdue' => round($totalOverdue, 2),
            'overdue_count' => $overdueCount,
            'aging' => $aging,
            'currency' => 'USD',
        ]);
    }

    /**
     * POST /api/v1/finance/receivables/advances
     * Solicitar anticipo vinculado a reserva (PROC-041 / FIN-BR-02..04)
     */
    public function requestAdvance(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
            ? app('current_tenant_id') 
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));

        $validated = $request->validate([
            'reservation_id' => 'required|uuid|exists:booking_reservations,id',
            'amount' => 'required|numeric|min:0.01',
            'due_date' => 'required|date',
            'concept' => 'required|string|max:255',
            'notify' => 'nullable|boolean',
        ]);

        $reservation = DB::table('booking_reservations')
            ->where('tenant_id', $tenantId)
            ->where('id', $validated['reservation_id'])
            ->first();

        if (in_array($reservation->status, ['cancelada', 'completada'])) {
            return response()->json(['message' => 'No se puede solicitar anticipos sobre reservas canceladas o completadas.'], 422);
        }

        // Validar saldo no comprometido (FIN-BR-03)
        $committed = DB::table('fin_receivables')
            ->where('tenant_id', $tenantId)
            ->where('reservation_id', $reservation->id)
            ->where('status', '!=', 'cancelada')
            ->sum('amount');

        $available = (float)$reservation->total_amount - (float)$committed;
        if ((float)$validated['amount'] > ($available + 0.005)) {
            return response()->json([
                'message' => "El anticipo excede el saldo no comprometido de la reserva (Disponible: {$reservation->currency} {$available})."
            ], 422);
        }

        $code = 'CXC-' . str_pad((string)rand(100, 99999), 5, '0', STR_PAD_LEFT);

        DB::table('fin_receivables')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'reservation_id' => $reservation->id,
            'client_id' => $reservation->client_id,
            'kind' => 'anticipo',
            'concept' => $validated['concept'],
            'amount' => $validated['amount'],
            'currency' => $reservation->currency,
            'paid_amount' => 0.00,
            'due_date' => $validated['due_date'],
            'status' => 'abierta',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => "Anticipo {$code} generado exitosamente.",
            'code' => $code,
        ], 201);
    }

    /**
     * POST /api/v1/finance/receivables/{code}/reminders
     * Enviar recordatorio formal de cobro (FIN-BR-05)
     */
    public function sendReminder(Request $request, string $code): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
            ? app('current_tenant_id') 
            : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
        $user = $request->user();

        $cxc = DB::table('fin_receivables')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (!$cxc) return response()->json(['message' => 'Cuenta por cobrar no encontrada.'], 404);

        if ($cxc->status === 'pagada') {
            return response()->json(['message' => 'La obligación ya se encuentra pagada.'], 422);
        }

        $balance = (float)$cxc->amount - (float)$cxc->paid_amount;

        DB::table('fin_receivable_reminders')->insert([
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $tenantId,
            'receivable_id' => $cxc->id,
            'channels' => 'email',
            'sent_by' => $user->id,
            'sent_at' => now(),
            'balance_at_send' => $balance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fin_receivables')->where('id', $cxc->id)->update([
            'last_reminder_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => "Recordatorio de cobro enviado por {$cxc->currency} {$balance}."]);
    }
}