<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardWallet extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['balance' => 'integer', 'lifetime_earned' => 'integer', 'lifetime_redeemed' => 'integer'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
