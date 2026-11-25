<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Settings for outside services (Administration › Integrations), one `settings`
 * row per group under the key `integration.<group>`.
 *
 * Secrets (passwords, API keys, tokens) are stored encrypted with APP_KEY and
 * are never sent back to the browser: `public()` replaces each with a boolean
 * `<name>Set`. Saving a secret as null or '' keeps the stored one; send it in
 * `clear` to remove it. `config()` returns the decrypted values for the server.
 */
class Integrations
{
    public const GROUPS = [
        'mail' => [
            'defaults' => ['enabled' => false, 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'fromAddress' => '', 'fromName' => 'Red Pharma'],
            'secrets' => ['password'],
        ],
        'sms' => [
            // Any HTTP gateway: the request is described by a template (see SmsGateway).
            'defaults' => [
                'enabled' => false, 'provider' => 'custom', 'method' => 'POST', 'url' => '', 'bodyType' => 'form',
                'params' => [], 'headers' => [], 'senderId' => '', 'phoneFormat' => '8801', 'successContains' => '',
                'sendOrderUpdates' => false, 'sendAfterSale' => false,
            ],
            'secrets' => ['apiKey', 'apiSecret'],
        ],
        'tracking' => [
            'defaults' => [
                'gtmId' => '', 'facebookPixelId' => '', 'ga4MeasurementId' => '', 'googleAdsId' => '', 'googleAdsPurchaseLabel' => '',
                'serverSideFacebook' => false, 'facebookTestEventCode' => '', 'serverSideGa4' => false,
            ],
            'secrets' => ['facebookCapiToken', 'ga4ApiSecret'],
        ],
        'feed' => [
            'defaults' => ['enabled' => false, 'siteUrl' => '', 'brandFallback' => 'Red Pharma', 'inStockOnly' => true, 'includePrescription' => false],
            'secrets' => ['token'],
        ],
        'sslcommerz' => [
            'defaults' => ['enabled' => false, 'sandbox' => true, 'storeId' => '', 'label' => 'Card / mobile banking (SSLCommerz)'],
            'secrets' => ['storePassword'],
        ],
        'bkash' => [
            'defaults' => ['enabled' => false, 'sandbox' => true, 'appKey' => '', 'username' => '', 'label' => 'bKash'],
            'secrets' => ['appSecret', 'password'],
        ],
        'steadfast' => [
            'defaults' => ['enabled' => false, 'apiKey' => '', 'baseUrl' => 'https://portal.packzy.com/api/v1'],
            'secrets' => ['secretKey', 'webhookToken'],
        ],
        'pathao' => [
            'defaults' => [
                'enabled' => false, 'sandbox' => true, 'clientId' => '', 'username' => '', 'storeId' => '',
                'defaultItemType' => 2, 'defaultDeliveryType' => 48, 'defaultWeightKg' => 0.5,
            ],
            'secrets' => ['clientSecret', 'password', 'webhookSecret'],
        ],
        'storefront' => [
            'defaults' => [
                'siteName' => 'Red Pharma', 'websiteUrl' => '', 'phone' => '', 'whatsapp' => '', 'email' => '', 'address' => '',
                'facebookUrl' => '', 'instagramUrl' => '', 'youtubeUrl' => '', 'metaTitle' => '', 'metaDescription' => '',
                'announcement' => '', 'codEnabled' => true,
            ],
            'secrets' => [],
        ],
    ];

    private array $cache = [];

    public static function has(string $group): bool
    {
        return isset(self::GROUPS[$group]);
    }

    /** What the admin screen sees: values plus `<secret>Set` flags, never the secrets. */
    public function public(string $group): array
    {
        $raw = $this->raw($group);
        $out = array_diff_key($raw, array_flip(self::GROUPS[$group]['secrets']));
        foreach (self::GROUPS[$group]['secrets'] as $s) {
            $out[$s.'Set'] = ! empty($raw[$s]);
        }

        return $out;
    }

    /** Full values with decrypted secrets, for the server only. */
    public function config(string $group): array
    {
        $raw = $this->raw($group);
        foreach (self::GROUPS[$group]['secrets'] as $s) {
            try {
                $raw[$s] = ! empty($raw[$s]) ? Crypt::decryptString($raw[$s]) : null;
            } catch (DecryptException) {
                $raw[$s] = null; // APP_KEY changed: the secret has to be entered again
            }
        }

        return $raw;
    }

    /** The shop website (payment returns, feed links): Website tab, else the feed's, else FRONTEND_URL / APP_URL. */
    public function websiteUrl(): string
    {
        $url = $this->raw('storefront')['websiteUrl'] ?: $this->raw('feed')['siteUrl'] ?: env('FRONTEND_URL') ?: config('app.url');

        return rtrim((string) $url, '/');
    }

    public function enabled(string $group): bool
    {
        return (bool) ($this->raw($group)['enabled'] ?? false);
    }

    /** @param  array<int, string>  $clear  secrets to remove */
    public function save(string $group, array $values, array $clear = []): array
    {
        $def = self::GROUPS[$group];
        $current = $this->raw($group);
        $clean = array_merge($def['defaults'], array_intersect_key($current, $def['defaults']), array_intersect_key($values, $def['defaults']));
        foreach ($def['secrets'] as $s) {
            $new = $values[$s] ?? null;
            $clean[$s] = in_array($s, $clear, true) ? null : (is_string($new) && $new !== '' ? Crypt::encryptString($new) : ($current[$s] ?? null));
        }
        Setting::updateOrCreate(['scope' => 'global', 'scope_id' => 0, 'key' => 'integration.'.$group], ['value' => $clean]);
        unset($this->cache[$group]);

        return $this->public($group);
    }

    private function raw(string $group): array
    {
        if (isset($this->cache[$group])) {
            return $this->cache[$group];
        }
        $stored = Setting::where(['scope' => 'global', 'scope_id' => 0, 'key' => 'integration.'.$group])->value('value');
        $stored = is_string($stored) ? json_decode($stored, true) : (array) $stored;

        return $this->cache[$group] = array_merge(self::GROUPS[$group]['defaults'], array_fill_keys(self::GROUPS[$group]['secrets'], null), $stored ?? []);
    }
}
