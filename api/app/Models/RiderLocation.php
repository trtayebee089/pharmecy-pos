<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One position report from the rider app. */
class RiderLocation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['lat' => 'float', 'lng' => 'float', 'speed' => 'float', 'recorded_at' => 'datetime'];
}
