<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'reward_ledger';

    protected $guarded = ['id'];

    protected $casts = ['points' => 'integer', 'remaining' => 'integer', 'value' => 'decimal:2', 'created_at' => 'datetime'];
}
