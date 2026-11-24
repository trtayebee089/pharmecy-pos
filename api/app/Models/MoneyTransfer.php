<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyTransfer extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'voided_at' => 'datetime']; // transfer_date: plain Y-m-d

    public function from(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
