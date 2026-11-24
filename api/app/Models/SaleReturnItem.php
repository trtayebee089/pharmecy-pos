<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['restock' => 'boolean', 'unit_refund' => 'decimal:4', 'line_total' => 'decimal:2'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
