<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Admin settings stored in `settings` (global scope):
 *
 * - `general` (Administration › General settings), owner decisions 25 Nov 2026:
 *   expiry thresholds, **roundTo** (the server rounds every sale's grand total
 *   to 0.50 or 1 taka and records the difference on `sales.rounding`; 0 = off),
 *   and two real stock switches that default to the safe rules:
 *   **blockExpiredSale** (true = expired batches are never sold) and
 *   **allowNegativeStock** (false = a sale needs the stock).
 * - `pos` (Administration › POS settings): counter layout and defaults, invoice
 *   layout and printers. Payment methods live in `payment_methods`; the PrintNode
 *   API key is stored encrypted and never returned.
 */
class AppSettings
{
    public const GENERAL = [
        'nearExpiryDays' => 90, 'criticalExpiryDays' => 30, 'allowNegativeStock' => false, 'blockExpiredSale' => true,
        'roundTo' => 0, 'receiptWidth' => '80mm', 'receiptFooter' => '', 'printOnComplete' => true, 'rxReminder' => true,
        'defaultCustomerId' => null,
    ];

    public const POS = [
        'layout' => 'grid', 'defaultCustomerId' => null, 'defaultWarehouseId' => null, 'defaultBillerId' => null,
        'productsPerPage' => 24, 'productGrid' => 'comfortable', 'touchKeyboard' => false, 'sendSmsAfterSale' => false,
        'requireCustomerForDue' => true,
        'invoice' => ['format' => 'thermal80', 'showLogo' => true, 'showGeneric' => true, 'showBatchExpiry' => true, 'showSavings' => true, 'showQr' => false, 'header' => '', 'footer' => ''],
        'printers' => [],
    ];

    private array $cache = [];

    public function general(?string $key = null): mixed
    {
        $all = $this->cache['general'] ??= $this->load('general', self::GENERAL);
        $all['defaultCustomerId'] ??= Customer::where('is_walk_in', true)->value('id');

        return $key === null ? $all : $all[$key];
    }

    public function saveGeneral(array $values): array
    {
        $this->store('general', array_merge(self::GENERAL, array_intersect_key($values, self::GENERAL)));

        return $this->general();
    }

    public function pos(): array
    {
        $all = $this->cache['pos'] ??= $this->load('pos', self::POS);
        unset($all['printNodeKey']);
        $all['printNodeConfigured'] = $this->printNodeKey() !== null;

        return $all;
    }

    public function savePos(array $values, ?string $printNodeKey): array
    {
        $current = $this->load('pos', self::POS);
        $clean = array_merge(self::POS, array_intersect_key($values, self::POS));
        $clean['printNodeKey'] = $printNodeKey !== null && $printNodeKey !== '' ? Crypt::encryptString($printNodeKey) : ($current['printNodeKey'] ?? null);
        $this->store('pos', $clean);

        return $this->pos();
    }

    public function printNodeKey(): ?string
    {
        $enc = $this->load('pos', self::POS)['printNodeKey'] ?? null;

        return $enc ? Crypt::decryptString($enc) : null;
    }

    /** Rounding step in paisa (0 = no rounding). */
    public function roundingPaisa(): int
    {
        return (int) round(((float) $this->general('roundTo')) * 100);
    }

    private function load(string $key, array $defaults): array
    {
        $stored = Setting::where(['scope' => 'global', 'scope_id' => 0, 'key' => $key])->value('value');
        $stored = is_string($stored) ? json_decode($stored, true) : (array) $stored;

        return array_merge($defaults, $stored ?? []);
    }

    private function store(string $key, array $value): void
    {
        Setting::updateOrCreate(['scope' => 'global', 'scope_id' => 0, 'key' => $key], ['value' => $value]);
        unset($this->cache[$key]);
    }
}
