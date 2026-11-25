<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One SMS attempt (sent or failed) through the configured gateway. */
class SmsMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];
}
