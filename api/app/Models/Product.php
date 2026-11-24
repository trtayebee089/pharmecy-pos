<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'requires_prescription' => 'boolean',
        'is_batch_tracked' => 'boolean',
        'is_active' => 'boolean',
        'reorder_level_base' => 'integer',
        'reorder_qty_base' => 'integer',
    ];

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('factor');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function dosageForm(): BelongsTo
    {
        return $this->belongsTo(DosageForm::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
