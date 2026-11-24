<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean', 'opening_balance' => 'decimal:2'];
}
