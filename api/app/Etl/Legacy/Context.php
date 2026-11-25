<?php

namespace App\Etl\Legacy;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared state of one legacy → new migration run: the two connections, the
 * id maps built by earlier steps, bulk inserts, rejects and the report.
 *
 * Documents keep their legacy primary key in the new table (sales.id = legacy
 * sales.id and so on), which keeps every line link without a lookup. That
 * only works on an empty target, which LegacyMigrator checks first.
 */
class Context
{
    public Connection $src;

    public Connection $dst;

    /** @var array<string, array<int, int>> entity => [legacy id => new id] */
    public array $maps = [];

    /** @var array<string, array<string, mixed>> section => facts */
    public array $report = [];

    // Ids set by MasterData / Catalog for the later steps.
    public int $defaultWarehouse = 0;

    public int $cashAccount = 0;

    public int $cashMethod = 0;

    public int $systemUser = 0;

    public int $walkIn = 0;

    public int $unknownSupplier = 0;

    /** @var array<int, float> new customer id => legacy points (merged duplicates summed) */
    public array $customerPoints = [];

    /** @var array<int, int> product id => piece unit id */
    public array $pieceUnit = [];

    /** @var array<int, float> product id => unit cost used for history and opening stock */
    public array $unitCost = [];

    /** @var array<int, string> product id => name */
    public array $productName = [];

    /** @var array<int, array{0: float, 1: float, 2: float, 3: float, 4: ?string}> catalog step scratch: piece_qty, stripe_qty, price, wholesale, code */
    public array $legacyProduct = [];

    /** @var array<int, int> imported sale id => new customer id */
    public array $saleCustomer = [];

    /** @var array<int, int> imported purchase id => new supplier id */
    public array $purchaseSupplier = [];

    public int $customLineOffset = 0;

    /** @var array<string, true> numbers already used per document type */
    private array $numbers = [];

    /** @var array<int, array<string, mixed>> pending legacy_id_map rows */
    private array $mapRows = [];

    /** @var array<int, array<string, mixed>> pending etl_rejects rows */
    private array $rejectRows = [];

    public function __construct(
        public string $cutoverDate,
        public string $warehouseCode,
        public string $stockSource,
        public ?Closure $log = null,
    ) {
        $this->src = DB::connection('legacy');
        $this->dst = DB::connection();
    }

    public function info(string $message): void
    {
        if ($this->log) {
            ($this->log)($message);
        }
    }

    public function map(string $entity, int $legacyId, int $newId, bool $persist = true): void
    {
        $this->maps[$entity][$legacyId] = $newId;
        if ($persist) {
            $this->mapRows[] = ['entity' => $entity, 'legacy_id' => $legacyId, 'new_id' => $newId, 'source' => 'etl', 'created_at' => now()];
            if (count($this->mapRows) >= 2000) {
                $this->flush();
            }
        }
    }

    public function mapped(string $entity, ?int $legacyId): ?int
    {
        return $legacyId === null ? null : ($this->maps[$entity][$legacyId] ?? null);
    }

    public function reject(string $entity, ?int $legacyId, string $reason, array $payload = []): void
    {
        $this->rejectRows[] = [
            'entity' => $entity, 'legacy_id' => $legacyId, 'reason' => Str::limit($reason, 250),
            'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null, 'created_at' => now(),
        ];
        $this->count($entity, 'rejected: '.$reason);
        if (count($this->rejectRows) >= 2000) {
            $this->flush();
        }
    }

    /** Adds to a counter in the report. */
    public function count(string $section, string $key, int|float $by = 1): void
    {
        $this->report[$section][$key] = ($this->report[$section][$key] ?? 0) + $by;
    }

    public function fact(string $section, string $key, mixed $value): void
    {
        $this->report[$section][$key] = $value;
    }

    /** Inserts rows in chunks into the target. */
    public function insert(string $table, array $rows, int $chunk = 1000): void
    {
        foreach (array_chunk($rows, $chunk) as $part) {
            $this->dst->table($table)->insert($part);
        }
    }

    public function flush(): void
    {
        if ($this->mapRows) {
            $this->insert('legacy_id_map', $this->mapRows);
            $this->mapRows = [];
        }
        if ($this->rejectRows) {
            $this->insert('etl_rejects', $this->rejectRows, 500);
            $this->rejectRows = [];
        }
    }

    /**
     * A unique document number from a legacy reference. Duplicates (legacy
     * references use a 12-hour clock) get a -2, -3 suffix; empty ones get
     * "{fallback}{id}".
     */
    public function number(string $type, ?string $reference, string $fallback, int $id): string
    {
        $base = trim((string) $reference) !== '' ? Str::limit(trim($reference), 34, '') : $fallback.$id;
        $number = $base;
        for ($n = 2; isset($this->numbers[$type][strtolower($number)]); $n++) {
            $number = $base.'-'.$n;
        }
        $this->numbers[$type][strtolower($number)] = true;

        return $number;
    }

    public static function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /** Legacy timestamps: NULL and zero dates become the fallback. */
    public static function ts(mixed $value, ?string $fallback = null): ?string
    {
        if (! $value || str_starts_with((string) $value, '0000')) {
            return $fallback;
        }

        return (string) $value;
    }

    public static function date(mixed $value, ?string $fallback = null): ?string
    {
        $ts = self::ts($value);

        return $ts ? substr($ts, 0, 10) : $fallback;
    }

    /**
     * Bangladesh mobile number in 01XXXXXXXXX form, or null when the value is
     * not one (kept as the raw phone only).
     */
    public static function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '88') && strlen($digits) === 13) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    public static function ulid(): string
    {
        return (string) Str::ulid();
    }
}
