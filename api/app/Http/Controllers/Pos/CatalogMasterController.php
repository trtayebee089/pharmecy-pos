<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DosageForm;
use App\Models\Generic;
use App\Models\Manufacturer;
use App\Support\Present;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catalog masters: generics, manufacturers, dosage forms, categories
 * (/catalog/{type}). Names are unique. A master used by products cannot be
 * deleted (deactivate it: it disappears from product forms but stays on
 * existing products). Categories can nest; a category cannot become its own
 * ancestor.
 */
class CatalogMasterController extends Controller
{
    /** type => [model, camelCase field => column, extra validation] */
    private const TYPES = [
        'generics' => [Generic::class, ['therapeuticClass' => 'therapeutic_class']],
        'manufacturers' => [Manufacturer::class, ['shortName' => 'short_name', 'country' => 'country']],
        'dosage-forms' => [DosageForm::class, []],
        'categories' => [Category::class, ['parentId' => 'parent_id', 'sortOrder' => 'sort_order']],
    ];

    /** Active masters for product forms; categories as their full path. */
    public function lookups(): JsonResponse
    {
        $pick = fn (string $model) => $model::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->all();
        $categories = Category::where('is_active', true)->get();

        return response()->json([
            'generics' => $pick(Generic::class), 'manufacturers' => $pick(Manufacturer::class), 'dosageForms' => $pick(DosageForm::class),
            'categories' => $categories->map(fn ($c) => ['id' => $c->id, 'name' => $this->path($c)])->sortBy('name')->values()->all(),
        ]);
    }

    public function index(Request $request, string $type): JsonResponse
    {
        [$model] = $this->type($type);
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'all' => ['nullable'], 'isActive' => ['nullable'],
        ]);
        $query = $model::query()->withCount('products')
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%'))
            ->when(isset($data['isActive']) && $data['isActive'] !== '', fn ($q) => $q->where('is_active', filter_var($data['isActive'], FILTER_VALIDATE_BOOLEAN)))
            ->when($type === 'categories', fn ($q) => $q->orderBy('sort_order'))
            ->orderBy('name');

        if (filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json($query->get()->map(fn ($m) => $this->present($type, $m))->all());
        }
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($m) => $this->present($type, $m))->all()));
    }

    public function show(string $type, int $id): JsonResponse
    {
        [$model] = $this->type($type);

        return response()->json($this->present($type, $model::withCount('products')->findOrFail($id)));
    }

    public function store(Request $request, string $type): JsonResponse
    {
        [$model] = $this->type($type);
        $row = new $model;
        $this->fill($request, $type, $row)->save();
        AuditLog::record($request->user()->id, "catalog.$type.created", $type, $row->id, ['name' => $row->name]);

        return response()->json($this->present($type, $row->loadCount('products')), 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        [$model] = $this->type($type);
        $row = $model::findOrFail($id);
        $this->fill($request, $type, $row)->save();
        AuditLog::record($request->user()->id, "catalog.$type.updated", $type, $row->id, ['name' => $row->name]);

        return response()->json($this->present($type, $row->loadCount('products')));
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        [$model] = $this->type($type);
        $row = $model::withCount('products')->findOrFail($id);
        if ($row->products_count > 0) {
            throw ValidationException::withMessages(['id' => "{$row->name} is used by {$row->products_count} products. Deactivate it instead."]);
        }
        if ($type === 'categories' && Category::where('parent_id', $row->id)->exists()) {
            throw ValidationException::withMessages(['id' => "{$row->name} has sub-categories. Move or delete them first."]);
        }
        $row->delete();
        AuditLog::record($request->user()->id, "catalog.$type.deleted", $type, $row->id, ['name' => $row->name]);

        return response()->json(['deleted' => true]);
    }

    private function fill(Request $request, string $type, Model $row): Model
    {
        [$model, $map] = $this->type($type);
        $table = (new $model)->getTable();
        $partial = $request->isMethod('put') && ! $request->has('name'); // e.g. toggling isActive only
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique($table, 'name')->ignore($row->id)],
            'isActive' => ['sometimes', 'boolean'],
            'therapeuticClass' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shortName' => ['sometimes', 'nullable', 'string', 'max:60'],
            'country' => ['sometimes', 'nullable', 'string', 'max:60'],
            'parentId' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
            'sortOrder' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        if (array_key_exists('name', $data)) {
            $row->name = trim($data['name']);
        }
        if (array_key_exists('isActive', $data)) {
            $row->is_active = $data['isActive'];
        }
        foreach ($map as $field => $column) {
            if (array_key_exists($field, $data)) {
                $row->{$column} = $data[$field] ?? ($column === 'sort_order' ? 0 : null);
            }
        }
        if ($type === 'categories') {
            for ($p = $row->parent_id; $p; $p = Category::whereKey($p)->value('parent_id')) {
                if ($row->exists && $p === $row->id) {
                    throw ValidationException::withMessages(['parentId' => 'A category cannot be placed under itself.']);
                }
            }
            if (! $row->exists || $row->isDirty('name')) {
                $slug = Str::slug($row->name) ?: 'category';
                for ($n = 2, $try = $slug; Category::withTrashed()->where('slug', $try)->where('id', '!=', $row->id ?? 0)->exists(); $n++) {
                    $try = "$slug-$n";
                }
                $row->slug = $try;
            }
        }

        return $row;
    }

    private function present(string $type, Model $m): array
    {
        $base = ['id' => $m->id, 'name' => $m->name, 'products' => (int) ($m->products_count ?? 0), 'isActive' => (bool) $m->is_active];

        return $base + match ($type) {
            'generics' => ['therapeuticClass' => (string) $m->therapeutic_class],
            'manufacturers' => ['shortName' => (string) $m->short_name, 'country' => (string) $m->country],
            'categories' => ['parentId' => $m->parent_id, 'path' => $this->path($m), 'sortOrder' => (int) $m->sort_order],
            default => [],
        };
    }

    private function path(Category $c): string
    {
        $names = [$c->name];
        for ($p = $c->parent_id, $guard = 0; $p && $guard < 10; $guard++) {
            $parent = Category::find($p);
            if (! $parent) {
                break;
            }
            array_unshift($names, $parent->name);
            $p = $parent->parent_id;
        }

        return implode(' › ', $names);
    }

    private function type(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }
}
