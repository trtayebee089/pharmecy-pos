<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'requires_reference' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
