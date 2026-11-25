<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['basic' => 'decimal:2', 'additions' => 'decimal:2', 'deductions' => 'decimal:2', 'advance_recovery' => 'decimal:2', 'net' => 'decimal:2'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
