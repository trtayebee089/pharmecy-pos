<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An in-house delivery rider. Signs in to the rider app (/api/rider/v1) with
 * phone + password; the Sanctum token carries the `rider` ability only, so it
 * never reaches staff or customer routes. `password` is null until staff set one.
 */
class Rider extends Authenticatable
{
    use HasApiTokens;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed', 'last_login_at' => 'datetime', 'last_seen_at' => 'datetime', 'on_duty' => 'boolean',
        'last_lat' => 'float', 'last_lng' => 'float', 'last_speed' => 'float',
    ];
}
