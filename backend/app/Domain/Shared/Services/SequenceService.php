<?php

namespace App\Domain\Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SequenceService
{
    public static function nextCode(string $tenantId, string $docType, string $series = 'default'): string
    {
        return DB::transaction(function () use ($tenantId, $docType, $series) {
            $seq = DB::table('sys_sequences')
                ->where('tenant_id', $tenantId)
                ->where('doc_type', $docType)
                ->where('series', $series)
                ->lockForUpdate()
                ->first();

            if (!$seq) {
                // Prefijos por defecto según §3.7
                $prefixMap = [
                    'quote' => 'COT-' . date('Y') . '-',
                    'reservation' => 'RES-' . date('Y') . '-',
                    'lead' => 'LEAD-',
                    'client' => 'CLI-',
                ];

                $prefix = $prefixMap[$docType] ?? strtoupper(substr($docType, 0, 3)) . '-';
                $padWidth = ($docType === 'reservation') ? 6 : 4;

                DB::table('sys_sequences')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'doc_type' => $docType,
                    'label' => ucfirst($docType),
                    'series' => $series,
                    'prefix' => $prefix,
                    'pad_width' => $padWidth,
                    'next_number' => 2,
                    'year_reset' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $prefix . str_pad('1', $padWidth, '0', STR_PAD_LEFT);
            }

            $currentNumber = $seq->next_number;
            DB::table('sys_sequences')
                ->where('id', $seq->id)
                ->update([
                    'next_number' => $currentNumber + 1,
                    'updated_at' => now(),
                ]);

            return $seq->prefix . str_pad((string) $currentNumber, $seq->pad_width, '0', STR_PAD_LEFT);
        });
    }
}
