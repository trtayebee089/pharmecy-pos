<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to pay an online order through SSLCommerz or bKash. */
class OnlinePayment extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'payload' => 'array', 'paid_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'online_order_id');
    }
}
