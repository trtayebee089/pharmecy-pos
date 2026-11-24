<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Customer groups (CustomerGroup in pos/app/types/people.ts). A group with members cannot be deleted. */
class CustomerGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'search' => ['nullable', 'string', 'max:100'], 'all' => ['nullable'],
        ]);
        $query = CustomerGroup::query()->withCount(['customers as members' => fn ($q) => $q->where('is_active', true)])
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%$s%"))->orderBy('name');
        if (filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json($query->get()->map(fn ($g) => $this->present($g))->all());
        }
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($g) => $this->present($g))->all()));
    }

    public function show(CustomerGroup $group): JsonResponse
    {
        return response()->json($this->present($group->loadCount(['customers as members' => fn ($q) => $q->where('is_active', true)])));
    }

    public function store(Request $request): JsonResponse
    {
        $group = new CustomerGroup;
        $this->fill($request, $group)->save();
        AuditLog::record($request->user()->id, 'customer_group.created', 'customer_group', $group->id, ['name' => $group->name]);

        return response()->json($this->present($group), 201);
    }

    public function update(Request $request, CustomerGroup $group): JsonResponse
    {
        $this->fill($request, $group)->save();
        AuditLog::record($request->user()->id, 'customer_group.updated', 'customer_group', $group->id, ['discountPct' => (float) $group->discount_pct]);

        return response()->json($this->present($group->loadCount('customers as members')));
    }

    public function destroy(Request $request, CustomerGroup $group): JsonResponse
    {
        $n = Customer::where('customer_group_id', $group->id)->count();
        if ($n > 0) {
            throw ValidationException::withMessages(['id' => "{$group->name} has $n customers. Move them to another group first."]);
        }
        $group->delete();
        AuditLog::record($request->user()->id, 'customer_group.deleted', 'customer_group', $group->id, ['name' => $group->name]);

        return response()->json(['deleted' => true]);
    }

    private function fill(Request $request, CustomerGroup $group): CustomerGroup
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('customer_groups', 'name')->ignore($group->id)],
            'discountPct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $group->fill(['name' => trim($data['name']), 'discount_pct' => $data['discountPct'], 'notes' => ($data['notes'] ?? '') ?: null]);
    }

    private function present(CustomerGroup $g): array
    {
        return ['id' => $g->id, 'name' => $g->name, 'discountPct' => (float) $g->discount_pct, 'members' => (int) ($g->members ?? 0), 'notes' => (string) $g->notes];
    }
}
