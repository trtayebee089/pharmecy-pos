<?php

namespace App\Services;

use App\Models\StorefrontBanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Home page banners (Website › Banners in the POS). Layout on the website:
 * one wide hero banner across the top (several rotate as a slider) and three
 * tiles in a row beneath it. Images live on the public disk under `banners/`.
 *
 * Recommended sizes: hero 1600 × 533 (3:1), its phone version 800 × 800,
 * tiles 800 × 500 (16:10). JPG/PNG/WebP up to 3 MB.
 */
class StorefrontBanners
{
    public const PLACEMENTS = ['hero' => 'Main banner', 'tile' => 'Tile'];

    public const TILE_SLOTS = 3;

    /** Active banners for today, grouped as the website lays them out. */
    public function forSite(): array
    {
        $today = now()->toDateString();
        $rows = StorefrontBanner::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderBy('sort_order')->orderBy('id')->get();

        return [
            'hero' => $rows->where('placement', 'hero')->map(fn ($b) => $this->present($b))->values()->all(),
            'tiles' => $rows->where('placement', 'tile')->take(self::TILE_SLOTS)->map(fn ($b) => $this->present($b))->values()->all(),
        ];
    }

    public function present(StorefrontBanner $b, bool $admin = false): array
    {
        return [
            'id' => $b->id, 'placement' => $b->placement, 'title' => $b->title, 'subtitle' => $b->subtitle,
            'button_text' => $b->button_text, 'link_url' => $b->link_url,
            'image' => $this->url($b->image_path), 'mobile_image' => $this->url($b->mobile_image_path),
        ] + ($admin ? [
            'sort_order' => $b->sort_order, 'is_active' => $b->is_active, 'starts_on' => $b->starts_on, 'ends_on' => $b->ends_on,
            'updated_at' => $b->updated_at?->toIso8601String(),
        ] : []);
    }

    public function store(?UploadedFile $file, ?string $old = null): ?string
    {
        if (! $file) {
            return $old;
        }
        $path = $file->store('banners', 'public');
        $this->delete($old);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
