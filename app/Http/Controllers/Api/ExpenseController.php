<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpensePayment;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\ProductUnitConversionService;
use App\Services\PurchaseAccountingService;
use App\Support\ListCursorHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;

    private function escapeCsvCell($value): string
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

    private function throwValidationError(string $field, string $message): never
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

    private function clearExpenseCache(): void
    {
        PurchaseAccountingService::bumpListPrefix('expense_list_summary_v1');
        app(PurchaseAccountingService::class)->invalidateAllCaches();
    }

    private function assertExpenseOutletAccess(Request $request, ?int $warehouseId): void
    {
        $allowed = app(PurchaseAccountingService::class)->extractAllowedOutletIds($request);
        if ($allowed === null) {
            return;
        }
        if (empty($allowed) || in_array(-1, $allowed, true) || !$warehouseId || !in_array((int)$warehouseId, $allowed, true)) {
            abort(403, 'Unauthorized warehouse access.');
        }
    }

    private function resolveExpenseWarehouse(
        Request $request,
        ?int $warehouseId,
        string $warehouseName,
        ProductUnitConversionService $unitService
    ): array {
        $resolvedId = $unitService->warehouseId($warehouseId, $warehouseName);
        if (Outlet::query()->exists() && !$resolvedId) {
            $this->throwValidationError('warehouse_name', 'Selected warehouse/outlet could not be resolved.');
        }
        $this->assertExpenseOutletAccess($request, $resolvedId ? (int)$resolvedId : null);
        $canonicalName = ($resolvedId ? Outlet::find($resolvedId)?->name : null) ?? trim($warehouseName);

        return [$resolvedId ? (int)$resolvedId : null, $canonicalName];
    }

    private function resolveExpenseCategory(?int $categoryId, string $categoryName): array
    {
        if ($categoryId !== null && $categoryId > 0) {
            $cat = ExpenseCategory::find($categoryId);
            if (!$cat) {
                $this->throwValidationError('expense_category_id', 'Selected expense category does not exist.');
            }
            if (strtolower(trim((string)($cat->status ?? 'Active'))) === 'inactive') {
                $this->throwValidationError('expense_category_id', "Expense category '{$cat->name}' is inactive.");
            }
            return [(int)$cat->id, $cat->name];
        }

        $trimmed = trim($categoryName);
        $byName  = ExpenseCategory::whereRaw('LOWER(TRIM(name)) = ?', [strtolower($trimmed)])->first();
        if ($byName) {
            if (strtolower(trim((string)($byName->status ?? 'Active'))) === 'inactive') {
                $this->throwValidationError('expense_category_name', "Expense category '{$byName->name}' is inactive.");
            }
            return [(int)$byName->id, $byName->name];
        }

        return [null, $trimmed];
    }

    private function resolveExpenseActor(Request $request, array $validated, ?Expense $existing = null): array
    {
        $authUser = User::resolveFromRequest($request) ?? $request->user();
        $authRole = $authUser?->roleModel?->name ?? ($authUser?->role ?? null);
        $isAdmin  = $authUser && (
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

    private function generateUniqueExpensePaymentRef(string $expenseRef, int $seedIndex = 1): string
    {
        $idx  = max(1, $seedIndex);
        $cand = 'PAY-' . $expenseRef . '-' . $idx;
        while (ExpensePayment::where('reference_no', $cand)->exists()) {
            $cand = 'PAY-' . $expenseRef . '-' . date('YmdHis') . '-' . Str::upper(Str::random(4)) . '-' . $idx;
            $idx++;
        }
        return $cand;
    }

    private function normalizeDateTimeString(?string $rawDate, ?string $fallbackTime = null): string
    {
        $timezone = Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
        $trimmed = trim((string)($rawDate ?? ''));
        if ($trimmed === '') {
            return Carbon::now()->timezone($timezone)->format('Y-m-d H:i:s');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $trimmed)) {
            return $trimmed;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            if (!empty($fallbackTime)) {
                try {
                    $parsed = Carbon::parse($trimmed . ' ' . trim($fallbackTime));
                    return $parsed->format('Y-m-d H:i:s');
                } catch (\Throwable $e) {
                }
            }
            return $trimmed . ' ' . Carbon::now()->timezone($timezone)->format('H:i:s');
        }
        try {
            return Carbon::parse($trimmed)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return str_contains($trimmed, ':') ? $trimmed : ($trimmed . ' ' . Carbon::now()->timezone($timezone)->format('H:i:s'));
        }
    }

    private function resolveActiveOpenCashRegisterId(
        ?int $explicitRegId,
        ?int $warehouseId,
        ?string $warehouseName,
        ?int $ownerId,
        ?string $ownerName,
        bool $requireOpenIfExplicit = true,
        ?Request $request = null
    ): ?int {
        if ($explicitRegId) {
            $reg = CashRegister::lockForUpdate()->find($explicitRegId);
            if (!$reg || !in_array(strtolower(trim((string)$reg->status)), ['open', 'opened'], true)) {
                if ($requireOpenIfExplicit) {
                    $this->throwValidationError('cash_register_id', 'Selected cash register is closed or does not exist.');
                }
                return $explicitRegId;
            }
            if ($request && $reg->warehouse_id) {
                $this->assertExpenseOutletAccess($request, (int)$reg->warehouse_id);
            }
            if ($warehouseId && $reg->warehouse_id && (int)$reg->warehouse_id !== (int)$warehouseId) {
                $this->throwValidationError('cash_register_id', 'Selected cash register belongs to a different branch/warehouse.');
            }
            if ($request) {
                $authUser = User::resolveFromRequest($request) ?? $request->user();
                if ($authUser && !$this->isPrivilegedRegisterActor($authUser)) {
                    $ownsById = $reg->user_id && (int)$reg->user_id === (int)$authUser->id;
                    $ownsByName = !$reg->user_id && $reg->user_name && strcasecmp(trim((string)$reg->user_name), trim((string)$authUser->name)) === 0;
                    if (!$ownsById && !$ownsByName) {
                        $this->throwValidationError('cash_register_id', 'You cannot post cash transactions against another cashier\'s cash register.');
                    }
                }
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
                if ($ownerId) {
                    $q->where('user_id', $ownerId);
                } elseif ($ownerName) {
                    $q->where('user_name', $ownerName);
                }
            })->orderByDesc('id')->lockForUpdate()->first();
            if ($owned) {
                return (int)$owned->id;
            }
        }
        return null;
    }

    private function isPrivilegedRegisterActor(?User $user): bool
    {
        if (!$user) {
            return true;
        }
        $role = strtolower(trim((string)($user->roleModel?->name ?? $user->role ?? '')));
        return in_array($role, ['super admin', 'super_admin', 'admin', 'administrator', 'owner', 'manager', 'store manager', 'accountant'], true);
    }

    private function extractExpenseCashByRegister(Expense $expense): array
    {
        $accounting = app(PurchaseAccountingService::class);
        $expense->loadMissing('payments');
        $byReg = [];

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

    private function isTransferLinkedExpense(Expense $expense): bool
    {
        if (!empty($expense->transfer_id)) {
            return true;
        }
        $ref = strtoupper(trim((string)$expense->reference_no));
        return str_contains($ref, 'TRF-');
    }

    /**
     * Generate next sequential expense reference code (e.g. EXP_0010001).
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('expense'));
    }

    public function buildFilteredQuery(Request $request, bool $forExport = false)
    {
        $selectCols = [
            'id', 'reference_no', 'warehouse_name', 'warehouse_id',
            'cash_register_id', 'transfer_id',
            'expense_category_id', 'expense_category_name', 'title',
            'amount', 'paid_amount', 'due_amount', 'payment_status',
            'payment_method', 'payment_method_id', 'date', 'details',
            'created_by', 'user_id', 'user_name', 'user_role',
            'created_at', 'updated_at',
        ];

        $query = Expense::select($selectCols);

        if (!$forExport && !$request->boolean('no_items')) {
            $query->with([
                'category:id,name',
                'payments:id,expense_id,reference_no,payment_date,payment_time,payment_type,cash_register_id,amount,received_amount,change_amount,notes,user_id,user_name,user_role,created_by',
            ]);
        }

        $allowed = app(PurchaseAccountingService::class)->extractAllowedOutletIds($request);
        if (!empty($allowed)) {
            $query->whereIn('warehouse_id', $allowed);
        }

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $query->where('warehouse_id', (int)$request->input('warehouse_id'));
        }

        $warehouseFilter = $request->input('warehouse') ?: $request->input('warehouse_name');
        if (!empty($warehouseFilter) && $warehouseFilter !== 'All') {
            $query->where('warehouse_name', $warehouseFilter);
        }

        $catId = $request->input('category_id') ?: $request->input('expense_category_id');
        if (!empty($catId) && $catId !== 'All') {
            $query->where('expense_category_id', (int)$catId);
        }

        $catName = $request->input('category_name') ?: ($request->input('category') ?: $request->input('expense_category_name'));
        if (!empty($catName) && $catName !== 'All') {
            $query->where('expense_category_name', $catName);
        }

        $pm = $request->input('payment_method') ?: $request->input('payment_type');
        if (!empty($pm) && $pm !== 'All') {
            app(PurchaseAccountingService::class)->applyPaymentTypeFilter(
                $query,
                (string)$pm,
                'payment_method',
                'payments'
            );
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        $fromDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
        $toDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
        app(PurchaseAccountingService::class)->applyDateRangeFilter(
            $query,
            $fromDate ? (string)$fromDate : null,
            $toDate ? (string)$toDate : null,
            'date'
        );

        $userFilter = $request->input('created_by') ?: ($request->input('user') ?: $request->input('user_name'));
        if (!empty($userFilter) && $userFilter !== 'All') {
            $filterUser = trim((string)$userFilter);
            $query->where(function ($q) use ($filterUser) {
                $q->where('created_by', $filterUser)
                  ->orWhere('created_by', 'like', $filterUser . ' (%')
                  ->orWhere('user_name', $filterUser);
            });
        }

        if ($request->filled('user_role') && $request->input('user_role') !== 'All') {
            $query->where('user_role', $request->input('user_role'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            if (preg_match('/^EXP[-_]/i', $search)) {
                $altHyphen = str_replace('_', '-', $search);
                $altUnderscore = str_replace('-', '_', $search);
                $query->where(function ($q) use ($search, $altHyphen, $altUnderscore) {
                    $q->where('reference_no', 'like', "{$search}%");
                    if ($altHyphen !== $search) {
                        $q->orWhere('reference_no', 'like', "{$altHyphen}%");
                    }
                    if ($altUnderscore !== $search) {
                        $q->orWhere('reference_no', 'like', "{$altUnderscore}%");
                    }
                });
            } else {
                $query->where(function ($q) use ($search) {
                    $q->where('reference_no', 'like', "%{$search}%")
                      ->orWhere('title', 'like', "%{$search}%")
                      ->orWhere('warehouse_name', 'like', "%{$search}%")
                      ->orWhere('expense_category_name', 'like', "%{$search}%")
                      ->orWhere('payment_method', 'like', "%{$search}%")
                      ->orWhere('created_by', 'like', "%{$search}%")
                      ->orWhere('user_name', 'like', "%{$search}%")
                      ->orWhere('user_role', 'like', "%{$search}%")
                      ->orWhere('details', 'like', "%{$search}%");
                });
            }
        }

        return $query;
    }

    private function hasActiveFilters(Request $request): bool
    {
        $filterKeys = [
            ['warehouse_id', true],
            ['warehouse', true],
            ['warehouse_name', true],
            ['category_id', true],
            ['expense_category_id', true],
            ['category_name', true],
            ['category', true],
            ['expense_category_name', true],
            ['payment_method', true],
            ['payment_type', true],
            ['payment_status', true],
            ['from_date', false],
            ['date_from', false],
            ['start_date', false],
            ['to_date', false],
            ['date_to', false],
            ['end_date', false],
            ['created_by', true],
            ['user', true],
            ['user_name', true],
            ['user_role', true],
            ['search', false],
        ];

        foreach ($filterKeys as [$key, $ignoreAll]) {
            $val = $request->input($key);
            if ($val !== null && trim((string)$val) !== '' && (!$ignoreAll || $val !== 'All')) {
                return true;
            }
        }

        return false;
    }

    private function computeFilteredSummary($query): array
    {
        $payCashCond = "(LOWER(TRIM(COALESCE(payment_type, ''))) IN ('cash', 'petty cash', 'cash payment', 'cash on delivery', 'cod') OR (LOWER(COALESCE(payment_type, '')) LIKE '%cash%' AND LOWER(COALESCE(payment_type, '')) NOT LIKE '%non-cash%' AND LOWER(COALESCE(payment_type, '')) NOT LIKE '%non cash%' AND LOWER(COALESCE(payment_type, '')) NOT LIKE '%cashless%'))";
        $expCashCond = "(LOWER(TRIM(COALESCE(payment_method, ''))) IN ('cash', 'petty cash', 'cash payment', 'cash on delivery', 'cod') OR (LOWER(COALESCE(payment_method, '')) LIKE '%cash%' AND LOWER(COALESCE(payment_method, '')) NOT LIKE '%non-cash%' AND LOWER(COALESCE(payment_method, '')) NOT LIKE '%non cash%' AND LOWER(COALESCE(payment_method, '')) NOT LIKE '%cashless%'))";

        $baseQuery = (clone $query)->without(['category', 'payments'])->toBase();
        $baseQuery->columns = null;
        $baseQuery->orders = null;
        $agg = $baseQuery->selectRaw("
            COUNT(*) as total_records,
            MAX(updated_at) as max_updated_at,
            COALESCE(SUM(amount), 0) as total_amount,
            COALESCE(SUM(CASE
                WHEN LOWER(TRIM(COALESCE(payment_status, ''))) = 'unpaid' THEN 0
                ELSE COALESCE(paid_amount, CASE WHEN COALESCE(due_amount, 0) > 0 THEN (CASE WHEN amount - due_amount > 0 THEN amount - due_amount ELSE 0 END) ELSE amount END)
            END), 0) as total_paid,
            COALESCE(SUM(COALESCE(due_amount, 0)), 0) as total_due
        ")->first();

        $expenseIdSubquery = (clone $query)->without(['category', 'payments'])->toBase();
        $expenseIdSubquery->columns = ['id'];
        $expenseIdSubquery->orders = null;

        $payAgg = DB::table('expense_payments')
            ->whereIn('expense_id', $expenseIdSubquery)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN {$payCashCond} THEN amount ELSE 0 END), 0) as cash_total,
                COALESCE(SUM(CASE WHEN NOT ({$payCashCond}) AND LOWER(TRIM(COALESCE(payment_type, ''))) NOT IN ('', 'unpaid', 'n/a', 'split payment') THEN amount ELSE 0 END), 0) as digital_total
            ")->first();

        $legacyQuery = (clone $query)->without(['category', 'payments'])->toBase();
        $legacyQuery->columns = null;
        $legacyQuery->orders = null;
        $legacyAgg = $legacyQuery
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('expense_payments')
                  ->whereColumn('expense_payments.expense_id', 'expenses.id');
            })
            ->whereRaw("LOWER(TRIM(COALESCE(payment_status, ''))) != 'unpaid'")
            ->whereRaw('COALESCE(paid_amount, 0) > 0')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN {$expCashCond} THEN paid_amount ELSE 0 END), 0) as cash_total,
                COALESCE(SUM(CASE WHEN NOT ({$expCashCond}) AND LOWER(TRIM(COALESCE(payment_method, ''))) NOT IN ('', 'unpaid', 'n/a', 'split payment') THEN paid_amount ELSE 0 END), 0) as digital_total
            ")->first();

        return [
            'total_amount'   => (float)($agg->total_amount ?? 0),
            'total_paid'     => (float)($agg->total_paid ?? 0),
            'total_due'      => (float)($agg->total_due ?? 0),
            'cash_total'     => (float)($payAgg->cash_total ?? 0) + (float)($legacyAgg->cash_total ?? 0),
            'digital_total'  => (float)($payAgg->digital_total ?? 0) + (float)($legacyAgg->digital_total ?? 0),
            'total_records'  => (int)($agg->total_records ?? 0),
            'max_updated_at' => (string)($agg->max_updated_at ?? ''),
        ];
    }

    public function index(Request $request)
    {
        $accounting = app(PurchaseAccountingService::class);
        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        $noItems = $request->boolean('no_items');
        $skipSummary = $request->boolean('skip_summary');
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;

        // High-performance sorting
        $sortColumn = $request->input('sort_by', 'id');
        $sortDirection = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumnMap = [
            'ref' => 'reference_no',
            'reference' => 'reference_no',
            'warehouse' => 'warehouse_name',
            'category' => 'expense_category_name',
            'due' => 'due_amount',
            'paid' => 'paid_amount',
            'paymentMethod' => 'payment_method',
            'createdBy' => 'created_by',
            'user' => 'created_by',
        ];
        if (isset($sortColumnMap[$sortColumn])) {
            $sortColumn = $sortColumnMap[$sortColumn];
        }
        $allowedSorts = [
            'id', 'date', 'amount', 'paid_amount', 'due_amount', 'title',
            'reference_no', 'warehouse_name', 'expense_category_name',
            'payment_method', 'payment_status', 'created_by', 'user_name'
        ];
        if (!in_array($sortColumn, $allowedSorts, true)) {
            $sortColumn = 'id';
        }

        $perPage = max(1, min(1000, (int)$request->input('per_page', 50)));
        $page = max(1, (int)$request->input('page', 1));
        $cursorData = !$isAll
            ? ListCursorHelper::decode(
                (string)$request->input('cursor', ''),
                $sortColumn,
                $sortDirection,
                $allowedSorts,
                ['id'],
                ['amount', 'paid_amount', 'due_amount']
            )
            : null;
        $isScrollPage = ($page > 1 || $cursorData !== null);

        $query = $this->buildFilteredQuery($request, false);
        if ($skipSummary && $knownTotal !== null && $isScrollPage) {
            $summary = [
                'total_amount'   => 0.0,
                'total_paid'     => 0.0,
                'total_due'      => 0.0,
                'cash_total'     => 0.0,
                'digital_total'  => 0.0,
                'total_records'  => $knownTotal,
            ];
        } else {
            $summary = $this->computeFilteredSummary($query);
        }

        $totalRecords = (int)$summary['total_records'];
        $summaryPayload = [
            'total_amount'   => (float)$summary['total_amount'],
            'total_paid'     => (float)$summary['total_paid'],
            'total_due'      => (float)$summary['total_due'],
            'cash_total'     => (float)$summary['cash_total'],
            'digital_total'  => (float)$summary['digital_total'],
            'total_records'  => $totalRecords,
        ];

        $query->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        $cacheControl = 'no-store, no-cache, must-revalidate, private';
        if ($isAll) {
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            $items = $query->take($limit)->get();
            return response()->json([
                'status'  => 'success',
                'data'    => $items,
                'summary' => $summaryPayload,
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $items->count(),
                    'total'        => $totalRecords,
                    'last_page'    => max(1, (int)ceil($totalRecords / max(1, $limit))),
                ],
            ])->header('Cache-Control', $cacheControl);
        }

        if ($cursorData !== null) {
            ListCursorHelper::apply($query, $sortColumn, $sortDirection, $cursorData);
            $fetched = $query->limit($perPage + 1)->get();
        } else {
            $fetched = $query->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
        }
        $hasMore = $fetched->count() > $perPage;
        $items = $hasMore ? $fetched->slice(0, $perPage)->values() : $fetched;
        $lastRowObj = $items->last();
        $nextCursor = ($hasMore && $lastRowObj !== null)
            ? ListCursorHelper::encode((object)$lastRowObj, $sortColumn, $sortDirection, $allowedSorts)
            : null;
        $lastPage = max(1, (int)ceil($totalRecords / max(1, $perPage)));

        $pagination = [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $totalRecords,
            'last_page'    => $lastPage,
            'has_more'     => $hasMore,
        ];
        if ($nextCursor !== null) {
            $pagination['next_cursor'] = $nextCursor;
        }

        return response()->json([
            'status'  => 'success',
            'data'    => $items,
            'summary' => $summaryPayload,
            'pagination' => $pagination,
        ])->header('Cache-Control', $cacheControl);
    }

    /**
     * Stream complete RFC-4180 UTF-8 BOM CSV matching all active filters.
     */
    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $query = $this->buildFilteredQuery($request, true);

        $sortColumn = $request->input('sort_by', 'id');
        $sortDirection = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumnMap = [
            'ref' => 'reference_no',
            'reference' => 'reference_no',
            'warehouse' => 'warehouse_name',
            'category' => 'expense_category_name',
            'due' => 'due_amount',
            'paid' => 'paid_amount',
            'paymentMethod' => 'payment_method',
            'createdBy' => 'created_by',
            'user' => 'created_by',
        ];
        if (isset($sortColumnMap[$sortColumn])) {
            $sortColumn = $sortColumnMap[$sortColumn];
        }
        $allowedSorts = [
            'id', 'date', 'amount', 'paid_amount', 'due_amount', 'title',
            'reference_no', 'warehouse_name', 'expense_category_name',
            'payment_method', 'payment_status', 'created_by', 'user_name'
        ];
        if (!in_array($sortColumn, $allowedSorts, true)) {
            $sortColumn = 'id';
        }
        $query->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="expenses_export_' . date('Ymd_His') . '.csv"',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $warehouseFilter = $request->input('warehouse') ?: ($request->input('warehouse_name') ?: 'All');
        $categoryFilter = $request->input('category_name') ?: ($request->input('category') ?: 'All');
        $paymentMethodFilter = $request->input('payment_method') ?: ($request->input('payment_type') ?: 'All');
        $paymentStatusFilter = $request->input('payment_status', 'All');
        $userFilter = $request->input('created_by') ?: ($request->input('user') ?: 'All');
        $fromDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
        $toDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
        $search = $request->input('search');

        $callback = function () use ($query, $warehouseFilter, $categoryFilter, $paymentMethodFilter, $paymentStatusFilter, $userFilter, $fromDate, $toDate, $search) {
            $output = fopen('php://output', 'w');

            // 1. UTF-8 BOM for Microsoft Excel on Windows
            fwrite($output, "\xEF\xBB\xBF");

            // 2. Metadata Header Block
            fputcsv($output, ['Expense Transactions Export']);
            fputcsv($output, ['Generated At', date('Y-m-d H:i:s')]);
            fputcsv($output, ['Warehouse Filter', $this->escapeCsvCell($warehouseFilter)]);
            fputcsv($output, ['Category Filter', $this->escapeCsvCell($categoryFilter)]);
            fputcsv($output, ['Payment Method Filter', $this->escapeCsvCell($paymentMethodFilter)]);
            fputcsv($output, ['Payment Status Filter', $this->escapeCsvCell($paymentStatusFilter)]);
            fputcsv($output, ['Created By Filter', $this->escapeCsvCell($userFilter)]);
            fputcsv($output, ['Date Range', ($fromDate ?: 'Beginning') . ' to ' . ($toDate ?: 'Present')]);
            if (!empty($search)) {
                fputcsv($output, ['Search Query', $this->escapeCsvCell($search)]);
            }
            fputcsv($output, []); // Blank separator line

            // 3. Table Column Headers (15 Columns)
            fputcsv($output, [
                'SN',
                'Reference No',
                'Warehouse',
                'Expense Category',
                'Expense Title',
                'Amount',
                'Paid Amount',
                'Due Amount',
                'Payment Status',
                'Payment Method',
                'Created By',
                'User Role',
                'Date',
                'Time',
                'Details / Notes',
            ]);

            // 4. Stream Data via toBase()->cursor() (Zero Eloquent Hydration, O(N) streaming)
            $sn = 1;
            $totalAmount = 0.0;
            $totalPaid = 0.0;
            $totalDue = 0.0;
            $flushCounter = 0;

            foreach ($query->toBase()->cursor() as $exp) {
                $amount = (float)$exp->amount;
                $paidAmt = strtolower(trim((string)($exp->payment_status ?? ''))) === 'unpaid'
                    ? 0.0
                    : (float)($exp->paid_amount ?? ($exp->due_amount > 0 ? max(0.0, $amount - (float)$exp->due_amount) : $amount));
                $dueAmt = max(0.0, (float)($exp->due_amount ?? ($amount - $paidAmt)));
                $payStatus = $exp->payment_status ?: ($paidAmt <= 0.001 ? 'Unpaid' : ($dueAmt <= 0.001 ? 'Paid' : 'Partial'));

                $totalAmount += $amount;
                $totalPaid += $paidAmt;
                $totalDue += $dueAmt;

                $userName = $exp->user_name ?: ($exp->created_by ?: 'System');
                $userRole = $exp->user_role ?: 'Cashier';

                $dateOnly = $exp->date ? explode(' ', trim($exp->date))[0] : '';
                $timeStr = '';
                if ($exp->created_at) {
                    $timeStr = date('H:i:s', strtotime($exp->created_at));
                } elseif ($exp->date && strpos($exp->date, ' ') !== false) {
                    $timeStr = explode(' ', trim($exp->date))[1];
                }

                $effectivePm = $exp->payment_method ?: ($payStatus === 'Unpaid' ? 'Unpaid' : 'N/A');

                fputcsv($output, [
                    $sn++,
                    $this->escapeCsvCell($exp->reference_no),
                    $this->escapeCsvCell($exp->warehouse_name ?: 'Main Warehouse Branch'),
                    $this->escapeCsvCell($exp->expense_category_name ?: 'General'),
                    $this->escapeCsvCell($exp->title),
                    number_format($amount, 2, '.', ''),
                    number_format($paidAmt, 2, '.', ''),
                    number_format($dueAmt, 2, '.', ''),
                    $this->escapeCsvCell($payStatus),
                    $this->escapeCsvCell($effectivePm),
                    $this->escapeCsvCell($userName),
                    $this->escapeCsvCell($userRole),
                    $dateOnly,
                    $timeStr,
                    $this->escapeCsvCell($exp->details ?: ''),
                ]);

                if (++$flushCounter % 500 === 0) {
                    flush();
                }
            }

            // 5. Accounting Summary Totals Row
            fputcsv($output, [
                'TOTAL',
                ($sn - 1) . ' Expenses',
                '—',
                '—',
                '—',
                number_format($totalAmount, 2, '.', ''),
                number_format($totalPaid, 2, '.', ''),
                number_format($totalDue, 2, '.', ''),
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
                '—',
            ]);

            fclose($output);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no'               => 'nullable|string|max:255|unique:expenses,reference_no',
            'warehouse_id'               => 'nullable|integer',
            'warehouse_name'             => 'required|string|max:255',
            'expense_category_id'        => 'nullable|integer',
            'expense_category_name'      => 'required|string|max:255',
            'title'                      => 'required|string|max:255',
            'amount'                     => 'required|numeric|min:0.01',
            'paid_amount'                => 'nullable|numeric|min:0',
            'due_amount'                 => 'nullable|numeric|min:0',
            'payment_status'             => 'nullable|string|in:Paid,Partial,Unpaid',
            'payment_method'             => 'nullable|string|max:255',
            'payment_method_id'          => 'nullable|integer',
            'cash_register_id'           => 'nullable|integer',
            'date'                       => 'required|string',
            'details'                    => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:255',
            'user_id'                    => 'nullable|integer',
            'payments'                   => 'nullable|array',
            'payments.*.amount'          => 'nullable|numeric|min:0',
            'payments.*.payment_type'    => 'nullable|string|max:255',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.payment_time'    => 'nullable|string',
            'payments.*.notes'           => 'nullable|string',
            'payments.*.received_amount' => 'nullable|numeric',
            'payments.*.change_amount'   => 'nullable|numeric',
            'payments.*.cash_register_id'=> 'nullable|integer',
        ]);

        $unitService       = app(ProductUnitConversionService::class);
        $accountingService = app(PurchaseAccountingService::class);

        $amount = round((float)$validated['amount'], 2);
        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
        }

        $paymentsList = array_values(array_filter(
            $validated['payments'] ?? [],
            static fn($p) => !empty($p['amount']) && (float)$p['amount'] > 0.0001
        ));

        DB::beginTransaction();
        try {
            [$warehouseId, $warehouseName] = $this->resolveExpenseWarehouse(
                $request,
                $validated['warehouse_id'] ?? null,
                $validated['warehouse_name'],
                $unitService
            );
            [$categoryId, $categoryName] = $this->resolveExpenseCategory(
                !empty($validated['expense_category_id']) ? (int)$validated['expense_category_id'] : null,
                $validated['expense_category_name']
            );
            $actor = $this->resolveExpenseActor($request, $validated);

            if (!empty($paymentsList)) {
                $totalPaid = round(array_sum(array_map(static fn($p) => round((float)$p['amount'], 2), $paymentsList)), 2);
                if ($totalPaid > $amount + 0.01) {
                    $this->throwValidationError(
                        'payments',
                        'Payment amount (' . number_format($totalPaid, 2) . ') cannot exceed expense amount (' . number_format($amount, 2) . ').'
                    );
                }
                // Clamp any sub-cent/1-cent tolerance excess on the last payment leg so paid_amount <= amount strictly
                // and cash_registers.expected_cash is never over-deducted.
                if ($totalPaid > $amount) {
                    $excess = round($totalPaid - $amount, 2);
                    $lastIdx = count($paymentsList) - 1;
                    $paymentsList[$lastIdx]['amount'] = max(0.0, round((float)$paymentsList[$lastIdx]['amount'] - $excess, 2));
                    $totalPaid = $amount;
                }
                $dueAmount       = max(0.0, round($amount - $totalPaid, 2));
                $paymentStatus   = $dueAmount <= 0.001 ? 'Paid' : ($totalPaid > 0.001 ? 'Partial' : 'Unpaid');
                $uniqueTypes     = collect($paymentsList)->pluck('payment_type')->filter()->unique();
                $effectiveMethod = $totalPaid <= 0.001
                    ? 'Unpaid'
                    : ($uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?: ($validated['payment_method'] ?? 'Cash')));
            } else {
                if (isset($validated['paid_amount'])) {
                    $totalPaid = round((float)$validated['paid_amount'], 2);
                    if ($totalPaid > $amount + 0.01) {
                        $this->throwValidationError(
                            'paid_amount',
                            'Paid amount (' . number_format($totalPaid, 2) . ') cannot exceed expense amount (' . number_format($amount, 2) . ').'
                        );
                    }
                    $totalPaid     = min($totalPaid, $amount);
                    $dueAmount     = max(0.0, round($amount - $totalPaid, 2));
                    $paymentStatus = $totalPaid <= 0.001 ? 'Unpaid' : ($dueAmount <= 0.001 ? 'Paid' : 'Partial');
                } elseif (isset($validated['payment_status']) && strcasecmp(trim($validated['payment_status']), 'Unpaid') === 0) {
                    $totalPaid     = 0.0;
                    $dueAmount     = $amount;
                    $paymentStatus = 'Unpaid';
                } else {
                    $totalPaid     = $amount;
                    $dueAmount     = 0.0;
                    $paymentStatus = 'Paid';
                }
                $effectiveMethod = $totalPaid <= 0.001 ? 'Unpaid' : (!empty($validated['payment_method']) ? $validated['payment_method'] : 'Cash');
            }

            $refNo           = $this->acquireUniqueReference('expense', $validated['reference_no'] ?? null);
            $headerCashRegId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;

            $expense = Expense::create([
                'reference_no'          => $refNo,
                'warehouse_id'          => $warehouseId,
                'warehouse_name'        => $warehouseName,
                'cash_register_id'      => null, // Stamped only if an actual Cash tender leg hits an open register
                'expense_category_id'   => $categoryId,
                'expense_category_name' => $categoryName,
                'title'                 => $validated['title'],
                'amount'                => $amount,
                'paid_amount'           => $totalPaid,
                'due_amount'            => $dueAmount,
                'payment_status'        => $paymentStatus,
                'payment_method'        => $effectiveMethod,
                'payment_method_id'     => $validated['payment_method_id'] ?? null,
                'date'                  => $txDate,
                'details'               => $validated['details'] ?? null,
                'created_by'            => $actor['user_name'],
                'user_name'             => $actor['user_name'],
                'user_role'             => $actor['user_role'],
                'user_id'               => $actor['user_id'],
            ]);

            if (!empty($paymentsList)) {
                $pIndex = 1;
                foreach ($paymentsList as $p) {
                    $pAmount = round((float)$p['amount'], 2);
                    if ($pAmount <= 0) {
                        continue;
                    }
                    $pType    = $p['payment_type'] ?? 'Cash';
                    $isCash   = $accountingService->isCashPaymentType($pType);
                    $legRegId = null;
                    if ($isCash) {
                        $explicitRegId = !empty($p['cash_register_id']) ? (int)$p['cash_register_id'] : $headerCashRegId;
                        $legRegId      = $this->resolveActiveOpenCashRegisterId(
                            $explicitRegId,
                            $warehouseId,
                            $warehouseName,
                            $actor['user_id'],
                            $actor['user_name'],
                            true,
                            $request
                        );
                    }

                    $legDate = $this->normalizeDateTimeString($p['payment_date'] ?? $txDate, $p['payment_time'] ?? null);
                    ExpensePayment::create([
                        'expense_id'       => $expense->id,
                        'cash_register_id' => $legRegId,
                        'reference_no'     => $this->generateUniqueExpensePaymentRef($refNo, $pIndex++),
                        'payment_date'     => $legDate,
                        'payment_time'     => $p['payment_time'] ?? date('h:i A'),
                        'payment_type'     => $pType,
                        'amount'           => $pAmount,
                        'received_amount'  => (float)($p['received_amount'] ?? $pAmount),
                        'change_amount'    => (float)($p['change_amount'] ?? 0),
                        'notes'            => $p['notes'] ?? ($validated['details'] ?? 'Disbursement tender'),
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
            } elseif ($totalPaid > 0.001) {
                $isCash   = $accountingService->isCashPaymentType($effectiveMethod);
                $legRegId = null;
                if ($isCash) {
                    $legRegId = $this->resolveActiveOpenCashRegisterId(
                        $headerCashRegId,
                        $warehouseId,
                        $warehouseName,
                        $actor['user_id'],
                        $actor['user_name'],
                        true,
                        $request
                    );
                }

                ExpensePayment::create([
                    'expense_id'       => $expense->id,
                    'cash_register_id' => $legRegId,
                    'reference_no'     => $this->generateUniqueExpensePaymentRef($refNo, 1),
                    'payment_date'     => $txDate,
                    'payment_time'     => date('h:i A'),
                    'payment_type'     => $effectiveMethod,
                    'amount'           => $totalPaid,
                    'received_amount'  => $totalPaid,
                    'change_amount'    => 0,
                    'notes'            => $validated['details'] ?? 'Initial disbursement',
                    'created_by'       => $actor['user_name'],
                    'user_id'          => $actor['user_id'],
                    'user_name'        => $actor['user_name'],
                    'user_role'        => $actor['user_role'],
                ]);

                if ($legRegId) {
                    if (!$expense->cash_register_id) {
                        $expense->update(['cash_register_id' => $legRegId]);
                    }
                    $accountingService->adjustOpenRegisterExpectedCash((int)$legRegId, -$totalPaid);
                }
            }

            DB::commit();
            $this->clearExpenseCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Expense created successfully',
                'data'    => $expense->fresh()->load(['category', 'payments']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $expense = Expense::with(['category', 'payments'])->find($id)
            ?? Expense::with(['category', 'payments'])->where('reference_no', $id)->first();
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
        }
        $this->assertExpenseOutletAccess($request, $expense->warehouse_id);
        return response()->json(['status' => 'success', 'data' => $expense]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'reference_no'               => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('expenses', 'reference_no')->ignore($id)],
            'warehouse_id'               => 'sometimes|nullable|integer',
            'warehouse_name'             => 'sometimes|required|string|max:255',
            'expense_category_id'        => 'nullable|integer',
            'expense_category_name'      => 'sometimes|required|string|max:255',
            'title'                      => 'sometimes|required|string|max:255',
            'amount'                     => 'sometimes|required|numeric|min:0.01',
            'payment_method'             => 'sometimes|nullable|string|max:255',
            'payment_method_id'          => 'sometimes|nullable|integer',
            'cash_register_id'           => 'sometimes|nullable|integer',
            'date'                       => 'sometimes|required|string',
            'details'                    => 'nullable|string',
            'created_by'                 => 'sometimes|nullable|string|max:255',
            'user_name'                  => 'sometimes|nullable|string|max:255',
            'user_role'                  => 'sometimes|nullable|string|max:255',
            'user_id'                    => 'sometimes|nullable|integer',
            'payments'                   => 'sometimes|nullable|array',
            'payments.*.id'              => 'nullable|integer',
            'payments.*.amount'          => 'nullable|numeric|min:0',
            'payments.*.payment_type'    => 'nullable|string|max:255',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.payment_time'    => 'nullable|string',
            'payments.*.notes'           => 'nullable|string',
            'payments.*.received_amount' => 'nullable|numeric',
            'payments.*.change_amount'   => 'nullable|numeric',
            'payments.*.cash_register_id'=> 'nullable|integer',
        ]);

        $unitService       = app(ProductUnitConversionService::class);
        $accountingService = app(PurchaseAccountingService::class);
        $hasPaymentsParam  = $request->has('payments') && is_array($request->input('payments'));
        $submittedPayments = $hasPaymentsParam
            ? array_values(array_filter(
                $validated['payments'] ?? [],
                static fn($p) => !empty($p['amount']) && (float)$p['amount'] > 0.0001
            ))
            : [];
        unset($validated['payments']);

        if (array_key_exists('date', $validated)) {
            $validated['date'] = $this->normalizeDateTimeString($validated['date']);
        }

        DB::beginTransaction();
        try {
            $expense = Expense::lockForUpdate()->find($id);
            if (!$expense) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
            }

            $this->assertExpenseOutletAccess($request, $expense->warehouse_id);

            if ($this->isTransferLinkedExpense($expense)) {
                $changingRestricted = array_key_exists('amount', $validated) && abs((float)$validated['amount'] - (float)$expense->amount) > 0.001;
                if (!$changingRestricted && (
                    array_key_exists('warehouse_id', $validated) ||
                    array_key_exists('warehouse_name', $validated) ||
                    array_key_exists('expense_category_name', $validated)
                )) {
                    $newWhName  = trim((string)($validated['warehouse_name'] ?? $expense->warehouse_name));
                    $newCatName = trim((string)($validated['expense_category_name'] ?? $expense->expense_category_name));
                    $changingRestricted = ($newWhName !== trim((string)$expense->warehouse_name))
                        || ($newCatName !== trim((string)$expense->expense_category_name));
                }
                if ($changingRestricted) {
                    $this->throwValidationError(
                        'amount',
                        'This shipping expense is linked to a Stock Transfer. Edit its warehouse, category, or amount from the Stock Transfer instead.'
                    );
                }
            }

            $warehouseChanged = false;
            $oldWarehouseId   = (int)($expense->warehouse_id ?? 0);
            if (array_key_exists('warehouse_name', $validated) || array_key_exists('warehouse_id', $validated)) {
                [$wId, $wName] = $this->resolveExpenseWarehouse(
                    $request,
                    $validated['warehouse_id'] ?? $expense->warehouse_id,
                    $validated['warehouse_name'] ?? $expense->warehouse_name,
                    $unitService
                );
                if ((int)($wId ?? 0) !== $oldWarehouseId) {
                    $warehouseChanged = true;
                    $beforeCashByRegCheck = $this->extractExpenseCashByRegister($expense);
                    if ($regErr = $accountingService->assertCashRegisterDeltaMutable($beforeCashByRegCheck, [])) {
                        $this->throwValidationError('warehouse_id', $regErr);
                    }
                }
                $validated['warehouse_id']   = $wId;
                $validated['warehouse_name'] = $wName;
            }

            if (array_key_exists('expense_category_id', $validated) || array_key_exists('expense_category_name', $validated)) {
                [$cId, $cName] = $this->resolveExpenseCategory(
                    !empty($validated['expense_category_id']) ? (int)$validated['expense_category_id'] : null,
                    $validated['expense_category_name'] ?? $expense->expense_category_name
                );
                $validated['expense_category_id']   = $cId;
                $validated['expense_category_name'] = $cName;
            }

            $allPayments = ExpensePayment::where('expense_id', $expense->id)->lockForUpdate()->get();
            $newAmount   = array_key_exists('amount', $validated) ? round((float)$validated['amount'], 2) : (float)$expense->amount;
            $actor       = $this->resolveExpenseActor($request, $validated, $expense);
            $effectiveWhId   = (int)($validated['warehouse_id'] ?? $expense->warehouse_id ?? 0) ?: null;
            $effectiveWhName = (string)($validated['warehouse_name'] ?? $expense->warehouse_name ?? '');
            $headerCashRegId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
            unset($validated['cash_register_id']);

            if ($hasPaymentsParam) {
                $beforeCashByReg = $this->extractExpenseCashByRegister($expense);
                $submittedTotal  = round(array_sum(array_map(static fn($p) => round((float)$p['amount'], 2), $submittedPayments)), 2);

                if ($submittedTotal > $newAmount + 0.01) {
                    $this->throwValidationError(
                        'payments',
                        'Payment amount (' . number_format($submittedTotal, 2) . ') cannot exceed expense amount (' . number_format($newAmount, 2) . ').'
                    );
                }
                if ($submittedTotal > $newAmount && !empty($submittedPayments)) {
                    $excess  = round($submittedTotal - $newAmount, 2);
                    $lastIdx = count($submittedPayments) - 1;
                    $submittedPayments[$lastIdx]['amount'] = max(0.0, round((float)$submittedPayments[$lastIdx]['amount'] - $excess, 2));
                }

                // Resolve cash registers for new payment legs and verify no closed-shift cash total changes
                $existingById = $allPayments->keyBy('id');
                $resolvedLegs = [];
                $afterCashByReg = [];
                foreach ($submittedPayments as $idx => $p) {
                    $pAmt = round((float)$p['amount'], 2);
                    if ($pAmt <= 0) {
                        continue;
                    }
                    $pType  = $p['payment_type'] ?? 'Cash';
                    $isCash = $accountingService->isCashPaymentType($pType);
                    $legRegId = null;

                    if ($isCash) {
                        $existingLeg = !empty($p['id']) ? $existingById->get((int)$p['id']) : null;
                        $candidateRegId = !empty($p['cash_register_id'])
                            ? (int)$p['cash_register_id']
                            : ($existingLeg && !$warehouseChanged && $existingLeg->cash_register_id ? (int)$existingLeg->cash_register_id : $headerCashRegId);

                        if ($candidateRegId && !$warehouseChanged) {
                            $existingReg = CashRegister::lockForUpdate()->find($candidateRegId);
                            if ($existingReg && !in_array(strtolower(trim((string)$existingReg->status)), ['open', 'opened'], true)) {
                                // Keep closed register ID for delta check below; if total matches, it stays immutable; if changed, delta check rejects.
                                $legRegId = (int)$existingReg->id;
                            } else {
                                $legRegId = $this->resolveActiveOpenCashRegisterId(
                                    $candidateRegId,
                                    $effectiveWhId,
                                    $effectiveWhName,
                                    $actor['user_id'],
                                    $actor['user_name'],
                                    true,
                                    $request
                                );
                            }
                        } else {
                            $legRegId = $this->resolveActiveOpenCashRegisterId(
                                $headerCashRegId,
                                $effectiveWhId,
                                $effectiveWhName,
                                $actor['user_id'],
                                $actor['user_name'],
                                true,
                                $request
                            );
                        }

                        if ($legRegId) {
                            $afterCashByReg[$legRegId] = round(($afterCashByReg[$legRegId] ?? 0.0) + $pAmt, 2);
                        }
                    }

                    $resolvedLegs[] = [
                        'payment_type'     => $pType,
                        'amount'           => $pAmt,
                        'received_amount'  => (float)($p['received_amount'] ?? $pAmt),
                        'change_amount'    => (float)($p['change_amount'] ?? 0),
                        'payment_date'     => $this->normalizeDateTimeString($p['payment_date'] ?? ($validated['date'] ?? $expense->date), $p['payment_time'] ?? null),
                        'payment_time'     => $p['payment_time'] ?? date('h:i A'),
                        'notes'            => $p['notes'] ?? ($validated['details'] ?? $expense->details ?? 'Disbursement tender'),
                        'cash_register_id' => $legRegId,
                    ];
                }

                if ($regErr = $accountingService->assertCashRegisterDeltaMutable($beforeCashByReg, $afterCashByReg)) {
                    $this->throwValidationError('payments', $regErr);
                }

                // Replace payment legs atomically and reconcile open cash registers by exact per-register delta
                ExpensePayment::where('expense_id', $expense->id)->delete();
                $pIndex = 1;
                $firstCashRegId = null;
                foreach ($resolvedLegs as $leg) {
                    if ($leg['cash_register_id'] && !$firstCashRegId) {
                        $firstCashRegId = (int)$leg['cash_register_id'];
                    }
                    ExpensePayment::create([
                        'expense_id'       => $expense->id,
                        'cash_register_id' => $leg['cash_register_id'],
                        'reference_no'     => $this->generateUniqueExpensePaymentRef($expense->reference_no, $pIndex++),
                        'payment_date'     => $leg['payment_date'],
                        'payment_time'     => $leg['payment_time'],
                        'payment_type'     => $leg['payment_type'],
                        'amount'           => $leg['amount'],
                        'received_amount'  => $leg['received_amount'],
                        'change_amount'    => $leg['change_amount'],
                        'notes'            => $leg['notes'],
                        'created_by'       => $actor['user_name'],
                        'user_id'          => $actor['user_id'],
                        'user_name'        => $actor['user_name'],
                        'user_role'        => $actor['user_role'],
                    ]);
                }

                $allRegIds = array_unique(array_merge(array_keys($beforeCashByReg), array_keys($afterCashByReg)));
                foreach ($allRegIds as $rId) {
                    $delta = round(($beforeCashByReg[$rId] ?? 0.0) - ($afterCashByReg[$rId] ?? 0.0), 2);
                    if (abs($delta) > 0.001) {
                        $accountingService->adjustOpenRegisterExpectedCash((int)$rId, $delta);
                    }
                }

                $validated['cash_register_id'] = $firstCashRegId;
                $allPayments = ExpensePayment::where('expense_id', $expense->id)->get();
                $totalPaid   = round((float)$allPayments->sum('amount'), 2);
            } else {
                // Protect legacy payment-less paid expenses from being wiped to 0
                $totalPaid = $allPayments->isNotEmpty()
                    ? round((float)$allPayments->sum('amount'), 2)
                    : round((float)$expense->paid_amount, 2);

                if ($newAmount < $totalPaid - 0.01) {
                    $this->throwValidationError(
                        'amount',
                        'Cannot reduce expense amount below already paid amount (' . number_format($totalPaid, 2) . '). Delete excess payments first.'
                    );
                }

                // If warehouse changed on an expense with open-register cash legs, re-attribute registers cleanly
                if ($warehouseChanged) {
                    $beforeCashByReg = $this->extractExpenseCashByRegister($expense);
                    if (!empty($beforeCashByReg)) {
                        foreach ($beforeCashByReg as $oldRegId => $oldAmt) {
                            $accountingService->adjustOpenRegisterExpectedCash((int)$oldRegId, $oldAmt);
                        }
                        $newWhRegId = $this->resolveActiveOpenCashRegisterId(
                            null,
                            $effectiveWhId,
                            $effectiveWhName,
                            $actor['user_id'],
                            $actor['user_name'],
                            false,
                            $request
                        );
                        $movedCash = 0.0;
                        foreach ($allPayments as $existingLeg) {
                            if ($accountingService->isCashPaymentType($existingLeg->payment_type)) {
                                $existingLeg->update(['cash_register_id' => $newWhRegId]);
                                $movedCash += round((float)$existingLeg->amount, 2);
                            }
                        }
                        if ($allPayments->isEmpty() && $totalPaid > 0.001 && $accountingService->isCashPaymentType($expense->payment_method)) {
                            $movedCash = $totalPaid;
                        }
                        if ($newWhRegId && $movedCash > 0.001) {
                            $accountingService->adjustOpenRegisterExpectedCash((int)$newWhRegId, -$movedCash);
                        }
                        $validated['cash_register_id'] = $newWhRegId;
                    }
                }
            }

            $validated['created_by'] = $actor['user_name'];
            $validated['user_name']  = $actor['user_name'];
            $validated['user_role']  = $actor['user_role'];
            $validated['user_id']    = $actor['user_id'];

            $expense->update($validated);

            $newDue           = max(0.0, round((float)$expense->amount - $totalPaid, 2));
            $newStatus        = $totalPaid <= 0.001 ? 'Unpaid' : ($newDue <= 0.001 ? 'Paid' : 'Partial');
            $uniqueTypes      = $allPayments->pluck('payment_type')->filter()->unique();
            $newPaymentMethod = $totalPaid <= 0.001
                ? 'Unpaid'
                : ($uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?: $expense->payment_method));

            $expense->update([
                'paid_amount'    => $totalPaid,
                'due_amount'     => $newDue,
                'payment_status' => $newStatus,
                'payment_method' => $newPaymentMethod,
            ]);

            if (!empty($expense->transfer_id)) {
                StockTransfer::where('id', $expense->transfer_id)->update(['updated_at' => now()]);
            }

            DB::commit();
            $this->clearExpenseCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Expense updated successfully',
                'data'    => $expense->fresh()->load(['category', 'payments']),
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
        $accountingService = app(PurchaseAccountingService::class);

        DB::beginTransaction();
        try {
            $expense = Expense::with('payments')->lockForUpdate()->find($id);
            if (!$expense) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
            }

            $this->assertExpenseOutletAccess($request, $expense->warehouse_id);

            if ($this->isTransferLinkedExpense($expense)) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot delete a Transfer Shipping expense directly. Edit or delete the Stock Transfer instead.',
                ], 422);
            }

            $beforeCashByReg = $this->extractExpenseCashByRegister($expense);
            if (!empty($beforeCashByReg)) {
                if ($errMsg = $accountingService->assertCashRegisterDeltaMutable($beforeCashByReg, [])) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $errMsg], 422);
                }
                foreach ($beforeCashByReg as $regId => $cashOut) {
                    $accountingService->adjustOpenRegisterExpectedCash((int)$regId, round((float)$cashOut, 2));
                }
            }

            $expense->payments()->delete();
            $expense->delete();

            DB::commit();
            $this->clearExpenseCache();

            return response()->json(['status' => 'success', 'message' => 'Expense deleted']);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function addPayment(Request $request, $id)
    {
        $validated = $request->validate([
            'amount'                      => 'nullable|numeric|min:0',
            'payment_type'                => 'required_without:payments|nullable|string',
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
            'user_name'                   => 'nullable|string|max:255',
            'user_role'                   => 'nullable|string|max:255',
            'user_id'                     => 'nullable|integer',
        ]);

        $authUser    = User::resolveFromRequest($request) ?? $request->user();
        $authRole    = $authUser?->roleModel?->name ?? ($authUser?->role ?? null);
        // Trust authenticated user identity first; fall back to request metadata only when unauthenticated.
        $userName    = $authUser?->name ?? ($request->input('user_name') ?? 'Super Admin');
        $userRole    = $authRole ?? ($request->input('user_role') ?? 'Super Admin');
        $userId      = $authUser?->id ?? ($request->input('user_id') ? (int)$request->input('user_id') : null);

        $timezone    = Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
        $nowTz       = Carbon::now()->timezone($timezone);
        $defaultDate = $nowTz->format('Y-m-d H:i:s');
        $defaultTime = $nowTz->format('h:i A');

        $rawPDate = $validated['payment_date'] ?? $defaultDate;
        $pTime = $request->input('payment_time');
        if (str_contains((string)$rawPDate, ' ') && empty($pTime)) {
            $parts = explode(' ', trim((string)$rawPDate), 2);
            try {
                $pTime = Carbon::parse($parts[1])->timezone($timezone)->format('h:i A');
            } catch (\Throwable $e) {
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
        $pDate = $this->normalizeDateTimeString($rawPDate, $pTime);

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
            return response()->json(['status' => 'error', 'message' => 'Payment amount must be greater than 0.'], 422);
        }

        $accountingService = app(PurchaseAccountingService::class);

        DB::beginTransaction();
        try {
            $expense = Expense::with('payments')->where('id', $id)->lockForUpdate()->first();
            if (!$expense && !is_numeric($id)) {
                $expense = Expense::with('payments')->where('reference_no', $id)->lockForUpdate()->first();
            } elseif (!$expense) {
                $expense = Expense::with('payments')->where('reference_no', $id)->lockForUpdate()->first();
            }
            if (!$expense) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
            }

            $this->assertExpenseOutletAccess($request, $expense->warehouse_id);

            // Preserve legacy payment-less paid balance before adding new legs so summing ExpensePayment never wipes it.
            if ($expense->payments->isEmpty() && (float)$expense->paid_amount > 0.001) {
                ExpensePayment::create([
                    'expense_id'       => $expense->id,
                    'cash_register_id' => $expense->cash_register_id,
                    'reference_no'     => $this->generateUniqueExpensePaymentRef($expense->reference_no, 1),
                    'payment_date'     => $this->normalizeDateTimeString($expense->date),
                    'payment_time'     => $defaultTime,
                    'payment_type'     => ($expense->payment_method && $expense->payment_method !== 'Unpaid') ? $expense->payment_method : 'Cash',
                    'amount'           => round((float)$expense->paid_amount, 2),
                    'received_amount'  => round((float)$expense->paid_amount, 2),
                    'change_amount'    => 0,
                    'notes'            => 'Legacy recorded payment preserved',
                    'created_by'       => $expense->created_by ?: $userName,
                    'user_id'          => $expense->user_id ?: $userId,
                    'user_name'        => $expense->user_name ?: $userName,
                    'user_role'        => $expense->user_role ?: $userRole,
                ]);
                $expense->load('payments');
            }

            $currentPaid = round((float)$expense->payments->sum('amount'), 2);
            $currentDue  = max(0.0, round((float)$expense->amount - $currentPaid, 2));

            if ($totalPaying > $currentDue + 0.01) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment amount (' . number_format($totalPaying, 2) . ') cannot exceed the remaining due amount (' . number_format($currentDue, 2) . ')',
                ], 422);
            }

            $headerCashRegId = !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null;
            $fallbackType    = $validated['payment_type'] ?? 'Cash';
            $legsToCreate    = [];

            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                foreach ($validated['payments'] as $p) {
                    $pAmount = round((float)($p['amount'] ?? 0), 2);
                    if ($pAmount <= 0) {
                        continue;
                    }
                    $rawItemDate = !empty($p['payment_date']) ? $p['payment_date'] : $pDate;
                    $itemTime    = !empty($p['payment_time']) ? $p['payment_time'] : $pTime;
                    if (str_contains((string)$rawItemDate, ' ') && empty($p['payment_time'])) {
                        $parts = explode(' ', trim((string)$rawItemDate), 2);
                        try {
                            $itemTime = Carbon::parse($parts[1])->timezone($timezone)->format('h:i A');
                        } catch (\Throwable $e) {
                        }
                    }
                    try {
                        $itemTime = Carbon::parse($itemTime)->timezone($timezone)->format('h:i A');
                    } catch (\Throwable $e) {
                        if (!preg_match('/(AM|PM)/i', (string)$itemTime)) {
                            $itemTime = $defaultTime;
                        }
                    }
                    $itemDate = $this->normalizeDateTimeString($rawItemDate, $itemTime);

                    $legsToCreate[] = [
                        'payment_type'     => $p['payment_type'] ?? ($p['type'] ?? $fallbackType),
                        'amount'           => $pAmount,
                        'received_amount'  => (float)($p['received_amount'] ?? $pAmount),
                        'change_amount'    => (float)($p['change_amount'] ?? 0),
                        'payment_date'     => $itemDate,
                        'payment_time'     => $itemTime,
                        'notes'            => $p['notes'] ?? ($validated['notes'] ?? null),
                        'user_name'        => $authUser ? $userName : (!empty($p['user_name']) ? $p['user_name'] : $userName),
                        'user_role'        => $authUser ? $userRole : (!empty($p['user_role']) ? $p['user_role'] : $userRole),
                        'user_id'          => $authUser ? $userId : (!empty($p['user_id']) ? (int)$p['user_id'] : $userId),
                        'cash_register_id' => !empty($p['cash_register_id']) ? (int)$p['cash_register_id'] : $headerCashRegId,
                    ];
                }
            } else {
                $payingAmount   = round($totalPaying, 2);
                $legsToCreate[] = [
                    'payment_type'     => $fallbackType,
                    'amount'           => $payingAmount,
                    'received_amount'  => (float)($validated['received_amount'] ?? $payingAmount),
                    'change_amount'    => (float)($validated['change_amount'] ?? 0),
                    'payment_date'     => $pDate,
                    'payment_time'     => $pTime,
                    'notes'            => $validated['notes'] ?? null,
                    'user_name'        => $userName,
                    'user_role'        => $userRole,
                    'user_id'          => $userId,
                    'cash_register_id' => $headerCashRegId,
                ];
            }

            // Clamp any sub-cent/1-cent tolerance excess on the last leg so paid_amount <= amount strictly
            $legsSum = round(array_sum(array_column($legsToCreate, 'amount')), 2);
            if ($legsSum > $currentDue && !empty($legsToCreate)) {
                $excess = round($legsSum - $currentDue, 2);
                $lastIdx = count($legsToCreate) - 1;
                $legsToCreate[$lastIdx]['amount'] = max(0.0, round($legsToCreate[$lastIdx]['amount'] - $excess, 2));
            }

            $seedIndex = ExpensePayment::where('expense_id', $expense->id)->count() + 1;
            foreach ($legsToCreate as $leg) {
                if ((float)$leg['amount'] <= 0.0001) {
                    continue;
                }
                $isCash   = $accountingService->isCashPaymentType($leg['payment_type'] ?? null);
                $legRegId = null;
                if ($isCash) {
                    $legRegId = $this->resolveActiveOpenCashRegisterId(
                        $leg['cash_register_id'],
                        $expense->warehouse_id ? (int)$expense->warehouse_id : null,
                        $expense->warehouse_name,
                        $leg['user_id'] ? (int)$leg['user_id'] : null,
                        $leg['user_name'],
                        true,
                        $request
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

            if (!empty($expense->transfer_id)) {
                StockTransfer::where('id', $expense->transfer_id)->update(['updated_at' => now()]);
            }

            DB::commit();
            $this->clearExpenseCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully',
                'data'    => $expense->fresh()->load(['category', 'payments']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof ValidationException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function deletePayment(Request $request, $expenseId, $paymentId = null)
    {
        $accountingService = app(PurchaseAccountingService::class);

        DB::beginTransaction();
        try {
            if ($paymentId === null) {
                $payment = ExpensePayment::lockForUpdate()->find($expenseId);
                if (!$payment) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => 'Payment record not found'], 404);
                }
                $expense = Expense::lockForUpdate()->find($payment->expense_id);
                if (!$expense) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => 'Associated expense not found'], 404);
                }
            } else {
                $expense = Expense::lockForUpdate()->find($expenseId)
                    ?? Expense::where('reference_no', $expenseId)->lockForUpdate()->first();
                if (!$expense) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
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
                    return response()->json(['status' => 'error', 'message' => 'Payment transaction not found for this expense'], 404);
                }
            }

            $this->assertExpenseOutletAccess($request, $expense->warehouse_id);

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

            $remainingPayments = ExpensePayment::where('expense_id', $expense->id)->get();
            $newPaid           = round((float)$remainingPayments->sum('amount'), 2);
            $newDue            = max(0.0, round((float)$expense->amount - $newPaid, 2));
            $newStatus         = $newPaid <= 0.001 ? 'Unpaid' : ($newDue <= 0.001 ? 'Paid' : 'Partial');

            $uniqueTypes = $remainingPayments->pluck('payment_type')->filter()->unique();
            if ($newPaid <= 0.001) {
                $newPaymentType = 'Unpaid';
            } elseif ($uniqueTypes->count() > 1) {
                $newPaymentType = 'Split Payment';
            } elseif ($uniqueTypes->count() === 1) {
                $newPaymentType = $uniqueTypes->first();
            } else {
                $newPaymentType = $expense->payment_method;
            }

            $remainingCashRegId = $remainingPayments
                ->first(fn ($p) => $accountingService->isCashPaymentType($p->payment_type) && !empty($p->cash_register_id))
                ?->cash_register_id;

            $expense->update([
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_method'   => $newPaymentType,
                'cash_register_id' => $remainingCashRegId ? (int)$remainingCashRegId : null,
            ]);

            if (!empty($expense->transfer_id)) {
                StockTransfer::where('id', $expense->transfer_id)->update(['updated_at' => now()]);
            }

            DB::commit();
            $this->clearExpenseCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully',
                'data'    => $expense->fresh()->load(['category', 'payments']),
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
