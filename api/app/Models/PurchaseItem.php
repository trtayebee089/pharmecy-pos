<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['qty' => 'decimal:3', 'unit_cost' => 'decimal:4', 'discount_pct' => 'decimal:2', 'line_total' => 'decimal:2', 'expiry_date' => 'date'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}
