<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RewardCampaign extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['multiplier' => 'decimal:2', 'is_active' => 'boolean'];
}
