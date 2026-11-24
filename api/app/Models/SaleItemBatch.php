<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItemBatch extends Model
{
    protected $guarded = ['id'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
