<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StorefrontBanner;
use App\Services\StorefrontBanners;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Website › Banners: the home page hero banner(s) and the three tiles under it.
 * Multipart (images). Needs `settings.manage`.
 */
class BannerController extends Controller
{
    public function __construct(private StorefrontBanners $banners) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'banners' => StorefrontBanner::orderBy('placement')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($b) => $this->banners->present($b, admin: true))->all(),
            'tileSlots' => StorefrontBanners::TILE_SLOTS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request, null);
        $b = StorefrontBanner::create($d + [
            'image_path' => $this->banners->store($request->file('image')),
            'mobile_image_path' => $this->banners->store($request->file('mobile_image')),
            'sort_order' => (int) StorefrontBanner::where('placement', $d['placement'])->max('sort_order') + 1,
            'created_by' => $request->user()->id,
        ]);
        AuditLog::record($request->user()->id, 'banner.created', 'storefront_banner', $b->id, ['title' => $b->title, 'placement' => $b->placement], $request);

        return response()->json($this->banners->present($b, admin: true), 201);
    }

    /** POST (multipart cannot be a PUT in browsers): a new image replaces the old file. */
    public function update(Request $request, StorefrontBanner $banner): JsonResponse
    {
        $d = $this->validated($request, $banner);
        $banner->fill($d);
        $banner->image_path = $this->banners->store($request->file('image'), $banner->image_path);
        if ($request->boolean('remove_mobile_image')) {
            $this->banners->delete($banner->mobile_image_path);
            $banner->mobile_image_path = null;
        } else {
            $banner->mobile_image_path = $this->banners->store($request->file('mobile_image'), $banner->mobile_image_path);
        }
        $banner->save();
        AuditLog::record($request->user()->id, 'banner.updated', 'storefront_banner', $banner->id, ['title' => $banner->title], $request);

        return response()->json($this->banners->present($banner, admin: true));
    }

    public function destroy(Request $request, StorefrontBanner $banner): JsonResponse
    {
        $this->banners->delete($banner->image_path);
        $this->banners->delete($banner->mobile_image_path);
        $banner->delete();
        AuditLog::record($request->user()->id, 'banner.deleted', 'storefront_banner', $banner->id, ['title' => $banner->title], $request);

        return response()->json(['ok' => true]);
    }

    /** New order within one placement: ids top to bottom (left to right for tiles). */
    public function reorder(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['integer', 'exists:storefront_banners,id']]);
        DB::transaction(function () use ($d) {
            foreach ($d['ids'] as $i => $id) {
                StorefrontBanner::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return $this->index();
    }

    private function validated(Request $request, ?StorefrontBanner $banner): array
    {
        $image = ['image', 'mimes:jpg,jpeg,png,webp', 'max:3072'];

        $d = $request->validate([
            'placement' => ['required', 'in:hero,tile'], 'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:255'], 'button_text' => ['nullable', 'string', 'max:40'],
            // A path on the site (/category/vitamins) or a full https link.
            'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(/|https?://)#'],
            'is_active' => ['nullable', 'boolean'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'image' => [$banner ? 'nullable' : 'required', ...$image], 'mobile_image' => ['nullable', ...$image],
        ], ['link_url.regex' => 'Use a site path like /category/vitamins or a full https:// link.']);
        unset($d['image'], $d['mobile_image']); // files are stored separately

        return ['is_active' => $request->boolean('is_active', true)] + $d;
    }
}
