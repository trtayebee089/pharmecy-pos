<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipPlan extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'min_annual_spend' => 'decimal:2', 'discount_pct' => 'decimal:2', 'points_multiplier' => 'decimal:2',
        'fee' => 'decimal:2', 'duration_months' => 'integer', 'benefits' => 'array', 'is_active' => 'boolean',
    ];
}
