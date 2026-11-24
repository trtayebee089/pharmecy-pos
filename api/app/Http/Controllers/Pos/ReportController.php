<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Reports\PartyReports;
use App\Reports\ReportScope;
use App\Reports\SalesReports;
use App\Reports\StockReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * /reports/*: ReportResult (pos/app/types/domain.ts) for one warehouse (warehouse_id)
 * or every warehouse the user can access. Range from/to (inclusive dates);
 * other query keys are filters, an empty value meaning "all".
 */
class ReportController extends Controller
{
    private const FILTERS = [
        'sales' => ['channel', 'payment'], 'profit' => ['view'], 'billers' => ['biller'], 'custom-products' => ['product'],
        'stock' => ['manufacturer', 'form'], 'expiry' => ['window', 'manufacturer'], 'purchases' => ['view', 'supplier'],
        'customers' => ['membership', 'due'], 'customer' => ['customer'], 'suppliers' => ['due'], 'payments' => ['direction', 'method'], 'cashiers' => ['register'],
    ];

    public function show(Request $request, string $report, SalesReports $sales, StockReports $stock, PartyReports $parties): JsonResponse
    {
        abort_unless(isset(self::FILTERS[$report]), 404);
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $scope = new ReportScope(
            $this->warehouses($request),
            $data['from'] ?? now()->subDays(29)->toDateString(),
            $data['to'] ?? now()->toDateString(),
            collect(self::FILTERS[$report])->mapWithKeys(fn ($k) => [$k => (string) $request->query($k, '')])->all(),
        );

        return response()->json(match ($report) {
            'sales' => $sales->sales($scope), 'profit' => $sales->profit($scope), 'billers' => $sales->billers($scope),
            'custom-products' => $sales->customProducts($scope), 'stock' => $stock->stock($scope), 'expiry' => $stock->expiry($scope),
            'purchases' => $parties->purchases($scope), 'customers' => $parties->customers($scope), 'customer' => $parties->customer($scope),
            'suppliers' => $parties->suppliers($scope), 'payments' => $parties->payments($scope), 'cashiers' => $parties->cashiers($scope),
        });
    }

    public function monthlySummary(Request $request, SalesReports $sales): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'min:1', 'max:12'], 'warehouse_id' => ['nullable', 'integer']]);

        return response()->json($sales->monthlySummary($this->warehouses($request), (int) $data['year'], (int) $data['month']));
    }

    /** Filter values for the report pages (names, not ids). */
    public function options(Request $request): JsonResponse
    {
        $names = fn (string $table) => DB::table($table)->when(DB::getSchemaBuilder()->hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('name')->distinct()->pluck('name')->values()->all();

        return response()->json([
            'manufacturers' => $names('manufacturers'), 'forms' => $names('dosage_forms'), 'suppliers' => $names('suppliers'),
            'customItems' => DB::table('sale_items as si')->join('sales as x', 'x.id', '=', 'si.sale_id')->where('si.line_type', 'misc')
                ->whereIn('x.warehouse_id', $this->warehouses($request))->distinct()->orderBy('si.description')->pluck('si.description')->all(),
        ]);
    }

    /** A biller is the branch an invoice is issued from. */
    public function billers(Request $request): JsonResponse
    {
        return response()->json(Warehouse::whereIn('id', $this->warehouses($request, all: true))->orderBy('name')->get(['id', 'name']));
    }

    /** @return array<int> */
    private function warehouses(Request $request, bool $all = false): array
    {
        $user = $request->user();
        $id = $all ? null : $request->query('warehouse_id');
        if ($id !== null && $id !== '') {
            if (! $user->canAccessWarehouse((int) $id)) {
                throw ValidationException::withMessages(['warehouse_id' => 'You are not assigned to this warehouse.']);
            }

            return [(int) $id];
        }

        return ($user->isAdmin() ? Warehouse::pluck('id') : $user->warehouses()->pluck('warehouses.id'))->all();
    }
}
