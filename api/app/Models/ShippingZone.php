<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['rate' => 'decimal:2', 'free_shipping_min' => 'decimal:2', 'is_active' => 'boolean'];
}
