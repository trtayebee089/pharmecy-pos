<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountTransaction extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    // txn_date stays a plain Y-m-d string: a date cast would store a time and break date comparisons on SQLite.
    protected $casts = ['amount' => 'decimal:2'];
}
