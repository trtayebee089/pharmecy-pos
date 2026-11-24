<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['qty_base' => 'integer', 'avg_unit_cost' => 'decimal:4'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
