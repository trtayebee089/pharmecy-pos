<?php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * Inputs every report shares: the warehouses it may see (the selected one, or
 * all the user can access), the inclusive date range and the filters (an
 * empty filter value means "all").
 */
class ReportScope
{
    /** @param  array<int>  $warehouseIds */
    public function __construct(
        public array $warehouseIds,
        public string $from,
        public string $to,
        public array $filters = [],
    ) {}

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(): array
    {
        return [Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to)->endOfDay()];
    }

    public function filter(string $key, ?string $default = null): ?string
    {
        $v = trim((string) ($this->filters[$key] ?? ''));

        return $v === '' ? $default : $v;
    }

    public static function day(string|Carbon $date): string
    {
        return Carbon::parse($date)->format('d M');
    }

    public static function r2(float|int|string|null $n): float
    {
        return round((float) $n, 2);
    }

    /** Sums a column of rows. */
    public static function sum(iterable $rows, string $key): float
    {
        $t = 0.0;
        foreach ($rows as $r) {
            $t += (float) ($r[$key] ?? 0);
        }

        return round($t, 2);
    }

    /** [{label, value}] per day in date order from rows keyed by a date-like field. */
    public static function perDay(iterable $rows, string $dateKey, callable $value): array
    {
        $days = [];
        foreach ($rows as $r) {
            $d = substr((string) $r[$dateKey], 0, 10);
            $days[$d] = ($days[$d] ?? 0) + $value($r);
        }
        ksort($days);

        return collect($days)->map(fn ($v, $d) => ['label' => self::day($d), 'value' => round($v, 2)])->values()->all();
    }

    public static function col(string $key, string $label, ?string $format = null, array $extra = []): array
    {
        $right = in_array($format, ['money', 'number', 'percent'], true);

        return array_filter(['key' => $key, 'label' => $label, 'format' => $format, 'align' => $right ? 'right' : null], fn ($v) => $v !== null) + $extra;
    }

    public static function kpi(string $label, float|int $value, string $format = 'money', ?string $hint = null): array
    {
        return array_filter(['label' => $label, 'value' => is_float($value) ? round($value, 2) : $value, 'format' => $format, 'hint' => $hint], fn ($v) => $v !== null);
    }
}
