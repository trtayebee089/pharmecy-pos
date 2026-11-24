<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\MoneyTransfer;
use App\Services\Finance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Accounts, money transfers, expense/income categories and entries, balance sheet (pos/app/types/finance.ts). */
class FinanceController extends Controller
{
    // ------------------------------------------------------------ accounts

    public function accounts(): JsonResponse
    {
        $balances = Finance::balances();

        return response()->json(Account::orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($a) => $this->presentAccount($a, $balances[$a->id] ?? 0))->all());
    }

    public function storeAccount(Request $request, Finance $finance): JsonResponse
    {
        $a = $finance->saveAccount($request->user(), $this->accountRules($request));

        return response()->json($this->presentAccount($a, Finance::balance($a->id)), 201);
    }

    public function updateAccount(Request $request, Account $account, Finance $finance): JsonResponse
    {
        $a = $finance->saveAccount($request->user(), $this->accountRules($request, $account), $account);

        return response()->json($this->presentAccount($a, Finance::balance($a->id)));
    }

    public function activate(Request $request, Account $account, Finance $finance): JsonResponse
    {
        $finance->setActive($request->user(), $account, true);

        return response()->json(['isActive' => true]);
    }

    public function deactivate(Request $request, Account $account, Finance $finance): JsonResponse
    {
        $finance->setActive($request->user(), $account, false);

        return response()->json(['isActive' => false]);
    }

    public function makeDefault(Request $request, Account $account, Finance $finance): JsonResponse
    {
        $finance->setDefault($request->user(), $account);

        return response()->json(['isDefault' => true]);
    }

    public function statement(Request $request, Account $account, Finance $finance): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json($finance->statement($account, $data['from'], $data['to']));
    }

    // ------------------------------------------------------------ transfers

    public function transfers(): JsonResponse
    {
        return response()->json(MoneyTransfer::with('from', 'to', 'creator')->orderByDesc('transfer_date')->orderByDesc('id')->limit(500)->get()
            ->map(fn ($t) => $this->presentTransfer($t))->all());
    }

    public function storeTransfer(Request $request, Finance $finance): JsonResponse
    {
        $data = $request->validate([
            'fromAccountId' => ['required', 'integer'], 'toAccountId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'], 'date' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->presentTransfer($finance->transfer($request->user(), $data)), 201);
    }

    public function voidTransfer(Request $request, MoneyTransfer $transfer, Finance $finance): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json($this->presentTransfer($finance->voidTransfer($request->user(), $transfer, trim($data['reason']))));
    }

    // ------------------------------------------------------------ categories

    public function categories(Request $request, string $kind): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'all' => ['nullable'], 'page' => ['nullable', 'integer'], 'perPage' => ['nullable', 'integer', 'max:200']]);
        $rows = FinanceCategory::where('kind', $this->categoryKind($kind))->with('parent')->withCount('entries')
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%$s%"))->orderBy('name')->get()
            ->map(fn ($c) => $this->presentCategory($c));
        if (filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json($rows->values()->all());
        }
        $perPage = (int) ($data['perPage'] ?? 25);
        $page = max(1, (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values()->all(),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $rows->count(), 'lastPage' => max(1, (int) ceil($rows->count() / $perPage))],
        ]);
    }

    public function showCategory(int $id, string $kind): JsonResponse
    {
        return response()->json($this->presentCategory(FinanceCategory::where('kind', $this->categoryKind($kind))->withCount('entries')->findOrFail($id)));
    }

    public function storeCategory(Request $request, string $kind): JsonResponse
    {
        $c = new FinanceCategory(['kind' => $this->categoryKind($kind)]);
        $this->fillCategory($request, $c)->save();

        return response()->json($this->presentCategory($c->loadCount('entries')), 201);
    }

    public function updateCategory(Request $request, int $id, string $kind): JsonResponse
    {
        $c = FinanceCategory::where('kind', $this->categoryKind($kind))->findOrFail($id);
        $this->fillCategory($request, $c)->save();

        return response()->json($this->presentCategory($c->loadCount('entries')));
    }

    public function destroyCategory(int $id, string $kind): JsonResponse
    {
        $c = FinanceCategory::where('kind', $this->categoryKind($kind))->withCount('entries')->findOrFail($id);
        if ($c->entries_count > 0) {
            throw ValidationException::withMessages(['id' => "{$c->name} has {$c->entries_count} entries. Deactivate it instead."]);
        }
        if (FinanceCategory::where('parent_id', $c->id)->exists()) {
            throw ValidationException::withMessages(['id' => "{$c->name} has sub-categories."]);
        }
        $c->delete();

        return response()->json(['deleted' => true]);
    }

    // ------------------------------------------------------------ entries

    public function entries(string $kinds): JsonResponse
    {
        return response()->json(FinanceEntry::where('kind', Finance::KINDS[$kinds])->with('category', 'account', 'creator')
            ->orderByDesc('entry_date')->orderByDesc('id')->limit(1000)->get()->map(fn ($e) => $this->presentEntry($e))->all());
    }

    public function storeEntry(Request $request, string $kinds, Finance $finance): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'categoryId' => ['required', 'integer'], 'accountId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->presentEntry($finance->postEntry($request->user(), Finance::KINDS[$kinds], $data)), 201);
    }

    // Route parameters ({entry}) come before the route default (kinds).
    public function voidEntry(Request $request, FinanceEntry $entry, string $kinds, Finance $finance): JsonResponse
    {
        abort_unless($entry->kind === Finance::KINDS[$kinds], 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json($this->presentEntry($finance->voidEntry($request->user(), $entry, trim($data['reason']))));
    }

    /** Attaches the bill or receipt (PDF or image, at most 5 MB). Replaces an earlier one. */
    public function attach(Request $request, FinanceEntry $entry, string $kinds): JsonResponse
    {
        abort_unless($entry->kind === Finance::KINDS[$kinds], 404);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']]);
        $old = $entry->attachment_path;
        $path = $request->file('file')->store('finance/'.$entry->kind, 'local');
        $entry->update(['attachment_path' => $path]);
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json($this->presentEntry($entry->fresh()), 201);
    }

    /** A signed link valid for 5 minutes (the file itself is never public). */
    public function attachmentUrl(FinanceEntry $entry, string $kinds): JsonResponse
    {
        abort_unless($entry->kind === Finance::KINDS[$kinds] && $entry->attachment_path, 404);

        return response()->json(['url' => URL::temporarySignedRoute('finance.attachment', now()->addMinutes(5), ['entry' => $entry->id])]);
    }

    public function downloadAttachment(FinanceEntry $entry): StreamedResponse
    {
        abort_unless($entry->attachment_path && Storage::disk('local')->exists($entry->attachment_path), 404);

        return Storage::disk('local')->response($entry->attachment_path, $entry->number.'.'.pathinfo($entry->attachment_path, PATHINFO_EXTENSION));
    }

    // ------------------------------------------------------------ balance sheet

    public function balanceSheet(Request $request, Finance $finance): JsonResponse
    {
        $data = $request->validate(['as_of' => ['required', 'date_format:Y-m-d']]);

        return response()->json($finance->balanceSheet($data['as_of']));
    }

    // ------------------------------------------------------------ presenters

    private function presentAccount(Account $a, int $balance): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'accountNo' => (string) $a->account_no,
            'openingBalance' => (float) $a->opening_balance, 'balance' => $balance / 100, 'isDefault' => $a->is_default, 'isActive' => $a->is_active,
        ];
    }

    private function presentTransfer(MoneyTransfer $t): array
    {
        $t->loadMissing('from', 'to', 'creator');

        return [
            'id' => $t->id, 'number' => $t->number, 'date' => substr((string) $t->transfer_date, 0, 10),
            'fromAccount' => $t->from?->name ?? '', 'toAccount' => $t->to?->name ?? '', 'fromAccountId' => $t->from_account_id, 'toAccountId' => $t->to_account_id,
            'amount' => (float) $t->amount, 'note' => (string) $t->note, 'createdBy' => $t->creator?->name ?? '',
            'status' => $t->status, 'voidReason' => $t->void_reason,
        ];
    }

    private function presentCategory(FinanceCategory $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'parent' => (string) $c->parent?->name, 'entries' => (int) ($c->entries_count ?? 0), 'isActive' => $c->is_active];
    }

    private function presentEntry(FinanceEntry $e): array
    {
        $e->loadMissing('category', 'account', 'creator');

        return [
            'id' => $e->id, 'number' => $e->number, 'date' => substr((string) $e->entry_date, 0, 10), 'category' => $e->category?->name ?? '',
            'account' => $e->account?->name ?? '', 'amount' => (float) $e->amount, 'note' => (string) $e->note,
            'hasAttachment' => (bool) $e->attachment_path, 'status' => $e->status, 'createdBy' => $e->creator?->name ?? '', 'voidReason' => $e->void_reason,
        ];
    }

    private function accountRules(Request $request, ?Account $account = null): array
    {
        // Codes are stored upper-case: compare them that way too.
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:cash,bank,mobile_wallet'],
            'accountNo' => ['nullable', 'string', 'max:60'],
            'openingBalance' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
    }

    private function fillCategory(Request $request, FinanceCategory $c): FinanceCategory
    {
        $partial = $request->isMethod('put') && ! $request->has('name');
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('finance_categories', 'name')->where('kind', $c->kind)->ignore($c->id)],
            'parent' => ['sometimes', 'nullable', 'string', 'max:100'],
            'isActive' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('name', $data)) {
            $c->name = trim($data['name']);
        }
        if (array_key_exists('isActive', $data)) {
            $c->is_active = $data['isActive'];
        }
        if (array_key_exists('parent', $data)) {
            $parent = ($data['parent'] ?? '') !== '' ? FinanceCategory::where(['kind' => $c->kind, 'name' => $data['parent']])->first() : null;
            if (($data['parent'] ?? '') !== '' && (! $parent || $parent->id === $c->id || $parent->parent_id)) {
                throw ValidationException::withMessages(['parent' => 'Choose a top-level category as parent.']);
            }
            $c->parent_id = $parent?->id;
        }

        return $c;
    }

    private function categoryKind(string $kind): string
    {
        return ['expense-categories' => 'expense', 'income-categories' => 'income'][$kind] ?? abort(404);
    }
}
