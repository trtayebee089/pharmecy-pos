<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A customer. Signs in to the website and app (/api/v1) with a Sanctum
 * bearer token carrying the `customer` ability; never a staff session.
 * `password` is null until the customer creates a web/app account.
 */
class Customer extends Authenticatable
{
    use HasApiTokens;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected $casts = [
        'is_walk_in' => 'boolean', 'is_active' => 'boolean', 'credit_limit' => 'decimal:2',
        'password' => 'hashed', 'last_login_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
