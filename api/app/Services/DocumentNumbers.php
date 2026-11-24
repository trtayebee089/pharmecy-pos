<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Warehouse;

/**
 * Gap-free document numbers per (type, warehouse, year), e.g. S-DHK1-2026-00001.
 * Must be called inside the document's DB transaction: the sequence row is
 * locked so concurrent counters never get the same number.
 */
class DocumentNumbers
{
    private const PREFIX = ['sale' => 'S', 'purchase' => 'P', 'purchase_order' => 'PO', 'payment' => 'PAY', 'sale_return' => 'R', 'purchase_return' => 'PR'];

    public function next(string $docType, Warehouse $warehouse): string
    {
        $year = (int) now()->format('Y');
        $prefix = (self::PREFIX[$docType] ?? strtoupper($docType)).'-'.$warehouse->code.'-'.$year.'-';

        $seq = DocumentSequence::query()
            ->where(['doc_type' => $docType, 'warehouse_id' => $warehouse->id, 'year' => $year])
            ->lockForUpdate()
            ->first();

        if (! $seq) {
            $seq = DocumentSequence::create([
                'doc_type' => $docType, 'warehouse_id' => $warehouse->id, 'year' => $year,
                'prefix' => $prefix, 'next_number' => 1,
            ]);
        }

        $number = $seq->next_number;
        $seq->increment('next_number');

        return $prefix.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
