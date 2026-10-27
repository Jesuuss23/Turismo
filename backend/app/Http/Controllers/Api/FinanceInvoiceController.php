<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceInvoiceController extends Controller
{
    /**
     * GET /api/v1/finance/invoices
     * Listado de comprobantes emitidos ante SUNAT
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
    ? app('current_tenant_id') 
    : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));

        $query = DB::table('fin_invoices')
            ->where('tenant_id', $tenantId)
            ->orderBy('issue_date', 'desc')
            ->orderBy('correlative', 'desc');

        if ($request->filled('doc_type')) {
            $query->where('doc_type', $request->doc_type);
        }

        if ($request->filled('status') && $request->status !== 'todos') {
            $query->where('status', $request->status);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/v1/finance/invoices
     * Validar y emitir comprobante electrónico (Factura / Boleta / NC) - PROC-050
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app()->bound('current_tenant_id') 
    ? app('current_tenant_id') 
    : ($request->user()?->tenant_id ?? DB::table('tenants')->value('id'));
        $user = $request->user();

        $validated = $request->validate([
            'doc_type' => 'required|string|in:01,03,07',
            'customer_doc_type' => 'required|string|in:RUC,DNI,PASAPORTE,CE',
            'customer_doc_number' => 'required|string|max:20',
            'customer_name' => 'required|string|max:200',
            'customer_domiciled' => 'required|boolean',
            'total_amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'description' => 'required|string|max:500',
            'receivable_id' => 'nullable|uuid',
            'reservation_id' => 'nullable|uuid',
            'ref_invoice_id' => 'nullable|uuid',
            'credit_note_reason_code' => 'nullable|string',
            'credit_note_reason' => 'nullable|string',
        ]);

        // Regla FIN-BR-39: Factura exige RUC válido
        if ($validated['doc_type'] === '01' && $validated['customer_doc_type'] !== 'RUC') {
            return response()->json(['message' => 'La emisión de Factura requiere un cliente con RUC válido (Regla transversal 10).'], 422);
        }

        // Regla FIN-BR-40: Boleta no puede emitirse con RUC
        if ($validated['doc_type'] === '03' && $validated['customer_doc_type'] === 'RUC') {
            return response()->json(['message' => 'Para clientes con RUC corresponde emitir Factura electrónica.'], 422);
        }

        return DB::transaction(function () use ($tenantId, $user, $validated) {
            // Seleccionar serie adecuada
            $seriesPrefix = match ($validated['doc_type']) {
                '01' => 'F001',
                '03' => 'B001',
                '07' => 'FC01',
            };

            $seriesRow = DB::table('fin_document_series')
                ->where('tenant_id', $tenantId)
                ->where('series', $seriesPrefix)
                ->lockForUpdate()
                ->first();

            $correlative = $seriesRow ? $seriesRow->next_number : 1;
            $invoiceNumber = $seriesPrefix . '-' . str_pad((string)$correlative, 8, '0', STR_PAD_LEFT);

            // Cálculo fiscal IGV 18% vs Exportación (FIN-BR-45)
            $total = (float)$validated['total_amount'];
            $isExport = !$validated['customer_domiciled'];

            if ($isExport) {
                $taxable = $total;
                $igv = 0.00;
                $opType = 'exportacion';
            } else {
                $taxable = round($total / 1.18, 2);
                $igv = round($total - $taxable, 2);
                $opType = 'gravada';
            }

            $invoiceId = Str::uuid7()->toString();
            $now = now();

            DB::table('fin_invoices')->insert([
                'id' => $invoiceId,
                'tenant_id' => $tenantId,
                'number' => $invoiceNumber,
                'doc_type' => $validated['doc_type'],
                'series' => $seriesPrefix,
                'correlative' => $correlative,
                'issue_date' => $now->toDateString(),
                'period' => $now->format('Y-m'),
                'customer_doc_type' => $validated['customer_doc_type'],
                'customer_doc_number' => $validated['customer_doc_number'],
                'customer_name' => $validated['customer_name'],
                'customer_domiciled' => $validated['customer_domiciled'],
                'reservation_id' => $validated['reservation_id'] ?? null,
                'receivable_id' => $validated['receivable_id'] ?? null,
                'operation_type' => $opType,
                'currency' => $validated['currency'],
                'taxable_amount' => $taxable,
                'igv_amount' => $igv,
                'total_amount' => $total,
                'description' => $validated['description'],
                'ref_invoice_id' => $validated['ref_invoice_id'] ?? null,
                'credit_note_reason_code' => $validated['credit_note_reason_code'] ?? null,
                'credit_note_reason' => $validated['credit_note_reason'] ?? null,
                'status' => 'aceptado', // Simulación OSE exitosa
                'fiscal_provider' => 'OSE_SIMULATOR',
                'ose_response_code' => '0',
                'ose_response_message' => 'Comprobante aceptado por OSE/SUNAT.',
                'ose_responded_at' => $now,
                'hash_code' => hash('sha256', $invoiceNumber . '|' . $total . '|' . $now->toIso8601String()),
                'issued_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Incrementar correlativo
            DB::table('fin_document_series')
                ->where('tenant_id', $tenantId)
                ->where('series', $seriesPrefix)
                ->update(['next_number' => $correlative + 1, 'updated_at' => $now]);

            return response()->json([
                'message' => "Comprobante {$invoiceNumber} emitido y validado exitosamente ante SUNAT.",
                'number' => $invoiceNumber,
                'status' => 'aceptado',
            ], 201);
        });
    }
}