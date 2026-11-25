<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineOrder extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'subtotal' => 'decimal:2', 'shipping' => 'decimal:2', 'grand_total' => 'decimal:2',
        'discount_pct' => 'decimal:4', 'discount_total' => 'decimal:2', 'points_value' => 'decimal:2', 'points_redeemed' => 'integer',
        'placed_at' => 'datetime', 'delivered_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OnlineOrderLine::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OnlineOrderHistory::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class);
    }
}
