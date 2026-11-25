<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Request a medicine" from the website: medicine names and/or prescription files, handled by staff. */
class MedicineRequest extends Model
{
    public const STATUSES = ['new', 'contacted', 'ordered', 'closed'];

    protected $guarded = ['id'];

    protected $casts = ['files' => 'array', 'handled_at' => 'datetime'];

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
