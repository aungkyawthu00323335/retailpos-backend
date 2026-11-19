<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\FifoInventoryService;
use App\Services\ProductUnitConversionService;
use App\Services\PurchaseAccountingService;
use App\Support\ListCursorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class TransferController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;

    private const LIST_TOTAL_CACHE_KEY = 'stock_transfers_list_total_v1';

    protected function escapeCsvCell($value): string
    {
        $str = (string)($value ?? '');
        if ($str === '') {
            return '';
        }
        $trimmed = ltrim($str);
        if ($trimmed !== '') {
            $first = $trimmed[0];
            if (in_array($first, ['=', '+', '@', "\t", "\r"], true)
                || ($first === '-' && !preg_match('/^-\d+(\.\d+)?$/', $trimmed))
            ) {
                return "'" . $str;
            }
        }
        return $str;
    }

    protected function throwValidationError(string $field, string $message): never
    {
        $validator = Validator::make([], []);
        $validator->errors()->add($field, $message);
        throw new ValidationException(
            validator: $validator,
            response: response()->json([
                'status'  => 'error',
                'message' => $message,
                'errors'  => [$field => [$message]],
            ], 422)
        );
    }

    protected function maskTransferCostFields(array $transferData, bool $canViewCost): array
    {
        if ($canViewCost) {
            return $transferData;
        }
        if (array_key_exists('items_valuation', $transferData)) {
            $transferData['items_valuation'] = null;
        }
        if (!empty($transferData['items']) && is_array($transferData['items'])) {
            $transferData['items'] = array_map(static function ($item) {
                if (is_array($item)) {
                    $item['unit_cost'] = null;
                    $item['base_unit_cost'] = null;
                    $item['subtotal'] = null;
                }
                return $item;
            }, $transferData['items']);
        }
        return $transferData;
    }

    protected function findLinkedShippingExpense(StockTransfer $transfer, bool $lock = false): ?Expense
    {
        $query = Expense::with('payments')
            ->where(function ($q) use ($transfer) {
                $q->where('transfer_id', $transfer->id);
                $ref = trim((string)$transfer->reference_no);
                if ($ref !== '') {
                    $clean = preg_replace('/^(?:TR_|TR-)/', '', $ref);
                    $exactRefs = array_values(array_unique(array_filter([
                        $ref,
                        'EXP_TRF-' . $ref,
                        'EXP-TRF-' . $ref,
                        'TRF-' . $ref,
                        'EXP_TRF-' . $clean,
                        'EXP-TRF-' . $clean,
                    ])));
                    $q->orWhere(function ($q2) use ($exactRefs, $ref) {
                        $q2->whereNull('transfer_id')
                           ->where('expense_category_name', 'Transfer Shipping')
                           ->where(function ($q3) use ($exactRefs, $ref) {
                               $q3->whereIn('reference_no', $exactRefs)
                                  ->orWhere('title', 'like', "Shipping for Transfer #{$ref}%")
                                  ->orWhere('details', 'Auto-created from Stock Transfer #' . $ref);
                           });
                    });
                }
            });

        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->orderByDesc('transfer_id')->orderBy('id')->first();
    }

    protected function resolveTransferActor(Request $request, array $validated, ?StockTransfer $existing = null): array
    {
        $authUser = User::resolveFromRequest($request) ?? $request->user();
        $authRole = $authUser?->roleModel?->name ?? ($authUser?->role ?? null);
        $isAdmin = $authUser && (
            (method_exists($authUser, 'isSuperAdmin') && $authUser->isSuperAdmin()) ||
            in_array(strtolower((string)$authRole), ['super admin', 'admin', 'manager'], true)
        );

        if ($existing) {
            return [
                'user_id'   => $existing->user_id ?? $authUser?->id,
                'user_name' => $existing->user_name ?: ($existing->created_by ?: ($authUser?->name ?? 'Admin User')),
                'user_role' => $existing->user_role ?: ($authRole ?? 'Super Admin'),
            ];
        }

        if ($authUser && !$isAdmin) {
            return [
                'user_id'   => $authUser->id,
                'user_name' => $authUser->name,
                'user_role' => $authRole ?: 'Cashier',
            ];
        }

        $userName = !empty($validated['user_name'])
            ? $validated['user_name']
            : (!empty($validated['created_by']) ? $validated['created_by'] : ($authUser?->name ?? 'Admin User'));
        $userRole = !empty($validated['user_role'])
            ? $validated['user_role']
            : ($authRole ?? 'Super Admin');
        $userId = !empty($validated['user_id']) ? (int)$validated['user_id'] : $authUser?->id;

        return [
            'user_id'   => $userId,
            'user_name' => $userName,
            'user_role' => $userRole,
        ];
    }

    protected function generateUniqueExpensePaymentRef(string $expenseRef, int $seedIndex = 1): string
    {
        $idx = max(1, $seedIndex);
        $cand = 'PAY-' . $expenseRef . '-' . $idx;
        while (ExpensePayment::where('reference_no', $cand)->exists()) {
            $cand = 'PAY-' . $expenseRef . '-' . date('YmdHis') . '-' . Str::upper(Str::random(4)) . '-' . $idx;
            $idx++;
        }
        return $cand;
    }

    protected function syncTransferMovementUnitCosts(StockTransfer $transfer): void
    {
        if (!Schema::hasColumn('stock_movements', 'unit_cost')) {
            return;
        }
        foreach ($transfer->items as $item) {
            $baseCost = (float)($item->base_unit_cost ?? 0);
            if ($baseCost <= 0 || !$item->product_id) {
                continue;
            }
            $roundedCost = round($baseCost, 4);
            foreach (['stock_transfer_out', 'stock_transfer_in'] as $mvType) {
                $targetWh = $mvType === 'stock_transfer_out'
                    ? $transfer->from_warehouse_id
                    : $transfer->to_warehouse_id;
                if (!$targetWh) {
                    continue;
                }
                StockMovement::where('warehouse_id', (int)$targetWh)
                    ->where('product_id', (int)$item->product_id)
                    ->when(
                        $item->product_variant_id !== null && (int)$item->product_variant_id > 0,
                        fn($q) => $q->where('product_variant_id', (int)$item->product_variant_id),
                        fn($q) => $q->whereNull('product_variant_id')
                    )
                    ->where('movement_type', $mvType)
                    ->where('reference_type', 'stock_transfer')
                    ->where('reference_id', (string)$transfer->id)
                    ->update(['unit_cost' => $roundedCost]);
            }
        }
    }

    protected function resolveActiveOpenCashRegisterId(
        ?int $explicitRegId,
        ?int $warehouseId,
        ?string $warehouseName,
        ?int $ownerId,
        ?string $ownerName,
        bool $requireOpenIfExplicit = true
    ): ?int {
        if ($explicitRegId) {
            $reg = CashRegister::lockForUpdate()->find($explicitRegId);
            if (!$reg || !in_array(strtolower(trim((string)$reg->status)), ['open', 'opened'], true)) {
                if ($requireOpenIfExplicit) {
                    $this->throwValidationError('cash_register_id', 'Selected cash register is closed or does not exist.');
                }
                return $explicitRegId;
            }
            if ($warehouseId && $reg->warehouse_id && (int)$reg->warehouse_id !== (int)$warehouseId) {
                $this->throwValidationError('cash_register_id', 'Selected cash register belongs to a different branch/warehouse.');
            }
            return (int)$reg->id;
        }
        if (!$warehouseId && empty($warehouseName)) {
            return null;
        }
        $query = CashRegister::whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened'])
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId), fn($q) => $q->where('warehouse_name', $warehouseName));
        if ($ownerId || $ownerName) {
            $owned = (clone $query)->where(function ($q) use ($ownerId, $ownerName) {
                if ($ownerId) $q->where('user_id', $ownerId);
                if ($ownerName) $q->orWhere('user_name', $ownerName);
            })->orderByDesc('id')->lockForUpdate()->first();
            if ($owned) return (int)$owned->id;
        }
        // Strict shift isolation: never silently attach another cashier's open register
        return null;
    }

    private function buildFilteredQuery(Request $request, bool $noItems = false)
    {
        $accounting = app(PurchaseAccountingService::class);
        $query = StockTransfer::select([
            'id', 'reference_no', 'from_warehouse_id', 'from_warehouse',
            'to_warehouse_id', 'to_warehouse', 'date', 'transfer_time',
            'items_count', 'total_quantity', 'shipping_charge', 'status',
            'notes', 'received_by', 'received_at', 'receiving_notes',
            'created_by', 'user_id', 'user_name', 'user_role',
            'created_at', 'updated_at',
        ]);

        if (!$noItems) {
            $query->with([
                'user:id,name,role',
                'items.variant.values',
            ]);
        }

        $allowed = $accounting->extractAllowedOutletIds($request);
        if (!empty($allowed)) {
            $query->where(function ($q) use ($allowed) {
                $q->whereIn('from_warehouse_id', $allowed)->orWhereIn('to_warehouse_id', $allowed);
            });
        }

        $whInput = $request->filled('warehouse_id') ? $request->input('warehouse_id') : $request->input('warehouse');
        if (!empty($whInput) && $whInput !== 'All') {
            $wh = trim((string) $whInput);
            $query->where(function ($q) use ($wh) {
                if (is_numeric($wh)) {
                    $q->where('from_warehouse_id', (int)$wh)->orWhere('to_warehouse_id', (int)$wh);
                } else {
                    $q->where('from_warehouse', $wh)->orWhere('to_warehouse', $wh);
                }
            });
        }

        $fromWh = $request->filled('from_warehouse_id') ? $request->input('from_warehouse_id') : $request->input('from_warehouse');
        if (!empty($fromWh) && $fromWh !== 'All') {
            if (is_numeric($fromWh)) {
                $query->where('from_warehouse_id', (int)$fromWh);
            } else {
                $query->where('from_warehouse', $fromWh);
            }
        }

        $toWh = $request->filled('to_warehouse_id') ? $request->input('to_warehouse_id') : $request->input('to_warehouse');
        if (!empty($toWh) && $toWh !== 'All') {
            if (is_numeric($toWh)) {
                $query->where('to_warehouse_id', (int)$toWh);
            } else {
                $query->where('to_warehouse', $toWh);
            }
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $userFilter = $request->input('created_by') ?: $request->input('user');
        if (!empty($userFilter) && $userFilter !== 'All') {
            $cb = trim((string)$userFilter);
            $query->where(function ($q) use ($cb) {
                $q->where('created_by', $cb)
                  ->orWhere('created_by', 'like', $cb . ' (%')
                  ->orWhere('user_name', $cb);
            });
        }
        if ($request->filled('user_id') && $request->input('user_id') !== 'All') {
            $query->where('user_id', (int)$request->input('user_id'));
        }

        $df = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
        $dt = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
        $accounting->applyDateRangeFilter($query, $df ? (string)$df : null, $dt ? (string)$dt : null, 'date');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $cleanSearch = preg_replace('/[-_ ]/', '', $search);
            $altHyphen = str_replace('_', '-', $search);
            $altUnderscore = str_replace('-', '_', $search);
            $query->where(function ($q) use ($search, $cleanSearch, $altHyphen, $altUnderscore) {
                if (preg_match('/^(TR|TRF)[-_]/i', $search)) {
                    $q->where('reference_no', 'like', "{$search}%");
                    if ($altHyphen !== $search) {
                        $q->orWhere('reference_no', 'like', "{$altHyphen}%");
                    }
                    if ($altUnderscore !== $search) {
                        $q->orWhere('reference_no', 'like', "{$altUnderscore}%");
                    }
                } else {
                    $q->where('reference_no', 'like', "%{$search}%");
                    if (!empty($cleanSearch)) {
                        $q->orWhereRaw("REPLACE(REPLACE(reference_no, '_', ''), '-', '') LIKE ?", ["%{$cleanSearch}%"]);
                    }
                    $q->orWhere('from_warehouse', 'like', "%{$search}%")
                      ->orWhere('to_warehouse', 'like', "%{$search}%")
                      ->orWhere('notes', 'like', "%{$search}%")
                      ->orWhere('created_by', 'like', "%{$search}%")
                      ->orWhere('user_name', 'like', "%{$search}%")
                      ->orWhere('user_role', 'like', "%{$search}%")
                      ->orWhereHas('items', function ($iq) use ($search) {
                          $iq->where('product_name', 'like', "%{$search}%")
                             ->orWhere('product_code', 'like', "%{$search}%");
                      });
                }
            });
        }

        return $query;
    }

    private function hasActiveFilters(Request $request): bool
    {
        return ($request->filled('warehouse') && $request->input('warehouse') !== 'All')
            || $request->filled('warehouse_id')
            || ($request->filled('from_warehouse') && $request->input('from_warehouse') !== 'All')
            || $request->filled('from_warehouse_id')
            || ($request->filled('to_warehouse') && $request->input('to_warehouse') !== 'All')
            || $request->filled('to_warehouse_id')
            || ($request->filled('status') && $request->input('status') !== 'All')
            || ($request->filled('created_by') && $request->input('created_by') !== 'All')
            || ($request->filled('user') && $request->input('user') !== 'All')
            || $request->filled('user_id')
            || $request->filled('from_date')
            || $request->filled('date_from')
            || $request->filled('start_date')
            || $request->filled('to_date')
            || $request->filled('date_to')
            || $request->filled('end_date')
            || $request->filled('search');
    }
    /**
     * Generate next sequential transfer reference code (e.g. TK_0010001).
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('transfer'));
    }

    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $query = $this->buildFilteredQuery($request, true);

        $sortMap = [
            'id' => 'id',
            'reference' => 'reference_no',
            'reference_no' => 'reference_no',
            'fromWarehouse' => 'from_warehouse',
            'from_warehouse' => 'from_warehouse',
            'toWarehouse' => 'to_warehouse',
            'to_warehouse' => 'to_warehouse',
            'date' => 'date',
            'items' => 'items_count',
            'items_count' => 'items_count',
            'total_quantity' => 'total_quantity',
            'shipping' => 'shipping_charge',
            'shipping_charge' => 'shipping_charge',
            'status' => 'status',
            'createdBy' => 'created_by',
            'created_by' => 'created_by',
            'created_at' => 'created_at',
        ];
        $rawSort = (string)$request->input('sort_by', 'id');
        $sortColumn = $sortMap[$rawSort] ?? 'id';
        $sortDirection = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="transfers_export_' . date('Ymd_His') . '.csv"',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $callback = function () use ($query, $request) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, ['Stock Transfers Export']);
            fputcsv($output, ['Generated At', date('Y-m-d H:i:s')]);
            $filterMeta = [];
            if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
                $filterMeta[] = ['Warehouse', $request->input('warehouse')];
            }
            if ($request->filled('from_warehouse') && $request->input('from_warehouse') !== 'All') {
                $filterMeta[] = ['From Warehouse', $request->input('from_warehouse')];
            }
            if ($request->filled('to_warehouse') && $request->input('to_warehouse') !== 'All') {
                $filterMeta[] = ['To Warehouse', $request->input('to_warehouse')];
            }
            if ($request->filled('status') && $request->input('status') !== 'All') {
                $filterMeta[] = ['Status', $request->input('status')];
            }
            $uFilter = $request->input('created_by') ?: $request->input('user');
            if (!empty($uFilter) && $uFilter !== 'All') {
                $filterMeta[] = ['Created By', $uFilter];
            }
            $fDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
            $tDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
            if (!empty($fDate) || !empty($tDate)) {
                $filterMeta[] = ['Date Range', trim(($fDate ?: 'Start') . ' to ' . ($tDate ?: 'End'))];
            }
            if ($request->filled('search')) {
                $filterMeta[] = ['Search', $request->input('search')];
            }
            foreach ($filterMeta as $fm) {
                fputcsv($output, [$fm[0], $this->escapeCsvCell($fm[1])]);
            }
            fputcsv($output, []);

            fputcsv($output, [
                'SN', 'Reference No', 'From Warehouse', 'To Warehouse',
                'Status', 'Items Count', 'Total Quantity', 'Shipping Charge',
                'Created By', 'User Role', 'Date', 'Time', 'Notes'
            ]);

            $sn = 1;
            $totalQty = 0.0;
            $totalShipping = 0.0;
            $flushCount = 0;

            foreach ($query->toBase()->cursor() as $t) {
                $qty = (float)$t->total_quantity;
                $shipping = (float)$t->shipping_charge;
                $totalQty += $qty;
                $totalShipping += $shipping;

                $userName = $t->user_name ?: ($t->created_by ?: 'System');
                $userRole = $t->user_role ?: 'Warehouse Staff';

                $dateOnly = $t->date ? explode(' ', trim($t->date))[0] : '';
                $timeStr = $t->transfer_time ?: '';
                if (empty($timeStr) && $t->created_at) {
                    $timeStr = date('H:i:s', strtotime($t->created_at));
                }

                fputcsv($output, [
                    $sn++,
                    $this->escapeCsvCell($t->reference_no),
                    $this->escapeCsvCell($t->from_warehouse),
                    $this->escapeCsvCell($t->to_warehouse),
                    $this->escapeCsvCell($t->status),
                    $t->items_count ?: 0,
                    number_format($qty, 2, '.', ''),
                    number_format($shipping, 2, '.', ''),
                    $this->escapeCsvCell($userName),
                    $this->escapeCsvCell($userRole),
                    $dateOnly,
                    $timeStr,
                    $this->escapeCsvCell($t->notes ?: ''),
                ]);

                if (++$flushCount % 500 === 0) {
                    flush();
                }
            }

            fputcsv($output, [
                'TOTAL', ($sn - 1) . ' Transfers', '—', '—', '—', '—',
                number_format($totalQty, 2, '.', ''),
                number_format($totalShipping, 2, '.', ''),
                '—', '—', '—', '—', '—'
            ]);

            fclose($output);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function index(Request $request)
    {
        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        $noItems = !$isAll && $request->boolean('no_items');
        $skipSummary = $request->boolean('skip_summary');
        $hasActiveFilters = $this->hasActiveFilters($request);

        $query = $this->buildFilteredQuery($request, $noItems);

        $sortMap = [
            'id' => 'id',
            'reference' => 'reference_no',
            'reference_no' => 'reference_no',
            'fromWarehouse' => 'from_warehouse',
            'from_warehouse' => 'from_warehouse',
            'toWarehouse' => 'to_warehouse',
            'to_warehouse' => 'to_warehouse',
            'date' => 'date',
            'items' => 'items_count',
            'items_count' => 'items_count',
            'total_quantity' => 'total_quantity',
            'shipping' => 'shipping_charge',
            'shipping_charge' => 'shipping_charge',
            'status' => 'status',
            'createdBy' => 'created_by',
            'created_by' => 'created_by',
            'created_at' => 'created_at',
        ];
        $rawSort = (string) $request->input('sort_by', 'date');
        $sortBy = $sortMap[$rawSort] ?? 'date';
        $sortOrder = strtolower((string) $request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $orderedQuery = (clone $query)->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'id') {
            $orderedQuery->orderBy('id', $sortOrder);
        }

        $accounting = app(PurchaseAccountingService::class);
        $canViewCost = $accounting->canViewCost($request);
        $knownTotal = $request->filled('known_total') ? max(0, (int) $request->input('known_total')) : null;
        $page = max(1, (int) $request->input('page', 1));
        $perPage = $isAll
            ? min(500, max(1, (int) $request->input('limit', 500)))
            : min(1000, max(1, (int) $request->input('per_page', 50)));
        $cursorAllowed = [
            'id', 'reference_no', 'from_warehouse', 'to_warehouse', 'date',
            'items_count', 'total_quantity', 'shipping_charge', 'status', 'created_by', 'created_at',
        ];
        $cursorData = !$isAll
            ? ListCursorHelper::decode(
                (string) $request->input('cursor', ''),
                $sortBy,
                $sortOrder,
                $cursorAllowed,
                ['id', 'items_count'],
                ['total_quantity', 'shipping_charge']
            )
            : null;
        $isScrollPage = ($page > 1 || $cursorData !== null);

        if ($knownTotal !== null && $skipSummary && $isScrollPage) {
            $totalCount = $knownTotal;
        } else {
            $totalCount = (clone $query)->count();
        }

        $attachMeta = function ($items) use ($noItems, $canViewCost) {
            if ($items->isEmpty()) {
                return $items;
            }

            $valuationMap = [];
            if ($noItems && $canViewCost) {
                $ids = $items->pluck('id')->filter()->values()->all();
                if (!empty($ids)) {
                    $valuationMap = DB::table('stock_transfer_items')
                        ->selectRaw('stock_transfer_id, COALESCE(SUM(subtotal), 0) as items_valuation')
                        ->whereIn('stock_transfer_id', $ids)
                        ->groupBy('stock_transfer_id')
                        ->pluck('items_valuation', 'stock_transfer_id')
                        ->all();
                }
            }

            $shippingItems = $items->filter(function ($t) {
                return (float) ($t->shipping_charge ?? 0) > 0;
            });

            $expenseByTransferId = [];
            $expenseMap = [];
            if ($shippingItems->isNotEmpty()) {
                $shippingIds = $shippingItems->pluck('id')->filter()->values()->all();
                $refs = $shippingItems->pluck('reference_no')->filter()->values()->all();
                $candidateExpenseRefs = [];
                foreach ($refs as $r) {
                    $clean = preg_replace('/^(?:TR_|TR-)/', '', $r);
                    $candidateExpenseRefs[] = $r;
                    $candidateExpenseRefs[] = 'EXP_TRF-' . $r;
                    $candidateExpenseRefs[] = 'EXP-TRF-' . $r;
                    $candidateExpenseRefs[] = 'TRF-' . $r;
                    $candidateExpenseRefs[] = 'EXP_TRF-' . $clean;
                    $candidateExpenseRefs[] = 'EXP-TRF-' . $clean;
                }
                $candidateExpenseRefs = array_values(array_unique(array_filter($candidateExpenseRefs)));

                $expensesQuery = $noItems
                    ? Expense::select(['id', 'transfer_id', 'reference_no', 'title', 'details', 'paid_amount', 'due_amount', 'payment_status', 'payment_method'])
                    : Expense::with('payments');

                $expenses = $expensesQuery->where(function ($q) use ($shippingIds, $candidateExpenseRefs) {
                    if (!empty($shippingIds)) {
                        $q->whereIn('transfer_id', $shippingIds);
                    }
                    if (!empty($candidateExpenseRefs)) {
                        $q->orWhereIn('reference_no', $candidateExpenseRefs);
                    }
                })->get();

                foreach ($expenses as $e) {
                    if (!empty($e->transfer_id)) {
                        $expenseByTransferId[(int)$e->transfer_id] = $e;
                    }
                    if (preg_match('/#([^\s,→]+)/', $e->details ?? '', $m)) {
                        $expenseMap[$m[1]] = $e;
                    }
                    if (preg_match('/(?:TRF-|EXP-|EXP_)(.+)$/', $e->reference_no, $m)) {
                        $expenseMap[$m[1]] = $e;
                    }
                    $expenseMap[$e->reference_no] = $e;
                }
            }

            return $items->map(function ($t) use ($expenseByTransferId, $expenseMap, $valuationMap, $noItems, $canViewCost) {
                $arr = $t->toArray();
                if ($noItems) {
                    $arr['items'] = [];
                    $arr['items_valuation'] = $canViewCost ? (float) ($valuationMap[$t->id] ?? 0.0) : null;
                    $arr['payments'] = [];
                }

                $charge = (float) ($t->shipping_charge ?? 0);
                if ($charge <= 0) {
                    $arr['payments'] = [];
                    $arr['shipping_paid'] = 0.0;
                    $arr['shipping_due'] = 0.0;
                    $arr['shipping_payment_status'] = 'Free';
                    $arr['payment_method'] = 'N/A';
                    return $this->maskTransferCostFields($arr, $canViewCost);
                }

                $ref = (string) ($t->reference_no ?? '');
                $clean = preg_replace('/^(?:TR_|TR-)/', '', $ref);
                $exp = $expenseByTransferId[(int)$t->id] ?? ($expenseMap[$ref] ?? ($expenseMap[$clean] ?? ($expenseMap['EXP_TRF-' . $ref] ?? null)));

                $arr['payments'] = $noItems ? [] : ($exp ? ($exp->payments ?? []) : []);
                $arr['shipping_paid'] = $exp ? (float) $exp->paid_amount : 0.0;
                $arr['shipping_due'] = $exp ? (float) $exp->due_amount : $charge;
                $arr['shipping_payment_status'] = $exp ? $exp->payment_status : 'Unpaid';
                $arr['payment_method'] = $exp ? ($exp->payment_method ?: ($arr['shipping_paid'] > 0 ? 'Cash' : 'Unpaid')) : 'Unpaid';
                return $this->maskTransferCostFields($arr, $canViewCost);
            });
        };

        if ($isAll) {
            $items = $orderedQuery->take($perPage)->get();
            $hasMore = false;
        } else {
            if ($cursorData !== null) {
                ListCursorHelper::apply($orderedQuery, $sortBy, $sortOrder, $cursorData);
                $fetched = $orderedQuery->limit($perPage + 1)->get();
            } else {
                $fetched = $orderedQuery->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
            }
            $hasMore = $fetched->count() > $perPage;
            $items = $hasMore ? $fetched->slice(0, $perPage)->values() : $fetched;
        }

        $lastRowObj = $items->last();
        $nextCursor = (!$isAll && $hasMore && $lastRowObj !== null)
            ? ListCursorHelper::encode((object) $lastRowObj, $sortBy, $sortOrder, $cursorAllowed)
            : null;
        $dataWithMeta = $attachMeta($items);
        $lastPage = max(1, (int) ceil($totalCount / max(1, $perPage)));

        $pagination = [
            'current_page' => $isAll ? 1 : $page,
            'per_page' => $isAll ? $items->count() : $perPage,
            'total' => $totalCount,
            'last_page' => $lastPage,
            'has_more' => $hasMore,
        ];
        if ($nextCursor !== null) {
            $pagination['next_cursor'] = $nextCursor;
        }

        return response()->json([
            'status' => 'success',
            'data' => $dataWithMeta,
            'pagination' => $pagination,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    public function show(Request $request, $id)
    {
        $transfer = StockTransfer::with(['items.variant.values', 'user:id,name,role'])->find($id)
            ?? StockTransfer::with(['items.variant.values', 'user:id,name,role'])->where('reference_no', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        $this->assertTransferWarehouseAccess($request, $transfer->from_warehouse_id, $transfer->to_warehouse_id, true);

        $expense = $this->findLinkedShippingExpense($transfer);
        $canViewCost = app(PurchaseAccountingService::class)->canViewCost($request);

        $transferData = $transfer->toArray();
        $transferData['payments'] = $expense ? $expense->payments : [];
        $transferData['shipping_paid'] = $expense ? (float)$expense->paid_amount : 0.0;
        $transferData['shipping_due'] = $expense ? (float)$expense->due_amount : (float)$transfer->shipping_charge;
        $transferData['shipping_payment_status'] = $expense ? $expense->payment_status : ($transfer->shipping_charge <= 0 ? 'Free' : 'Unpaid');
        $transferData['payment_method'] = $expense
            ? ($expense->payment_method ?: ($transferData['shipping_paid'] > 0 ? 'Cash' : 'Unpaid'))
            : ($transfer->shipping_charge <= 0 ? 'N/A' : 'Unpaid');

        $transferData = $this->maskTransferCostFields($transferData, $canViewCost);

        return response()->json(['status' => 'success', 'data' => $transferData]);
    }

    private function clearTransferCache(): void
    {
        app(PurchaseAccountingService::class)->invalidateAllCaches();
    }

    private function assertTransferWarehouseAccess(
        Request $request,
        ?int $fromWarehouseId,
        ?int $toWarehouseId = null,
        bool $allowEither = false
    ): void {
        $allowed = app(PurchaseAccountingService::class)->extractAllowedOutletIds($request);
        if (empty($allowed)) {
            return;
        }
        if ($allowEither) {
            $fromOk = $fromWarehouseId && in_array((int)$fromWarehouseId, $allowed, true);
            $toOk   = $toWarehouseId && in_array((int)$toWarehouseId, $allowed, true);
            if (!$fromOk && !$toOk && ($fromWarehouseId || $toWarehouseId)) {
                abort(403, 'Unauthorized warehouse access.');
            }
            return;
        }
        if ($fromWarehouseId && !in_array((int)$fromWarehouseId, $allowed, true)) {
            abort(403, 'Unauthorized source warehouse access.');
        }
    }

    private function resolveTransferWarehouses(
        string $fromName,
        ?int $fromId,
        string $toName,
        ?int $toId,
        ProductUnitConversionService $unitService
    ): array {
        $fromWarehouseId = $unitService->warehouseId($fromId, $fromName);
        $toWarehouseId   = $unitService->warehouseId($toId, $toName);
        $hasOutlets      = Outlet::query()->exists();

        if ($hasOutlets) {
            if (!$fromWarehouseId) {
                $this->throwValidationError('from_warehouse', 'Selected source warehouse/outlet could not be resolved.');
            }
            if (!$toWarehouseId) {
                $this->throwValidationError('to_warehouse', 'Selected destination warehouse/outlet could not be resolved.');
            }
        }

        if ($fromWarehouseId && $toWarehouseId && (int)$fromWarehouseId === (int)$toWarehouseId) {
            $this->throwValidationError('to_warehouse', 'Source and destination warehouses must be different.');
        }

        $canonicalFrom = ($fromWarehouseId ? Outlet::find($fromWarehouseId)?->name : null) ?? trim($fromName);
        $canonicalTo   = ($toWarehouseId ? Outlet::find($toWarehouseId)?->name : null) ?? trim($toName);

        return [
            $fromWarehouseId ? (int)$fromWarehouseId : null,
            $canonicalFrom,
            $toWarehouseId ? (int)$toWarehouseId : null,
            $canonicalTo,
        ];
    }

    private function resolveTransferLine(array $item, int $idx, ProductUnitConversionService $unitService): array
    {
        $productId = !empty($item['product_id']) ? (int)$item['product_id'] : null;
        $product   = $productId
            ? Product::with(['unitLinks', 'variants'])->find($productId)
            : Product::with(['unitLinks', 'variants'])->where('code', $item['product_code'])->first();

        if (!$product && ($productId !== null || Product::query()->exists())) {
            $field = $productId !== null ? "items.{$idx}.product_id" : "items.{$idx}.product_code";
            $this->throwValidationError($field, "Product '{$item['product_code']}' could not be found.");
        }

        $variantId = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
        $variant   = null;
        $qty       = round((float)$item['quantity'], 4);
        $unitId    = !empty($item['unit_id']) ? (int)$item['unit_id'] : null;
        $unitName  = isset($item['unit_name']) ? trim((string)$item['unit_name']) : '';
        $convRate  = max(0.0001, (float)($item['conversion_rate'] ?? 1.0));
        $baseCost  = isset($item['base_unit_cost']) && (float)$item['base_unit_cost'] > 0
            ? (float)$item['base_unit_cost']
            : (float)($item['unit_cost'] ?? 0);

        if ($product) {
            $isVariable = (bool)$product->has_variants
                || strtolower((string)$product->type) === 'variable'
                || $product->variants->isNotEmpty();

            if ($isVariable && !$variantId) {
                $this->throwValidationError(
                    "items.{$idx}.product_variant_id",
                    "Variant selection is required for variable product '{$product->name}'."
                );
            }
            if ($variantId !== null) {
                $variant = $product->variants->firstWhere('id', $variantId)
                    ?? ProductVariant::where('id', $variantId)->where('product_id', $product->id)->first();
                if (!$variant) {
                    $this->throwValidationError(
                        "items.{$idx}.product_variant_id",
                        "Selected variant does not belong to product '{$product->name}'."
                    );
                }
            }

            // Resolve authoritative conversion rate from active ProductUnitLink or base unit
            $resolvedUnit = $this->resolveTransferUnitForProduct($product, $variantId, $unitId, $unitName, $idx, $unitService);
            $unitId   = $resolvedUnit['unit_id'];
            $unitName = $resolvedUnit['unit_name'];
            $convRate = $resolvedUnit['conversion_rate'];

            $defaultBaseCost = (float)($variant?->cost_price ?: ($product->cost_price ?: ($product->purchase_price ?: 0)));
            if ($baseCost <= 0 && $defaultBaseCost > 0) {
                $baseCost = $defaultBaseCost;
            } elseif (empty($item['base_unit_cost']) && !empty($item['unit_cost']) && $convRate > 0) {
                $baseCost = (float)$item['unit_cost'] / $convRate;
            }
        } elseif (empty($item['base_unit_cost']) && !empty($item['unit_cost']) && $convRate > 0) {
            $baseCost = (float)$item['unit_cost'] / $convRate;
        }

        $baseQty  = round($qty * $convRate, 4);
        $unitCost = round($baseCost * $convRate, 4);

        return [
            'product'            => $product,
            'product_id'         => $product?->id ?? $productId,
            'product_variant_id' => $variantId,
            'product_code'       => $product?->code ?? $item['product_code'],
            'product_name'       => $product?->name ?? $item['product_name'],
            'variant_name'       => $this->resolveVariantName($item),
            'unit_id'            => $unitId,
            'unit_name'          => $unitName !== '' ? $unitName : ($product?->unit_name ?: 'Pcs'),
            'conversion_rate'    => $convRate,
            'quantity'           => $qty,
            'base_quantity'      => $baseQty,
            'base_unit_cost'     => round($baseCost, 4),
            'unit_cost'          => $unitCost,
            'subtotal'           => round($qty * $unitCost, 4),
        ];
    }

    private function resolveTransferUnitForProduct(
        Product $product,
        ?int $variantId,
        ?int $unitId,
        string $unitName,
        int $idx,
        ProductUnitConversionService $unitService
    ): array {
        $baseUnitName = trim((string)($product->unit_name ?: 'Pcs'));
        $baseUnitId   = $product->base_unit_id ? (int)$product->base_unit_id : null;

        // 1. Base unit fast-path
        $isExplicitBaseId   = $unitId !== null && $baseUnitId !== null && $unitId === $baseUnitId;
        $isExplicitBaseName = $unitName !== '' && strcasecmp($unitName, $baseUnitName) === 0;
        if (($unitId === null && $unitName === '') || ($isExplicitBaseId && ($unitName === '' || $isExplicitBaseName)) || ($isExplicitBaseName && $unitId === null)) {
            return [
                'unit_id'         => $baseUnitId ?? $unitId,
                'unit_name'       => $baseUnitName,
                'conversion_rate' => 1.0,
            ];
        }

        // 2. Active ProductUnitLinks (purchase or sale packaging units)
        $links = $product->relationLoaded('unitLinks') ? $product->unitLinks : $product->unitLinks()->get();
        $matchingLink = $links->filter(function ($l) use ($variantId, $unitId, $unitName) {
            if (!$l->is_active || (float)$l->conversion_rate <= 0) {
                return false;
            }
            if ($variantId === null && $l->product_variant_id !== null) {
                return false;
            }
            if ($variantId !== null && $l->product_variant_id !== null && (int)$l->product_variant_id !== $variantId) {
                return false;
            }
            if ($unitId !== null && $l->unit_id !== null && (int)$l->unit_id === $unitId) {
                return true;
            }
            if ($unitName !== '' && strcasecmp(trim((string)$l->unit_name), $unitName) === 0) {
                return true;
            }
            return false;
        })->sortBy(function ($l) use ($variantId) {
            $vRank = ($variantId !== null && (int)$l->product_variant_id === $variantId) ? 0 : 1;
            $tRank = strtolower((string)$l->unit_type) === 'purchase' ? 0 : 1;
            return ($vRank * 10) + $tRank;
        })->first();

        if ($matchingLink) {
            return [
                'unit_id'         => $matchingLink->unit_id ? (int)$matchingLink->unit_id : $unitId,
                'unit_name'       => trim((string)($matchingLink->unit_name ?: $unitName ?: $baseUnitName)),
                'conversion_rate' => round((float)$matchingLink->conversion_rate, 6),
            ];
        }

        // 3. Fallback to ProductUnitConversionService (checks purchase then sale definitions)
        foreach (['purchase', 'sale'] as $uType) {
            try {
                $resolved = $unitService->resolve(
                    product:   $product,
                    type:      $uType,
                    unitId:    $unitId,
                    unitName:  $unitName !== '' ? $unitName : null,
                    variantId: $variantId
                );
                if (!empty($resolved['conversion_rate']) && (float)$resolved['conversion_rate'] > 0) {
                    return [
                        'unit_id'         => $resolved['unit_id'] ?? $unitId,
                        'unit_name'       => trim((string)($resolved['unit_name'] ?? ($unitName !== '' ? $unitName : $baseUnitName))),
                        'conversion_rate' => (float)$resolved['conversion_rate'],
                    ];
                }
            } catch (\Throwable $e) {
                // try next unit type
            }
        }

        // Reject unrecognized explicit unit_id so client cannot bypass packaging rates
        if ($unitId !== null) {
            $this->throwValidationError("items.{$idx}.unit_id", "Unit ID {$unitId} is not configured for product '{$product->name}'.");
        }
        if ($unitName !== '' && strcasecmp($unitName, 'Pcs') !== 0 && strcasecmp($unitName, $baseUnitName) !== 0) {
            $this->throwValidationError("items.{$idx}.unit_name", "Unit '{$unitName}' is not configured for product '{$product->name}'.");
        }

        return [
            'unit_id'         => $baseUnitId,
            'unit_name'       => $baseUnitName,
            'conversion_rate' => 1.0,
        ];
    }

    private function assertCumulativeSourceStock(int $fromWarehouseId, array $resolvedLines): void
    {
        $cumulativeNeeded = [];
        $itemInfo         = [];

        foreach ($resolvedLines as $line) {
            $productId = $line['product_id'] ?? null;
            $variantId = $line['product_variant_id'] ?? null;
            $baseQty   = (float)($line['base_quantity'] ?? 0);
            $key       = ($productId ?: $line['product_code']) . '_' . ($variantId ?? '0');

            if (!isset($cumulativeNeeded[$key])) {
                $cumulativeNeeded[$key] = 0.0;
                $itemInfo[$key] = [
                    'product_id'   => $productId,
                    'variant_id'   => $variantId,
                    'product_name' => $line['product_name'] ?? ($line['product_code'] ?? 'Product'),
                ];
            }
            $cumulativeNeeded[$key] += $baseQty;
        }

        $insufficientItems = [];
        foreach ($cumulativeNeeded as $infoKey => $neededBaseQty) {
            $info      = $itemInfo[$infoKey];
            $productId = $info['product_id'];
            $variantId = $info['variant_id'];

            if (!$productId || $neededBaseQty <= 0.0001) {
                continue;
            }

            $query = DB::table('stock_balances')
                ->where('product_id', $productId)
                ->where('warehouse_id', $fromWarehouseId);
            if ($variantId !== null) {
                $query->where('product_variant_id', $variantId);
            } else {
                $query->whereNull('product_variant_id');
            }
            $stockBalance = $query->lockForUpdate()->value('quantity');

            if ($stockBalance === null) {
                $stockBalance = DB::table('product_warehouse')
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $fromWarehouseId)
                    ->lockForUpdate()
                    ->value('quantity');
            }

            if ($stockBalance === null) {
                $hasBalances  = DB::table('stock_balances')->where('product_id', $productId)->exists();
                $prod         = Product::find($productId);
                $stockBalance = (!$hasBalances && $variantId === null && $prod) ? $prod->stock_quantity : 0;
            }

            $available = (float)($stockBalance ?? 0);
            if ($available < ($neededBaseQty - 0.0001)) {
                $insufficientItems[] = sprintf(
                    '%s: Total Base Needed %.4f, Available %.4f',
                    $info['product_name'],
                    $neededBaseQty,
                    $available
                );
            }
        }

        if (!empty($insufficientItems)) {
            $this->throwValidationError(
                'items',
                'Insufficient stock in source warehouse for: ' . implode('; ', $insufficientItems)
            );
        }
    }

    private function extractExpenseCashByRegister(?Expense $expense): array
    {
        if (!$expense) {
            return [];
        }
        $expense->loadMissing('payments');
        $byReg = [];

        $accounting = app(PurchaseAccountingService::class);
        foreach ($expense->payments as $p) {
            $isCash = $accounting->isCashPaymentType($p->payment_type);
            $amt    = round((float)$p->amount, 2);
            $regId  = (int)($p->cash_register_id ?: ($expense->cash_register_id ?: 0));
            if ($isCash && $amt > 0 && $regId > 0) {
                $byReg[$regId] = round(($byReg[$regId] ?? 0.0) + $amt, 2);
            }
        }

        if ($expense->payments->isEmpty()
            && (float)$expense->paid_amount > 0
            && $accounting->isCashPaymentType($expense->payment_method)
            && $expense->cash_register_id
        ) {
            $regId = (int)$expense->cash_register_id;
            $byReg[$regId] = round((float)$expense->paid_amount, 2);
        }

        return $byReg;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no'               => 'nullable|string|max:255|unique:stock_transfers,reference_no',
            'from_warehouse_id'          => 'nullable|integer',
            'from_warehouse'             => 'required|string|max:255',
            'to_warehouse_id'            => 'nullable|integer',
            'to_warehouse'               => 'required|string|max:255|different:from_warehouse',
            'date'                       => 'required|string',
            'transfer_time'              => 'nullable|string|max:50',
            'shipping_charge'            => 'nullable|numeric|min:0',
            'status'                     => 'nullable|string|in:Sent,Pending,Completed',
            'notes'                      => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:100',
            'cash_register_id'           => 'nullable|integer',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string|max:255',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0.0001',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.unit_cost'          => 'nullable|numeric|min:0',
            'items.*.base_unit_cost'     => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'nullable|numeric|min:0',
            'payment_method'             => 'nullable|string|max:100',
            'payments'                   => 'nullable|array',
            'payments.*.amount'          => 'nullable|numeric|min:0',
            'payments.*.payment_type'    => 'nullable|string|max:100',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.payment_time'    => 'nullable|string',
            'payments.*.notes'           => 'nullable|string',
            'payments.*.cash_register_id'=> 'nullable|integer',
        ]);

        $unitService       = app(ProductUnitConversionService::class);
        $fifoService       = app(FifoInventoryService::class);
        $accountingService = app(PurchaseAccountingService::class);
        $transferStatus    = $validated['status'] ?? 'Completed';

        DB::beginTransaction();
        try {
            [$fromWarehouseId, $fromWarehouseName, $toWarehouseId, $toWarehouseName] = $this->resolveTransferWarehouses(
                $validated['from_warehouse'],
                $validated['from_warehouse_id'] ?? null,
                $validated['to_warehouse'],
                $validated['to_warehouse_id'] ?? null,
                $unitService
            );

            $this->assertTransferWarehouseAccess($request, $fromWarehouseId);

            $actor         = $this->resolveTransferActor($request, $validated);
            $resolvedLines = [];
            $totalQty      = 0.0;
            foreach ($validated['items'] as $idx => $rawItem) {
                $line            = $this->resolveTransferLine($rawItem, (int)$idx, $unitService);
                $resolvedLines[] = $line;
                $totalQty       += $line['quantity'];
            }

            if (in_array($transferStatus, ['Completed', 'Sent'], true) && $fromWarehouseId) {
                $this->assertCumulativeSourceStock((int)$fromWarehouseId, $resolvedLines);
            }

            $shippingCharge = round((float)($validated['shipping_charge'] ?? 0), 2);
            $paymentsList   = array_values(array_filter(
                $validated['payments'] ?? [],
                static fn($p) => !empty($p['amount']) && (float)$p['amount'] > 0.0001
            ));
            $totalPaid = round(array_sum(array_map(static fn($p) => (float)$p['amount'], $paymentsList)), 2);

            if ($shippingCharge <= 0.001 && $totalPaid > 0.001) {
                $this->throwValidationError('payments', 'Cannot attach shipping payments when shipping charge is zero.');
            }
            if ($shippingCharge > 0.001 && $totalPaid > $shippingCharge + 0.01) {
                $this->throwValidationError('payments', 'Shipping payments cannot exceed the total shipping charge.');
            }

            $refNo  = $this->acquireUniqueReference('transfer', $validated['reference_no'] ?? null);
            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $transfer = StockTransfer::create([
                'reference_no'      => $refNo,
                'from_warehouse_id' => $fromWarehouseId,
                'from_warehouse'    => $fromWarehouseName,
                'to_warehouse_id'   => $toWarehouseId,
                'to_warehouse'      => $toWarehouseName,
                'date'              => $txDate,
                'transfer_time'     => $validated['transfer_time'] ?? date('h:i A'),
                'items_count'       => count($resolvedLines),
                'total_quantity'    => round($totalQty, 4),
                'shipping_charge'   => $shippingCharge,
                'status'            => $transferStatus,
                'notes'             => $validated['notes'] ?? null,
                'created_by'        => $actor['user_name'],
                'user_id'           => $actor['user_id'],
                'user_name'         => $actor['user_name'],
                'user_role'         => $actor['user_role'],
            ]);

            foreach ($resolvedLines as $line) {
                StockTransferItem::create([
                    'stock_transfer_id'  => $transfer->id,
                    'product_id'         => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'product_code'       => $line['product_code'],
                    'product_name'       => $line['product_name'],
                    'variant_name'       => $line['variant_name'],
                    'unit_id'            => $line['unit_id'],
                    'unit_name'          => $line['unit_name'],
                    'conversion_rate'    => $line['conversion_rate'],
                    'quantity'           => $line['quantity'],
                    'base_quantity'      => $line['base_quantity'],
                    'unit_cost'          => $line['unit_cost'],
                    'base_unit_cost'     => $line['base_unit_cost'],
                    'subtotal'           => $line['subtotal'],
                ]);

                if (in_array($transferStatus, ['Completed', 'Sent'], true) && $line['base_quantity'] > 0 && $line['product']) {
                    $mvCost = (float)($line['base_unit_cost'] ?? 0) > 0 ? (float)$line['base_unit_cost'] : null;
                    $mvDate = (string)($transfer->date ?? now());
                    if ($fromWarehouseId) {
                        $unitService->applyStock(
                            $line['product'], $fromWarehouseId, $line['product_variant_id'], $line['base_quantity'],
                            'out', 'stock_transfer_out', 'stock_transfer',
                            (string)$transfer->id, 'Transferred to ' . $toWarehouseName,
                            true, false, true, $mvCost, $mvDate
                        );
                    }
                    if ($transferStatus === 'Completed' && $toWarehouseId) {
                        $unitService->applyStock(
                            $line['product'], $toWarehouseId, $line['product_variant_id'], $line['base_quantity'],
                            'in', 'stock_transfer_in', 'stock_transfer',
                            (string)$transfer->id, 'Transferred from ' . $fromWarehouseName,
                            true, false, true, $mvCost, $mvDate
                        );
                    }
                }
            }

            if (in_array($transferStatus, ['Completed', 'Sent'], true) && $fromWarehouseId) {
                $fifoService->depleteTransferWithLayers(
                    $transfer->fresh('items'),
                    (int)$fromWarehouseId,
                    (int)($toWarehouseId ?: $fromWarehouseId),
                    $transferStatus === 'Completed'
                );
                $this->syncTransferMovementUnitCosts($transfer->fresh('items'));
            }

            // Auto-create Expense for Shipping Charge with Multi-Payment & Cash Register tracking
            if ($shippingCharge > 0.001) {
                $shippingPrefix = Setting::get('expense_prefix', 'EXP_');
                $expenseRef     = $shippingPrefix . 'TRF-' . $refNo;
                $dueAmount      = max(0.0, round($shippingCharge - $totalPaid, 2));
                $paymentStatus  = $totalPaid <= 0.001 ? 'Unpaid' : ($dueAmount <= 0.001 ? 'Paid' : 'Partial');

                $uniqueTypes   = collect($paymentsList)->pluck('payment_type')->filter()->unique();
                $paymentMethod = $totalPaid <= 0.001
                    ? 'Unpaid'
                    : ($uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?: 'Cash'));

                $headerCashRegId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
                $expense = Expense::create([
                    'transfer_id'           => $transfer->id,
                    'reference_no'          => $expenseRef,
                    'warehouse_id'          => $fromWarehouseId,
                    'warehouse_name'        => $fromWarehouseName,
                    'cash_register_id'      => $headerCashRegId,
                    'expense_category_name' => 'Transfer Shipping',
                    'title'                 => 'Shipping for Transfer #' . $refNo . ' → ' . $toWarehouseName,
                    'amount'                => $shippingCharge,
                    'paid_amount'           => $totalPaid,
                    'due_amount'            => $dueAmount,
                    'payment_status'        => $paymentStatus,
                    'payment_method'        => $paymentMethod,
                    'date'                  => $txDate,
                    'details'               => 'Auto-created from Stock Transfer #' . $refNo,
                    'created_by'            => $actor['user_name'],
                    'user_name'             => $actor['user_name'],
                    'user_role'             => $actor['user_role'],
                    'user_id'               => $actor['user_id'],
                ]);

                $pIndex = 1;
                foreach ($paymentsList as $p) {
                    $pAmount = round((float)$p['amount'], 2);
                    if ($pAmount <= 0) {
                        continue;
                    }
                    $pType    = $p['payment_type'] ?? 'Cash';
                    $isCash   = strtolower(trim((string)$pType)) === 'cash';
                    $legRegId = null;
                    if ($isCash) {
                        $explicitRegId = !empty($p['cash_register_id']) ? (int)$p['cash_register_id'] : $headerCashRegId;
                        $legRegId = $this->resolveActiveOpenCashRegisterId(
                            $explicitRegId,
                            $fromWarehouseId,
                            $fromWarehouseName,
                            $actor['user_id'],
                            $actor['user_name'],
                            true
                        );
                    }

                    ExpensePayment::create([
                        'expense_id'       => $expense->id,
                        'cash_register_id' => $legRegId,
                        'reference_no'     => $this->generateUniqueExpensePaymentRef($expenseRef, $pIndex++),
                        'payment_date'     => $p['payment_date'] ?? $txDate,
                        'payment_time'     => $p['payment_time'] ?? ($validated['transfer_time'] ?? date('h:i A')),
                        'payment_type'     => $pType,
                        'amount'           => $pAmount,
                        'received_amount'  => $pAmount,
                        'change_amount'    => 0.0,
                        'notes'            => $p['notes'] ?? ('Shipping freight payment for #' . $refNo),
                        'created_by'       => $actor['user_name'],
                        'user_id'          => $actor['user_id'],
                        'user_name'        => $actor['user_name'],
                        'user_role'        => $actor['user_role'],
                    ]);

                    if ($legRegId) {
                        if (!$expense->cash_register_id) {
                            $expense->update(['cash_register_id' => $legRegId]);
                        }
                        $accountingService->adjustOpenRegisterExpectedCash((int)$legRegId, -$pAmount);
                    }
                }
            }

            DB::commit();
            $this->clearTransferCache();

            $canViewCost = $accountingService->canViewCost($request);
            $freshData   = $transfer->fresh(['items.variant.values', 'user:id,name,role'])->toArray();
            $freshData   = $this->maskTransferCostFields($freshData, $canViewCost);

            return response()->json([
                'status'  => 'success',
                'message' => 'Stock transfer recorded successfully',
                'data'    => $freshData,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'reference_no'               => ['nullable', 'string', 'max:255', Rule::unique('stock_transfers', 'reference_no')->ignore($id)],
            'from_warehouse_id'          => 'nullable|integer',
            'from_warehouse'             => 'required|string|max:255',
            'to_warehouse_id'            => 'nullable|integer',
            'to_warehouse'               => 'required|string|max:255|different:from_warehouse',
            'date'                       => 'required|string',
            'transfer_time'              => 'nullable|string|max:50',
            'shipping_charge'            => 'nullable|numeric|min:0',
            'status'                     => 'nullable|string|in:Sent,Pending,Completed',
            'notes'                      => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:100',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string|max:255',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0.0001',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.unit_cost'          => 'nullable|numeric|min:0',
            'items.*.base_unit_cost'     => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'nullable|numeric|min:0',
        ]);

        $unitService       = app(ProductUnitConversionService::class);
        $fifoService       = app(FifoInventoryService::class);
        $accountingService = app(PurchaseAccountingService::class);

        DB::beginTransaction();
        try {
            $transfer = StockTransfer::with('items')->lockForUpdate()->find($id);
            if (!$transfer) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
            }

            $this->assertTransferWarehouseAccess($request, $transfer->from_warehouse_id, $transfer->to_warehouse_id, true);

            if ($transfer->status === 'Completed') {
                if ($errMsg = $fifoService->assertTransferCanModifyOrDelete($transfer)) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $errMsg], 422);
                }
            }

            [$fromWarehouseId, $fromWarehouseName, $toWarehouseId, $toWarehouseName] = $this->resolveTransferWarehouses(
                $validated['from_warehouse'],
                $validated['from_warehouse_id'] ?? null,
                $validated['to_warehouse'],
                $validated['to_warehouse_id'] ?? null,
                $unitService
            );
            $this->assertTransferWarehouseAccess($request, $fromWarehouseId);

            $newStatus      = $validated['status'] ?? $transfer->status;
            $actor          = $this->resolveTransferActor($request, $validated, $transfer);
            $resolvedLines  = [];
            $totalQty       = 0.0;
            foreach ($validated['items'] as $idx => $rawItem) {
                $line            = $this->resolveTransferLine($rawItem, (int)$idx, $unitService);
                $resolvedLines[] = $line;
                $totalQty       += $line['quantity'];
            }

            $shippingCharge  = round((float)($validated['shipping_charge'] ?? 0), 2);
            $existingExpense = $this->findLinkedShippingExpense($transfer, true);
            $beforeCashByReg = [];
            $warehouseChangedForShipping = ((int)$transfer->from_warehouse_id !== (int)$fromWarehouseId);
            if ($existingExpense) {
                $existingPaid = round((float)$existingExpense->payments()->sum('amount'), 2);
                if ($shippingCharge > 0.001 && $shippingCharge < $existingPaid - 0.01) {
                    $this->throwValidationError(
                        'shipping_charge',
                        'Cannot reduce shipping charge below already paid amount (' . number_format($existingPaid, 2) . '). Delete payments first.'
                    );
                }
                if ($shippingCharge <= 0.001 || $warehouseChangedForShipping) {
                    $beforeCashByReg = $this->extractExpenseCashByRegister($existingExpense);
                    if ($errMsg = $accountingService->assertCashRegisterDeltaMutable($beforeCashByReg, [])) {
                        DB::rollBack();
                        return response()->json(['status' => 'error', 'message' => $errMsg], 422);
                    }
                }
            }

            // Reverse prior physical stock & FIFO layers
            $revDate = (string)($validated['date'] ?? $transfer->date ?? now());
            if ($transfer->status === 'Completed') {
                $origFromId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
                $origToId   = $unitService->warehouseId($transfer->to_warehouse_id, $transfer->to_warehouse);

                foreach ($transfer->items as $oldItem) {
                    $oldBaseQty = (float)($oldItem->base_quantity ?? ($oldItem->quantity * ($oldItem->conversion_rate ?? 1.0)));
                    if ($oldBaseQty > 0) {
                        $oldCost = (float)($oldItem->base_unit_cost ?? 0) > 0 ? (float)$oldItem->base_unit_cost : null;
                        $product = $oldItem->product_id
                            ? Product::find($oldItem->product_id)
                            : Product::where('code', $oldItem->product_code)->first();
                        if ($product) {
                            if ($origFromId) {
                                $unitService->applyStock(
                                    $product, $origFromId, $oldItem->product_variant_id, $oldBaseQty,
                                    'in', 'stock_transfer_reversal_in', 'stock_transfer_reversal',
                                    (string)$transfer->id, 'Update reversal: restoring to ' . $transfer->from_warehouse,
                                    true, false, true, $oldCost, $revDate
                                );
                            }
                            if ($origToId) {
                                $unitService->applyStock(
                                    $product, $origToId, $oldItem->product_variant_id, $oldBaseQty,
                                    'out', 'stock_transfer_reversal_out', 'stock_transfer_reversal',
                                    (string)$transfer->id, 'Update reversal: removing from ' . $transfer->to_warehouse,
                                    true, false, true, $oldCost, $revDate
                                );
                            }
                        }
                    }
                }
                $fifoService->rollbackTransferFifo($transfer, false);
            } elseif ($transfer->status === 'Sent') {
                $origFromId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
                foreach ($transfer->items as $oldItem) {
                    $oldBaseQty = (float)($oldItem->base_quantity ?? ($oldItem->quantity * ($oldItem->conversion_rate ?? 1.0)));
                    if ($oldBaseQty > 0 && $origFromId) {
                        $oldCost = (float)($oldItem->base_unit_cost ?? 0) > 0 ? (float)$oldItem->base_unit_cost : null;
                        $product = $oldItem->product_id
                            ? Product::find($oldItem->product_id)
                            : Product::where('code', $oldItem->product_code)->first();
                        if ($product) {
                            $unitService->applyStock(
                                $product, $origFromId, $oldItem->product_variant_id, $oldBaseQty,
                                'in', 'stock_transfer_reversal_in', 'stock_transfer_reversal',
                                (string)$transfer->id, 'Update reversal: restoring dispatched in-transit to ' . $transfer->from_warehouse,
                                true, false, true, $oldCost, $revDate
                            );
                        }
                    }
                }
                $fifoService->rollbackTransferFifo($transfer, false);
            }

            if (in_array($newStatus, ['Completed', 'Sent'], true) && $fromWarehouseId) {
                $this->assertCumulativeSourceStock((int)$fromWarehouseId, $resolvedLines);
            }

            $transfer->items()->delete();
            $transfer->update([
                'reference_no'      => $validated['reference_no'] ?? $transfer->reference_no,
                'from_warehouse_id' => $fromWarehouseId ?? $transfer->from_warehouse_id,
                'from_warehouse'    => $fromWarehouseName,
                'to_warehouse_id'   => $toWarehouseId ?? $transfer->to_warehouse_id,
                'to_warehouse'      => $toWarehouseName,
                'date'              => $validated['date'],
                'transfer_time'     => $validated['transfer_time'] ?? $transfer->transfer_time,
                'items_count'       => count($resolvedLines),
                'total_quantity'    => round($totalQty, 4),
                'shipping_charge'   => $shippingCharge,
                'status'            => $newStatus,
                'notes'             => $validated['notes'] ?? $transfer->notes,
                'created_by'        => $actor['user_name'],
                'user_id'           => $actor['user_id'],
                'user_name'         => $actor['user_name'],
                'user_role'         => $actor['user_role'],
            ]);

            foreach ($resolvedLines as $line) {
                StockTransferItem::create([
                    'stock_transfer_id'  => $transfer->id,
                    'product_id'         => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'],
                    'product_code'       => $line['product_code'],
                    'product_name'       => $line['product_name'],
                    'variant_name'       => $line['variant_name'],
                    'unit_id'            => $line['unit_id'],
                    'unit_name'          => $line['unit_name'],
                    'conversion_rate'    => $line['conversion_rate'],
                    'quantity'           => $line['quantity'],
                    'base_quantity'      => $line['base_quantity'],
                    'unit_cost'          => $line['unit_cost'],
                    'base_unit_cost'     => $line['base_unit_cost'],
                    'subtotal'           => $line['subtotal'],
                ]);

                if (in_array($newStatus, ['Completed', 'Sent'], true) && $line['base_quantity'] > 0 && $line['product']) {
                    $mvCost = (float)($line['base_unit_cost'] ?? 0) > 0 ? (float)$line['base_unit_cost'] : null;
                    $mvDate = (string)($transfer->date ?? now());
                    if ($fromWarehouseId) {
                        $unitService->applyStock(
                            $line['product'], $fromWarehouseId, $line['product_variant_id'], $line['base_quantity'],
                            'out', 'stock_transfer_out', 'stock_transfer',
                            (string)$transfer->id, 'Updated transfer to ' . $toWarehouseName,
                            true, false, true, $mvCost, $mvDate
                        );
                    }
                    if ($newStatus === 'Completed' && $toWarehouseId) {
                        $unitService->applyStock(
                            $line['product'], $toWarehouseId, $line['product_variant_id'], $line['base_quantity'],
                            'in', 'stock_transfer_in', 'stock_transfer',
                            (string)$transfer->id, 'Updated transfer from ' . $fromWarehouseName,
                            true, false, true, $mvCost, $mvDate
                        );
                    }
                }
            }

            if (in_array($newStatus, ['Completed', 'Sent'], true) && $fromWarehouseId) {
                $fifoService->depleteTransferWithLayers(
                    $transfer->fresh('items'),
                    (int)$fromWarehouseId,
                    (int)($toWarehouseId ?: $fromWarehouseId),
                    $newStatus === 'Completed'
                );
                $this->syncTransferMovementUnitCosts($transfer->fresh('items'));
            }

            // Synchronize Shipping Operating Expense Record
            $refNo = $transfer->reference_no;
            if ($shippingCharge > 0.001) {
                if ($existingExpense) {
                    $totalPaid     = round((float)$existingExpense->payments()->sum('amount'), 2);
                    $dueAmount     = max(0.0, round($shippingCharge - $totalPaid, 2));
                    $paymentStatus = $totalPaid <= 0.001 ? 'Unpaid' : ($dueAmount <= 0.001 ? 'Paid' : 'Partial');
                    $existingExpense->update([
                        'transfer_id'    => $transfer->id,
                        'warehouse_id'   => $fromWarehouseId,
                        'warehouse_name' => $fromWarehouseName,
                        'amount'         => $shippingCharge,
                        'paid_amount'    => $totalPaid,
                        'due_amount'     => $dueAmount,
                        'payment_status' => $paymentStatus,
                        'payment_method' => $totalPaid <= 0.001 ? 'Unpaid' : $existingExpense->payment_method,
                        'date'           => $validated['date'],
                        'title'          => 'Shipping for Transfer #' . $refNo . ' → ' . $toWarehouseName,
                        'details'        => 'Auto-created from Stock Transfer #' . $refNo,
                    ]);
                } else {
                    $shippingPrefix = Setting::get('expense_prefix', 'EXP_');
                    Expense::create([
                        'transfer_id'           => $transfer->id,
                        'reference_no'          => $shippingPrefix . 'TRF-' . $refNo,
                        'warehouse_id'          => $fromWarehouseId,
                        'warehouse_name'        => $fromWarehouseName,
                        'expense_category_name' => 'Transfer Shipping',
                        'title'                 => 'Shipping for Transfer #' . $refNo . ' → ' . $toWarehouseName,
                        'amount'                => $shippingCharge,
                        'paid_amount'           => 0,
                        'due_amount'            => $shippingCharge,
                        'payment_status'        => 'Unpaid',
                        'payment_method'        => 'Unpaid',
                        'date'                  => $validated['date'],
                        'details'               => 'Auto-created from Stock Transfer #' . $refNo,
                        'created_by'            => $actor['user_name'],
                        'user_name'             => $actor['user_name'],
                        'user_role'             => $actor['user_role'],
                        'user_id'               => $actor['user_id'],
                    ]);
                }
            } elseif ($existingExpense) {
                foreach ($beforeCashByReg as $regId => $cashOut) {
                    $accountingService->adjustOpenRegisterExpectedCash((int)$regId, round((float)$cashOut, 2));
                }
                $existingExpense->payments()->delete();
                $existingExpense->delete();
            }

            DB::commit();
            $this->clearTransferCache();

            $canViewCost = $accountingService->canViewCost($request);
            $freshData   = $transfer->fresh(['items.variant.values', 'user:id,name,role'])->toArray();
            $freshData   = $this->maskTransferCostFields($freshData, $canViewCost);

            return response()->json([
                'status'  => 'success',
                'message' => 'Stock transfer updated successfully',
                'data'    => $freshData,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $unitService       = app(ProductUnitConversionService::class);
        $fifoService       = app(FifoInventoryService::class);
        $accountingService = app(PurchaseAccountingService::class);

        DB::beginTransaction();
        try {
            $transfer = StockTransfer::with('items')->lockForUpdate()->find($id);
            if (!$transfer) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
            }

            $this->assertTransferWarehouseAccess($request, $transfer->from_warehouse_id, $transfer->to_warehouse_id, true);

            if ($transfer->status === 'Completed') {
                if ($errMsg = $fifoService->assertTransferCanModifyOrDelete($transfer)) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $errMsg], 422);
                }
            }

            $existingExpense = $this->findLinkedShippingExpense($transfer, true);
            $beforeCashByReg = $this->extractExpenseCashByRegister($existingExpense);
            if (!empty($beforeCashByReg)) {
                if ($errMsg = $accountingService->assertCashRegisterDeltaMutable($beforeCashByReg, [])) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $errMsg], 422);
                }
            }

            $delDate = (string)($transfer->date ?? now());
            if ($transfer->status === 'Completed') {
                $fromWarehouseId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
                $toWarehouseId   = $unitService->warehouseId($transfer->to_warehouse_id, $transfer->to_warehouse);

                foreach ($transfer->items as $item) {
                    $baseQty = (float)($item->base_quantity ?? ($item->quantity * ($item->conversion_rate ?? 1.0)));
                    if ($baseQty > 0) {
                        $oldCost = (float)($item->base_unit_cost ?? 0) > 0 ? (float)$item->base_unit_cost : null;
                        $product = $item->product_id
                            ? Product::find($item->product_id)
                            : Product::where('code', $item->product_code)->first();
                        if ($product) {
                            if ($fromWarehouseId) {
                                $unitService->applyStock(
                                    $product, $fromWarehouseId, $item->product_variant_id, $baseQty,
                                    'in', 'stock_transfer_reversal_in', 'stock_transfer_reversal',
                                    (string)$transfer->id, 'Reverted transfer to ' . $transfer->to_warehouse,
                                    true, false, true, $oldCost, $delDate
                                );
                            }
                            if ($toWarehouseId) {
                                $unitService->applyStock(
                                    $product, $toWarehouseId, $item->product_variant_id, $baseQty,
                                    'out', 'stock_transfer_reversal_out', 'stock_transfer_reversal',
                                    (string)$transfer->id, 'Reverted transfer from ' . $transfer->from_warehouse,
                                    true, false, true, $oldCost, $delDate
                                );
                            }
                        }
                    }
                }
                $fifoService->rollbackTransferFifo($transfer, false);
            } elseif ($transfer->status === 'Sent') {
                $fromWarehouseId = $unitService->warehouseId($transfer->from_warehouse_id, $transfer->from_warehouse);
                foreach ($transfer->items as $item) {
                    $baseQty = (float)($item->base_quantity ?? ($item->quantity * ($item->conversion_rate ?? 1.0)));
                    if ($baseQty > 0 && $fromWarehouseId) {
                        $oldCost = (float)($item->base_unit_cost ?? 0) > 0 ? (float)$item->base_unit_cost : null;
                        $product = $item->product_id
                            ? Product::find($item->product_id)
                            : Product::where('code', $item->product_code)->first();
                        if ($product) {
                            $unitService->applyStock(
                                $product, $fromWarehouseId, $item->product_variant_id, $baseQty,
                                'in', 'stock_transfer_reversal_in', 'stock_transfer_reversal',
                                (string)$transfer->id, 'Cancelled in-transit transfer to ' . $transfer->to_warehouse,
                                true, false, true, $oldCost, $delDate
                            );
                        }
                    }
                }
                $fifoService->rollbackTransferFifo($transfer, false);
            }

            if ($existingExpense) {
                foreach ($beforeCashByReg as $regId => $cashOut) {
                    $accountingService->adjustOpenRegisterExpectedCash((int)$regId, round((float)$cashOut, 2));
                }
                $existingExpense->payments()->delete();
                $existingExpense->delete();
            }

            $transfer->items()->delete();
            $transfer->delete();

            DB::commit();
            $this->clearTransferCache();

            return response()->json(['status' => 'success', 'message' => 'Transfer deleted successfully and stock restored']);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Confirm and receive an In-Transit (Sent) or Pending transfer at the destination branch.
     * Physically replenishes inventory and preserves FIFO source cost layers.
     */
    public function receiveTransfer(Request $request, $id)
    {
        $validated = $request->validate([
            'received_by'     => 'nullable|string|max:255',
            'receiving_notes' => 'nullable|string|max:500',
        ]);

        $unitService       = app(ProductUnitConversionService::class);
        $fifoService       = app(FifoInventoryService::class);
        $accountingService = app(PurchaseAccountingService::class);

        DB::beginTransaction();
        try {
            $transfer = StockTransfer::with('items')->lockForUpdate()->find($id);
            if (!$transfer) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
            }

            $this->assertTransferWarehouseAccess($request, $transfer->from_warehouse_id, $transfer->to_warehouse_id, true);

            if ($transfer->status === 'Completed') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Transfer is already marked as Completed.',
                ], 422);
            }

            if (!in_array($transfer->status, ['Sent', 'Pending'], true)) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Only In-Transit (Sent) or Pending transfers can be received into destination inventory.',
                ], 422);
            }

            [$fromWarehouseId, , $toWarehouseId] = $this->resolveTransferWarehouses(
                $transfer->from_warehouse,
                $transfer->from_warehouse_id,
                $transfer->to_warehouse,
                $transfer->to_warehouse_id,
                $unitService
            );

            $wasPending = ($transfer->status === 'Pending');
            $itemLines  = [];
            foreach ($transfer->items as $item) {
                $convRate = (float)($item->conversion_rate ?? 1.0);
                $qty      = (float)$item->quantity;
                $baseQty  = (float)($item->base_quantity ?? ($qty * $convRate));
                $product  = $item->product_id
                    ? Product::find($item->product_id)
                    : Product::where('code', $item->product_code)->first();

                if ($product && !$item->product_id) {
                    $item->update(['product_id' => $product->id]);
                }

                $itemLines[] = [
                    'item_id'            => $item->id,
                    'item'               => $item,
                    'product'            => $product,
                    'product_id'         => $product?->id ?? $item->product_id,
                    'product_variant_id' => !empty($item->product_variant_id) ? (int)$item->product_variant_id : null,
                    'product_code'       => $item->product_code,
                    'product_name'       => $item->product_name,
                    'base_quantity'      => $baseQty,
                ];
            }

            if ($wasPending && $fromWarehouseId) {
                $this->assertCumulativeSourceStock((int)$fromWarehouseId, $itemLines);
            }

            $rcvDate = (string)($transfer->date ?? now());
            foreach ($itemLines as $line) {
                if ($line['base_quantity'] <= 0 || !$line['product']) {
                    continue;
                }
                $itemModel = $transfer->items->firstWhere('id', $line['item_id'] ?? 0);
                $lineCost  = $itemModel && (float)$itemModel->base_unit_cost > 0 ? (float)$itemModel->base_unit_cost : null;
                if ($wasPending && $fromWarehouseId) {
                    $unitService->applyStock(
                        $line['product'], $fromWarehouseId, $line['product_variant_id'], $line['base_quantity'],
                        'out', 'stock_transfer_out', 'stock_transfer',
                        (string)$transfer->id, 'Transferred to ' . $transfer->to_warehouse,
                        true, false, true, $lineCost, $rcvDate
                    );
                }
                if ($toWarehouseId) {
                    $unitService->applyStock(
                        $line['product'], $toWarehouseId, $line['product_variant_id'], $line['base_quantity'],
                        'in', 'stock_transfer_in', 'stock_transfer',
                        (string)$transfer->id, 'Transfer received from ' . $transfer->from_warehouse,
                        true, false, true, $lineCost, $rcvDate
                    );
                }
            }

            if ($wasPending && $fromWarehouseId) {
                $fifoService->depleteTransferWithLayers(
                    $transfer->fresh('items'),
                    (int)$fromWarehouseId,
                    (int)($toWarehouseId ?: $fromWarehouseId),
                    (bool)$toWarehouseId
                );
                $this->syncTransferMovementUnitCosts($transfer->fresh('items'));
            } elseif ($toWarehouseId) {
                $fifoService->createTransferDestinationBatches($transfer->fresh('items'), (int)$toWarehouseId);
                $this->syncTransferMovementUnitCosts($transfer->fresh('items'));
            }

            $authUser     = User::resolveFromRequest($request) ?? $request->user();
            $receiverName = $validated['received_by'] ?? ($authUser?->name ?? 'Branch Manager');

            $transfer->update([
                'status'          => 'Completed',
                'received_by'     => $receiverName,
                'received_at'     => now(),
                'receiving_notes' => $validated['receiving_notes'] ?? null,
            ]);

            DB::commit();
            $this->clearTransferCache();

            $canViewCost = $accountingService->canViewCost($request);
            $freshData   = $transfer->fresh(['items.variant.values', 'user:id,name,role'])->toArray();
            $freshData   = $this->maskTransferCostFields($freshData, $canViewCost);

            return response()->json([
                'status'  => 'success',
                'message' => 'Stock transfer received and verified successfully. Goods added to destination inventory.',
                'data'    => $freshData,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve rich variation name with attribute type and values.
     * E.g. "Cyan (Color: Cyan, Size: 44)" or "Size: 32, Color: Red"
     */
    protected function resolveVariantName(array $item): ?string
    {
        $vName = isset($item['variant_name']) ? trim((string)$item['variant_name']) : '';
        $pvId  = $item['product_variant_id'] ?? null;

        if (!empty($pvId)) {
            $pv = ProductVariant::with('values')->find($pvId);
            if ($pv) {
                $valParts = [];
                if (!empty($pv->values)) {
                    foreach ($pv->values as $val) {
                        $an = trim($val->attribute_name ?? '');
                        $av = trim($val->attribute_value ?? '');
                        if (!empty($an) && !empty($av)) {
                            $valParts[] = "$an: $av";
                        } elseif (!empty($av)) {
                            $valParts[] = $av;
                        }
                    }
                }
                $attrStr = implode(', ', $valParts);
                $pvName  = trim($pv->name ?? '');

                if ($vName !== '') {
                    if (!empty($attrStr) && !str_contains($vName, ':')) {
                        return "$vName ($attrStr)";
                    }
                    return $vName;
                }

                if (!empty($pvName) && !empty($attrStr) && !str_contains($pvName, ':')) {
                    return "$pvName ($attrStr)";
                } elseif (!empty($attrStr)) {
                    return $attrStr;
                } elseif (!empty($pvName)) {
                    return $pvName;
                }
            }
        }

        return $vName !== '' ? $vName : null;
    }

    /**
     * Get payment history for a stock transfer's shipping freight.
     */
    public function getPayments(Request $request, $id)
    {
        $transfer = StockTransfer::find($id) ?? StockTransfer::where('reference_no', $id)->first();
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        $this->assertTransferWarehouseAccess($request, $transfer->from_warehouse_id, $transfer->to_warehouse_id, true);

        $expense = $this->findLinkedShippingExpense($transfer, false);

        $payments   = $expense ? $expense->payments : collect();
        $grandTotal = (float)($expense ? $expense->amount : $transfer->shipping_charge);
        $totalPaid  = (float)($expense ? $expense->paid_amount : $payments->sum('amount'));
        $dueAmount  = (float)($expense ? $expense->due_amount : max(0, $grandTotal - $totalPaid));

        return response()->json([
            'status' => 'success',
            'data'   => [
                'transfer_id'    => $transfer->id,
                'reference_no'   => $transfer->reference_no,
                'grand_total'    => $grandTotal,
                'total_paid'     => $totalPaid,
                'due_amount'     => $dueAmount,
                'payment_status' => $expense ? $expense->payment_status : ($grandTotal <= 0.001 || $dueAmount <= 0.001 ? 'Paid' : 'Unpaid'),
                'payment_method' => $expense ? ($expense->payment_method ?: ($totalPaid <= 0.001 ? 'Unpaid' : 'N/A')) : ($grandTotal <= 0.001 ? 'N/A' : 'Unpaid'),
                'payments'       => $payments,
            ],
        ]);
    }

    /**
     * Add a payment to a stock transfer's shipping freight expense.
     */
    public function addPayment(Request $request, $id)
    {
        $validated = $request->validate([
            'amount'                      => 'nullable|numeric|min:0',
            'payment_type'                => 'required|string',
            'payment_date'                => 'nullable|string',
            'payment_time'                => 'nullable|string',
            'notes'                       => 'nullable|string',
            'received_amount'             => 'nullable|numeric',
            'change_amount'               => 'nullable|numeric',
            'cash_register_id'            => 'nullable|integer',
            'payments'                    => 'nullable|array',
            'payments.*.amount'           => 'nullable|numeric|min:0',
            'payments.*.payment_type'     => 'nullable|string',
            'payments.*.payment_date'     => 'nullable|string',
            'payments.*.payment_time'     => 'nullable|string',
            'payments.*.notes'            => 'nullable|string',
            'payments.*.received_amount'  => 'nullable|numeric',
            'payments.*.change_amount'    => 'nullable|numeric',
            'payments.*.cash_register_id' => 'nullable|integer',
            'reference_no'                => 'nullable|string',
        ]);

        $authUser = User::resolveFromRequest($request) ?? $request->user();
        $authRole = $authUser?->roleModel?->name ?? ($authUser?->role ?? null);
        $userName = $request->input('user_name') ?? ($authUser?->name ?? 'Super Admin');
        $userRole = $request->input('user_role') ?? ($authRole ?? 'Super Admin');
        $userId   = $request->input('user_id') ?? $authUser?->id;

        $timezone    = Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
        $nowTz       = Carbon::now()->timezone($timezone);
        $defaultDate = $nowTz->format('Y-m-d');
        $defaultTime = $nowTz->format('h:i A');

        $pDate = $request->input('payment_date');
        $pTime = $request->input('payment_time');

        if (empty($pDate)) {
            $pDate = $defaultDate;
        } elseif (strlen(trim((string)$pDate)) > 10 && str_contains((string)$pDate, ' ')) {
            $parts = explode(' ', trim((string)$pDate), 2);
            $pDate = $parts[0];
            if (empty($pTime)) {
                try {
                    $pTime = Carbon::parse($parts[1])->timezone($timezone)->format('h:i A');
                } catch (\Throwable $e) {
                }
            }
        }

        if (empty($pTime)) {
            $pTime = $defaultTime;
        } else {
            try {
                $pTime = Carbon::parse($pTime)->timezone($timezone)->format('h:i A');
            } catch (\Throwable $e) {
                if (!preg_match('/(AM|PM)/i', (string)$pTime)) {
                    $pTime = $defaultTime;
                }
            }
        }

        $totalPaying = 0.0;
        if (!empty($validated['payments']) && is_array($validated['payments'])) {
            foreach ($validated['payments'] as $p) {
                $pAmt = (float)($p['amount'] ?? 0);
                if ($pAmt > 0) {
                    $totalPaying += $pAmt;
                }
            }
        } else {
            $totalPaying = (float)($validated['amount'] ?? 0);
        }

        if ($totalPaying <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Payment amount must be greater than zero.'], 422);
        }

        $accountingService = app(PurchaseAccountingService::class);

        DB::beginTransaction();
        try {
            $transfer = StockTransfer::where('reference_no', $id)->lockForUpdate()->first();
            if (!$transfer && is_numeric($id)) {
                $transfer = StockTransfer::lockForUpdate()->find($id);
            }
            if (!$transfer && $request->filled('reference_no')) {
                $transfer = StockTransfer::where('reference_no', $request->input('reference_no'))->lockForUpdate()->first();
            }
            if (!$transfer) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
            }

            $this->assertTransferWarehouseAccess($request, $transfer->from_warehouse_id, $transfer->to_warehouse_id, true);

            $shippingPrefix  = Setting::get('expense_prefix', 'EXP_');
            $expenseRef      = $shippingPrefix . 'TRF-' . $transfer->reference_no;
            $headerCashRegId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;

            $expense = $this->findLinkedShippingExpense($transfer, true);

            if (!$expense) {
                if ((float)$transfer->shipping_charge <= 0.001) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Transfer has zero shipping charge; no shipping fee is due.',
                    ], 422);
                }
                $expense = Expense::create([
                    'transfer_id'           => $transfer->id,
                    'reference_no'          => $expenseRef,
                    'warehouse_id'          => $transfer->from_warehouse_id,
                    'warehouse_name'        => $transfer->from_warehouse,
                    'cash_register_id'      => $headerCashRegId,
                    'expense_category_name' => 'Transfer Shipping',
                    'title'                 => 'Shipping for Transfer #' . $transfer->reference_no . ' → ' . $transfer->to_warehouse,
                    'amount'                => (float)$transfer->shipping_charge,
                    'paid_amount'           => 0,
                    'due_amount'            => (float)$transfer->shipping_charge,
                    'payment_status'        => 'Unpaid',
                    'payment_method'        => 'Unpaid',
                    'date'                  => $pDate . ' ' . $pTime,
                    'details'               => 'Auto-created from Stock Transfer #' . $transfer->reference_no,
                    'created_by'            => $userName,
                    'user_name'             => $userName,
                    'user_role'             => $userRole,
                    'user_id'               => $userId,
                ]);
            } elseif (!$expense->transfer_id) {
                $expense->update(['transfer_id' => $transfer->id]);
            }

            // Strictly prevent paying more than remaining due shipping fee
            $currentPaid = (float)$expense->paid_amount;
            $currentDue  = max(0.0, (float)$expense->amount - $currentPaid);

            if ($totalPaying > $currentDue + 0.01) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment amount (' . number_format($totalPaying, 2) . ') cannot exceed the remaining due shipping fee (' . number_format($currentDue, 2) . ')',
                ], 422);
            }

            $legsToCreate = [];
            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                foreach ($validated['payments'] as $p) {
                    $pAmount = round((float)($p['amount'] ?? 0), 2);
                    if ($pAmount <= 0) {
                        continue;
                    }
                    $itemDate = !empty($p['payment_date']) ? $p['payment_date'] : $pDate;
                    $itemTime = !empty($p['payment_time']) ? $p['payment_time'] : $pTime;

                    if (str_contains((string)$itemDate, ' ')) {
                        $parts    = explode(' ', trim((string)$itemDate), 2);
                        $itemDate = $parts[0];
                        if (empty($p['payment_time'])) {
                            try {
                                $itemTime = Carbon::parse($parts[1])->timezone($timezone)->format('h:i A');
                            } catch (\Throwable $e) {
                            }
                        }
                    }
                    try {
                        $itemTime = Carbon::parse($itemTime)->timezone($timezone)->format('h:i A');
                    } catch (\Throwable $e) {
                        if (!preg_match('/(AM|PM)/i', (string)$itemTime)) {
                            $itemTime = $defaultTime;
                        }
                    }

                    $legsToCreate[] = [
                        'payment_type'     => $p['payment_type'] ?? ($p['type'] ?? $validated['payment_type']),
                        'amount'           => $pAmount,
                        'received_amount'  => (float)($p['received_amount'] ?? $pAmount),
                        'change_amount'    => (float)($p['change_amount'] ?? 0),
                        'payment_date'     => $itemDate,
                        'payment_time'     => $itemTime,
                        'notes'            => $p['notes'] ?? ($validated['notes'] ?? ('Shipping payment for #' . $transfer->reference_no)),
                        'user_name'        => !empty($p['user_name']) ? $p['user_name'] : $userName,
                        'user_role'        => !empty($p['user_role']) ? $p['user_role'] : $userRole,
                        'user_id'          => !empty($p['user_id']) ? $p['user_id'] : $userId,
                        'cash_register_id' => !empty($p['cash_register_id']) ? (int)$p['cash_register_id'] : $headerCashRegId,
                    ];
                }
            } else {
                $pAmount = round((float)$validated['amount'], 2);
                $legsToCreate[] = [
                    'payment_type'     => $validated['payment_type'],
                    'amount'           => $pAmount,
                    'received_amount'  => (float)($validated['received_amount'] ?? $pAmount),
                    'change_amount'    => (float)($validated['change_amount'] ?? 0),
                    'payment_date'     => $pDate,
                    'payment_time'     => $pTime,
                    'notes'            => $validated['notes'] ?? ('Shipping payment for #' . $transfer->reference_no),
                    'user_name'        => $userName,
                    'user_role'        => $userRole,
                    'user_id'          => $userId,
                    'cash_register_id' => $headerCashRegId,
                ];
            }

            $seedIndex = ExpensePayment::where('expense_id', $expense->id)->count() + 1;
            foreach ($legsToCreate as $leg) {
                $isCash   = $accountingService->isCashPaymentType($leg['payment_type']);
                $legRegId = null;
                if ($isCash) {
                    $legRegId = $this->resolveActiveOpenCashRegisterId(
                        $leg['cash_register_id'],
                        $transfer->from_warehouse_id ? (int)$transfer->from_warehouse_id : ($expense->warehouse_id ? (int)$expense->warehouse_id : null),
                        $transfer->from_warehouse ?: $expense->warehouse_name,
                        $leg['user_id'] ? (int)$leg['user_id'] : null,
                        $leg['user_name'],
                        true
                    );
                }

                ExpensePayment::create([
                    'expense_id'       => $expense->id,
                    'cash_register_id' => $legRegId,
                    'reference_no'     => $this->generateUniqueExpensePaymentRef($expense->reference_no, $seedIndex++),
                    'payment_date'     => $leg['payment_date'],
                    'payment_time'     => $leg['payment_time'],
                    'payment_type'     => $leg['payment_type'],
                    'amount'           => $leg['amount'],
                    'received_amount'  => $leg['received_amount'],
                    'change_amount'    => $leg['change_amount'],
                    'notes'            => $leg['notes'],
                    'created_by'       => $leg['user_name'],
                    'user_id'          => $leg['user_id'],
                    'user_name'        => $leg['user_name'],
                    'user_role'        => $leg['user_role'],
                ]);

                if ($legRegId) {
                    if (!$expense->cash_register_id) {
                        $expense->update(['cash_register_id' => $legRegId]);
                    }
                    $accountingService->adjustOpenRegisterExpectedCash((int)$legRegId, -$leg['amount']);
                }
            }

            // Recalculate expense totals
            $allPayments      = ExpensePayment::where('expense_id', $expense->id)->get();
            $newPaid          = round((float)$allPayments->sum('amount'), 2);
            $newDue           = max(0.0, round((float)$expense->amount - $newPaid, 2));
            $newStatus        = $newPaid <= 0.001 ? 'Unpaid' : ($newDue <= 0.001 ? 'Paid' : 'Partial');
            $uniqueTypes      = $allPayments->pluck('payment_type')->filter()->unique();
            $newPaymentMethod = $newPaid <= 0.001
                ? 'Unpaid'
                : ($uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?? $expense->payment_method));

            $expense->update([
                'paid_amount'    => $newPaid,
                'due_amount'     => $newDue,
                'payment_status' => $newStatus,
                'payment_method' => $newPaymentMethod,
            ]);

            $transfer->touch();

            DB::commit();
            $this->clearTransferCache();

            $canViewCost = $accountingService->canViewCost($request);
            $transferData = $transfer->fresh(['items.variant.values', 'user:id,name,role'])->toArray();
            $expense->refresh();
            $transferData['payments']                = $expense->payments()->orderBy('id', 'asc')->get()->toArray();
            $transferData['shipping_paid']           = (float)$expense->paid_amount;
            $transferData['shipping_due']            = (float)$expense->due_amount;
            $transferData['shipping_payment_status'] = $expense->payment_status;
            $transferData['payment_method']          = $expense->payment_method;
            $transferData                            = $this->maskTransferCostFields($transferData, $canViewCost);

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully to shipping expense',
                'data'    => $transferData,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a payment from a stock transfer's shipping freight expense.
     */
    public function deletePayment(Request $request, $id, $paymentId)
    {
        $accountingService = app(PurchaseAccountingService::class);

        DB::beginTransaction();
        try {
            $transfer = StockTransfer::where('reference_no', $id)->lockForUpdate()->first();
            if (!$transfer && is_numeric($id)) {
                $transfer = StockTransfer::lockForUpdate()->find($id);
            }
            if (!$transfer) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
            }

            $this->assertTransferWarehouseAccess($request, $transfer->from_warehouse_id, $transfer->to_warehouse_id, true);

            $expense = $this->findLinkedShippingExpense($transfer, true);
            if (!$expense) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Associated expense not found'], 404);
            }

            $payment = ExpensePayment::where('expense_id', $expense->id)
                ->where(function ($q) use ($paymentId) {
                    if (is_numeric($paymentId)) {
                        $q->where('id', (int)$paymentId)->orWhere('reference_no', (string)$paymentId);
                    } else {
                        $q->where('reference_no', (string)$paymentId);
                    }
                })
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Payment record not found'], 404);
            }

            $targetRegId = $payment->cash_register_id
                ? (int)$payment->cash_register_id
                : ($expense->cash_register_id ? (int)$expense->cash_register_id : null);

            if ($errMsg = $accountingService->assertPaymentLegRegisterMutable(
                $payment->cash_register_id ? (int)$payment->cash_register_id : null,
                $expense->cash_register_id ? (int)$expense->cash_register_id : null,
                $payment->payment_type
            )) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => $errMsg], 422);
            }

            $delAmount = round((float)$payment->amount, 2);
            $isCash    = $accountingService->isCashPaymentType($payment->payment_type);

            $payment->delete();

            if ($isCash && $targetRegId && $delAmount > 0) {
                $accountingService->adjustOpenRegisterExpectedCash($targetRegId, $delAmount);
            }

            // Recalculate expense totals
            $allPayments      = ExpensePayment::where('expense_id', $expense->id)->orderBy('id', 'asc')->get();
            $newPaid          = round((float)$allPayments->sum('amount'), 2);
            $newDue           = max(0.0, round((float)$expense->amount - $newPaid, 2));
            $newStatus        = $newPaid <= 0.001 ? 'Unpaid' : ($newDue <= 0.001 ? 'Paid' : 'Partial');
            $uniqueTypes      = $allPayments->pluck('payment_type')->filter()->unique();
            $newPaymentMethod = $newPaid <= 0.001
                ? 'Unpaid'
                : ($uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?: 'Unpaid'));

            $expense->update([
                'paid_amount'    => $newPaid,
                'due_amount'     => $newDue,
                'payment_status' => $newStatus,
                'payment_method' => $newPaymentMethod,
            ]);

            $transfer->touch();

            DB::commit();
            $this->clearTransferCache();

            $canViewCost = $accountingService->canViewCost($request);
            $transferData = $transfer->fresh(['items.variant.values', 'user:id,name,role'])->toArray();
            $expense->refresh();
            $transferData['payments']                = $expense->payments()->orderBy('id', 'asc')->get()->toArray();
            $transferData['shipping_paid']           = (float)$expense->paid_amount;
            $transferData['shipping_due']            = (float)$expense->due_amount;
            $transferData['shipping_payment_status'] = $expense->payment_status;
            $transferData['payment_method']          = $expense->payment_method;
            $transferData                            = $this->maskTransferCostFields($transferData, $canViewCost);

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully from shipping expense',
                'data'    => $transferData,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}

