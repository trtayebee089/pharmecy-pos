<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\ProductPrice;
use Illuminate\Support\Collection;

/**
 * Current selling price per product unit from the default (retail) price list.
 * Prices are versioned rows: the active one has effective_from <= now and no
 * effective_to (or one in the future).
 */
class Pricing
{
    private ?int $defaultListId = null;

    public function defaultListId(): int
    {
        return $this->defaultListId ??= (int) (PriceList::where('is_default', true)->value('id') ?? PriceList::value('id'));
    }

    public function listId(string $code): int
    {
        return $code === 'retail' ? $this->defaultListId() : (int) PriceList::where('code', $code)->value('id');
    }

    /** @param  array<int>  $unitIds  @return Collection<int, string> unit id => price */
    public function forUnits(array $unitIds, string $list = 'retail'): Collection
    {
        if ($unitIds === []) {
            return collect();
        }
        $now = now();

        return ProductPrice::query()
            ->where('price_list_id', $this->listId($list))
            ->whereIn('product_unit_id', $unitIds)
            ->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->orderBy('effective_from')
            ->get()
            ->mapWithKeys(fn (ProductPrice $p) => [$p->product_unit_id => (string) $p->price]);
    }

    /** Closes the current price and starts a new one (price history is kept). */
    public function setPrice(int $unitId, float $price, ?int $userId, string $list = 'retail'): void
    {
        $this->endPrice($unitId, $list);
        ProductPrice::create([
            'price_list_id' => $this->listId($list), 'product_unit_id' => $unitId,
            'price' => $price, 'effective_from' => now(), 'created_by' => $userId,
        ]);
    }

    /** Ends the current price on a list (e.g. a wholesale price that was cleared). */
    public function endPrice(int $unitId, string $list = 'retail'): void
    {
        ProductPrice::where('price_list_id', $this->listId($list))
            ->where('product_unit_id', $unitId)
            ->whereNull('effective_to')
            ->update(['effective_to' => now()]);
    }
}
