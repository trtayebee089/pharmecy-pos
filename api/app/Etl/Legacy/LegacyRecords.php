<?php

namespace App\Etl\Legacy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Guards for documents imported from the legacy POS. They were imported as
 * history without stock movements, ledger or account entries (the opening
 * balances carry their effect), so voiding one would post reversals for
 * stock and money that never moved in the new system.
 */
class LegacyRecords
{
    public static function is(string $entity, int $newId): bool
    {
        return Schema::hasTable('legacy_id_map')
            && DB::table('legacy_id_map')->where(['entity' => $entity, 'new_id' => $newId])->exists();
    }

    public static function refuseVoid(bool $legacy, string $field, string $what): void
    {
        if ($legacy) {
            throw ValidationException::withMessages([$field => "This {$what} was imported from the old system and cannot be voided here."]);
        }
    }
}
