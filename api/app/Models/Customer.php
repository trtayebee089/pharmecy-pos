<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_walk_in' => 'boolean', 'is_active' => 'boolean', 'credit_limit' => 'decimal:2'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
