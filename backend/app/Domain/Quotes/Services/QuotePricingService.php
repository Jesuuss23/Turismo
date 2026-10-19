<?php

namespace App\Domain\Quotes\Services;

use App\Models\Client;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Season;

class QuotePricingService
{
    /**
     * Calcula los totales oficiales de la cotización (PRC-04)
     */
    public static function calculateTotals(array $items, float $discountPct = 0.0, float $taxPct = 0.0): array
    {
        $costSum = 0.0;
        $saleSum = 0.0;

        foreach ($items as $it) {
            $qty = (int) ($it['qty'] ?? 1);
            $pax = (int) ($it['pax'] ?? 1);
            $unitCost = (float) ($it['unit_cost'] ?? 0.0);
            $unitPrice = (float) ($it['unit_price'] ?? 0.0);

            $costSum += $unitCost * $qty * $pax;
            $saleSum += $unitPrice * $qty * $pax;
        }

        // Descuento global sobre la venta bruta
        $discountAmount = round($saleSum * ($discountPct / 100.0), 2);
        $netTotal = round($saleSum - $discountAmount, 2);

        // Impuesto (IGV) sobre el valor de venta neto
        $taxAmount = round($netTotal * ($taxPct / 100.0), 2);
        $grandTotal = round($netTotal + $taxAmount, 2);

        // Utilidad y Margen Comercial sobre neto (sin IGV)
        $costTotal = round($costSum, 2);
        $profitTotal = round($netTotal - $costTotal, 2);

        $marginPct = $netTotal > 0 ? round(($profitTotal / $netTotal) * 100.0, 2) : 0.0;
        $markupPct = $costTotal > 0 ? round((($saleSum / $costTotal) - 1.0) * 100.0, 2) : 0.0;

        // Evaluar políticas de aprobación de gerencia (QTE-BR-11 y QTE-BR-08)
        $approvalEval = self::evaluateInternalApproval($discountPct, $marginPct);

        return [
            'cost_total' => number_format($costTotal, 2, '.', ''),
            'sale_total' => number_format($saleSum, 2, '.', ''),
            'discount_total' => number_format($discountAmount, 2, '.', ''),
            'net_total' => number_format($netTotal, 2, '.', ''),
            'tax_total' => number_format($taxAmount, 2, '.', ''),
            'grand_total' => number_format($grandTotal, 2, '.', ''),
            'profit_total' => number_format($profitTotal, 2, '.', ''),
            'margin_pct' => $marginPct,
            'markup_pct' => $markupPct,
            'needs_internal_approval' => $approvalEval['needs_approval'],
            'approval_reasons' => $approvalEval['reasons'],
            'over_cap' => $approvalEval['over_cap'],
        ];
    }

    /**
     * Regla QTE-BR-11 y QTE-BR-08:
     * Requiere aprobación si descuento > 5% o margen < 15%. Tope 10% requiere permiso supervisor/override.
     */
    public static function evaluateInternalApproval(float $discountPct, float $marginPct): array
    {
        $reasons = [];
        $overCap = $discountPct > 10.0;

        if ($discountPct > 5.0) {
            $reasons[] = "Descuento {$discountPct}% > 5.0% (requiere autorización comercial)";
        }

        if ($marginPct < 15.0) {
            $reasons[] = "Margen comercial {$marginPct}% < 15.0% (bajo margen mínimo de rentabilidad)";
        }

        return [
            'needs_approval' => count($reasons) > 0,
            'reasons' => $reasons,
            'over_cap' => $overCap,
        ];
    }
}