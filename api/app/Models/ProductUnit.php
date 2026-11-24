<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductUnit extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['factor' => 'integer', 'is_base' => 'boolean', 'is_sale_default' => 'boolean', 'is_purchase_default' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }
}
