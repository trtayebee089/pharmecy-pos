<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['qty' => 'decimal:3', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2', 'discount_total' => 'decimal:2', 'cost_total' => 'decimal:2'];

    public function batches(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}
