<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['queue' => 'array'];
}
