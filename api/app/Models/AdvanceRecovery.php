<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvanceRecovery extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected $casts = ['amount' => 'decimal:2', 'created_at' => 'datetime'];
}
