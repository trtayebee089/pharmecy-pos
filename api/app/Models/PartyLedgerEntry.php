<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartyLedgerEntry extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    // entry_date stays a plain Y-m-d string: a date cast stores a time and breaks date comparisons on SQLite.
    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
}
