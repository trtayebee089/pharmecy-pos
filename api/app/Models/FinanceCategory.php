<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCategory extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'parent_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinanceEntry::class, 'category_id');
    }
}
