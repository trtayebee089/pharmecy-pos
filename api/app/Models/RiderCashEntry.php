<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderCashEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'created_at' => 'datetime'];
}
