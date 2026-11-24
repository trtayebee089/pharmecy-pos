<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderDraftLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['forecast' => 'array', 'unit_cost' => 'decimal:4'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
