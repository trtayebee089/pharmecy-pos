<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Integrations;
use App\Services\StorefrontBanners;
use Illuminate\Http\JsonResponse;

/**
 * What the website and app need before showing anything: shop details, the
 * public tracking ids (never the server tokens), the payment options that are
 * switched on, and the home page banners. Cached by the client per visit.
 */
class SiteController extends V1Controller
{
    public function config(Integrations $i, StorefrontBanners $banners): JsonResponse
    {
        $shop = $i->public('storefront');
        $t = $i->public('tracking');

        return $this->ok([
            'shop' => array_diff_key($shop, array_flip(['codEnabled'])),
            'tracking' => [
                'gtm_id' => $t['gtmId'] ?: null, 'facebook_pixel_id' => $t['facebookPixelId'] ?: null,
                'ga4_measurement_id' => $t['ga4MeasurementId'] ?: null, 'google_ads_id' => $t['googleAdsId'] ?: null,
                'google_ads_purchase_label' => $t['googleAdsPurchaseLabel'] ?: null, 'currency' => 'BDT',
            ],
            'payments' => array_values(array_filter([
                $shop['codEnabled'] ? ['code' => 'cod', 'label' => 'Cash on delivery'] : null,
                $i->enabled('sslcommerz') ? ['code' => 'sslcommerz', 'label' => $i->public('sslcommerz')['label']] : null,
                $i->enabled('bkash') ? ['code' => 'bkash', 'label' => $i->public('bkash')['label']] : null,
            ])),
            'banners' => $banners->forSite(),
            // Google Maps for the delivery pin at checkout and live tracking (the browser key is public by design).
            'maps' => ($m = $i->public('maps'))['enabled'] && $m['browserKey'] ? [
                'enabled' => true, 'browser_key' => $m['browserKey'],
                'default_center' => ['lat' => (float) $m['defaultLat'], 'lng' => (float) $m['defaultLng']],
            ] : ['enabled' => false, 'browser_key' => null, 'default_center' => ['lat' => (float) $m['defaultLat'], 'lng' => (float) $m['defaultLng']]],
        ]);
    }
}
