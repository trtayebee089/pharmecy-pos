<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The part of a purchase return's credit that reduced one purchase invoice's due. */
class PurchaseReturnAllocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];
}
