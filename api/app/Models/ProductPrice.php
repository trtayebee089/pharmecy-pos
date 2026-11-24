<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['price' => 'decimal:4', 'effective_from' => 'datetime', 'effective_to' => 'datetime'];
}
