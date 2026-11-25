<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Home page banner: `hero` (the wide one, rotates when there are several) or `tile` (three under it). */
class StorefrontBanner extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];
}
