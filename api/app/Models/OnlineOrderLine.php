<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlineOrderLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['qty' => 'decimal:3', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}
