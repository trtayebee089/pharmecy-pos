<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['discount_pct' => 'decimal:2'];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
