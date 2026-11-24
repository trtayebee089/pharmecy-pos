<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'received_at' => 'datetime',
        'invoice_date' => 'date',
        'grand_total' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'due_total' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
