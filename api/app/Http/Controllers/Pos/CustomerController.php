<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:500'],
            'search' => ['nullable', 'string', 'max:100'], 'due' => ['nullable'],
        ]);
        $query = Customer::query()->where('is_active', true)
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('code', $s)))
            ->when(filter_var($data['due'] ?? false, FILTER_VALIDATE_BOOLEAN), fn ($q) => $q->whereIn('id',
                DB::table('party_ledger_entries')->where('party_type', 'customer')->groupBy('party_id')
                    ->havingRaw('SUM(debit) - SUM(credit) > 0')->select('party_id')))
            ->orderByDesc('is_walk_in')->orderBy('name');
        $page = $query->paginate($data['perPage'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(Present::paginated($page, Present::customers(collect($page->items()))));
    }

    public function lookup(Request $request): JsonResponse
    {
        $s = trim((string) $request->query('q', ''));
        $rows = Customer::query()->where('is_active', true)
            ->when($s !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")))
            ->orderByDesc('is_walk_in')->orderBy('name')->limit(8)->get();

        return response()->json(Present::customers($rows));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'gender' => ['nullable', 'in:male,female,other,'],
            'address' => ['nullable', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:60'],
        ]);
        $phone = self::normalizePhone($data['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).']);
        }
        if (Customer::where('phone_normalized', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'A customer with this phone already exists.']);
        }

        $customer = DB::transaction(function () use ($data, $phone) {
            $c = Customer::create([
                'code' => 'TMP-'.uniqid(), 'name' => trim($data['name']), 'phone' => $phone, 'phone_normalized' => $phone,
                'email' => $data['email'] ?? null, 'gender' => ($data['gender'] ?? null) ?: null, 'address' => $data['address'] ?? null,
                'customer_group_id' => ! empty($data['group']) ? CustomerGroup::firstOrCreate(['name' => $data['group']])->id : null,
            ]);
            $c->update(['code' => 'C-'.str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)]);

            return $c;
        });
        AuditLog::record($request->user()->id, 'customer.created', 'customer', $customer->id, null, $request);

        return response()->json(Present::customers(collect([$customer]))[0], 201);
    }

    public static function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/^880/', '0', preg_replace('/\D/', '', $raw));

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }
}
