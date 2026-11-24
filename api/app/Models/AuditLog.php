<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    /** Never pass secrets in $after: passwords and tokens must not reach the log. */
    public static function record(?int $userId, string $action, string $subjectType, ?int $subjectId, ?array $after, ?Request $request = null): void
    {
        static::create([
            'user_id' => $userId, 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'after' => $after, 'ip' => $request?->ip(), 'user_agent' => substr((string) $request?->userAgent(), 0, 255),
        ]);
    }
}
