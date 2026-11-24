<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected $casts = ['qty_base' => 'integer', 'unit_cost' => 'decimal:4', 'created_at' => 'datetime'];
}
