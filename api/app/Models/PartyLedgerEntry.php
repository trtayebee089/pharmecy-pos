<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartyLedgerEntry extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'entry_date' => 'date'];
}
