<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Integrations;
use App\Services\Storefront;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product catalogue feed for Facebook / Instagram shops and Google Merchant
 * Center (what CTX Feed does for WooCommerce): RSS 2.0 with the `g:` namespace,
 * or CSV. Same products, prices (per piece) and online-branch stock as the
 * website. Prescription medicines are left out unless the admin includes them.
 * Cached for 30 minutes; optional `?token=` when a feed token is set.
 */
class FeedController extends Controller
{
    public function __construct(private Integrations $integrations, private Storefront $store) {}

    public function xml(Request $request): Response
    {
        $items = $this->items($request);
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $shop = $this->integrations->public('storefront');
        $f = $this->integrations->public('feed');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
            .'<title>'.$esc($shop['siteName']).'</title><link>'.$esc($f['siteUrl']).'</link><description>'.$esc($shop['metaDescription'] ?: $shop['siteName'].' products').'</description>';
        foreach ($items as $i) {
            $out .= '<item>';
            foreach ($i as $k => $v) {
                if ($v !== null && $v !== '') {
                    $out .= "<g:{$k}>".$esc($v)."</g:{$k}>";
                }
            }
            $out .= '</item>';
        }

        return response($out.'</channel></rss>', 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=1800']);
    }

    public function csv(Request $request): Response
    {
        $items = $this->items($request);
        $cols = ['id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'brand', 'condition', 'product_type', 'google_product_category', 'identifier_exists', 'inventory'];
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $cols);
        foreach ($items as $i) {
            fputcsv($fh, array_map(fn ($c) => $i[$c] ?? '', $cols));
        }
        rewind($fh);

        return response("\xEF\xBB\xBF".stream_get_contents($fh), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'inline; filename="products.csv"', 'Cache-Control' => 'public, max-age=1800']);
    }

    /** @return array<int, array<string, mixed>> */
    private function items(Request $request): array
    {
        $f = $this->integrations->config('feed');
        abort_unless($f['enabled'], 404, 'The product feed is switched off.');
        abort_if($f['token'] && ! hash_equals($f['token'], (string) $request->query('token')), 403, 'Wrong feed token.');

        return Cache::remember('feed.products.'.md5(json_encode(array_diff_key($f, ['token' => 1]))), 1800, function () use ($f) {
            $site = rtrim((string) $f['siteUrl'], '/');
            $rows = [];
            Product::where('is_active', true)->when(! $f['includePrescription'], fn ($q) => $q->where('requires_prescription', false))
                ->orderBy('id')->chunk(200, function ($chunk) use (&$rows, $f, $site) {
                    foreach ($this->store->present($chunk, detail: true) as $p) {
                        if ($p['price'] === null || ($f['inStockOnly'] && $p['stock'] <= 0)) {
                            continue;
                        }
                        $title = trim($p['name'].' '.($p['strength'] ?? ''));
                        $rows[] = [
                            'id' => (string) $p['id'], 'title' => mb_substr($title.($p['unit'] ? ' ('.$p['unit'].')' : ''), 0, 150),
                            'description' => mb_substr(trim(strip_tags((string) ($p['description'] ?? '')) ?: implode(', ', array_filter([$title, $p['generic_name'] ?? null, $p['manufacturer'] ?? null, $p['pack'] ?? null]))), 0, 5000),
                            'link' => $site.'/product/'.rawurlencode((string) $p['slug']), 'image_link' => $p['image'] ?? null,
                            'availability' => $p['stock'] > 0 ? 'in stock' : 'out of stock', 'price' => number_format((float) $p['price'], 2, '.', '').' BDT',
                            'brand' => $p['manufacturer'] ?? null ?: $f['brandFallback'], 'condition' => 'new',
                            'product_type' => $p['category']['name'] ?? null, 'google_product_category' => 'Health & Beauty > Health Care',
                            'identifier_exists' => 'no', 'inventory' => (string) $p['stock'],
                        ];
                    }
                });

            return $rows;
        });
    }
}
