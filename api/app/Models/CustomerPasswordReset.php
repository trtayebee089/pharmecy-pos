<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPasswordReset extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_at' => 'datetime'];
}
