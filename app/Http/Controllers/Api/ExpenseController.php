<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ExpenseController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;
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
            'expense_category_id', 'expense_category_name', 'title',
            'amount', 'paid_amount', 'due_amount', 'payment_status',
            'payment_method', 'payment_method_id', 'date', 'details',
            'created_by', 'user_id', 'user_name', 'user_role', 'created_at'
        ];

        $query = Expense::select($selectCols);

        if (!$forExport) {
            $query->with(['category', 'payments']);
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
            $query->where('payment_method', $pm);
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        $fromDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
        if (!empty($fromDate)) {
            $fromDate = trim($fromDate);
            $fromStr = strlen($fromDate) === 10 ? ($fromDate . ' 00:00:00') : $fromDate;
            $query->where(function ($q) use ($fromDate, $fromStr) {
                $q->where('date', '>=', $fromDate)
                  ->orWhere('date', '>=', $fromStr);
            });
        }

        $toDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
        if (!empty($toDate)) {
            $toDate = trim($toDate);
            $toStr = strlen($toDate) === 10 ? ($toDate . ' 23:59:59') : $toDate;
            $query->where(function ($q) use ($toDate, $toStr) {
                $q->where('date', '<=', $toStr);
            });
        }

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
                $query->where('reference_no', 'like', "%{$search}%");
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

    public function index(Request $request)
    {
        $query = $this->buildFilteredQuery($request, false);

        // Aggregate total spend for filtered result in a single fast DB query
        $baseQuery = (clone $query)->without(['category', 'payments'])->toBase();
        $baseQuery->columns = null;
        $aggregates = $baseQuery->selectRaw("
            COUNT(*) as total_records,
            COALESCE(SUM(amount), 0) as total_amount,
            COALESCE(SUM(COALESCE(paid_amount, amount - COALESCE(due_amount, 0))), 0) as total_paid,
            COALESCE(SUM(COALESCE(due_amount, 0)), 0) as total_due
        ")->first();

        $totalRecords = (int)($aggregates->total_records ?? 0);
        $totalAmount  = (float)($aggregates->total_amount ?? 0);
        $totalPaid    = (float)($aggregates->total_paid ?? 0);
        $totalDue     = (float)($aggregates->total_due ?? 0);

        // ── Cash vs Non-Cash Breakdown (Ultra-fast SQL Subquery without PHP memory overhead) ──
        $expenseIdSubquery = (clone $query)->select('id');
        $cashTotal    = (float)ExpensePayment::whereIn('expense_id', $expenseIdSubquery)
            ->where('payment_type', 'Cash')
            ->sum('amount');
        $digitalTotal = (float)ExpensePayment::whereIn('expense_id', $expenseIdSubquery)
            ->whereIn('payment_type', ['KBZPay', 'WavePay', 'Bank Transfer', 'Credit Card'])
            ->sum('amount');

        // High-performance sorting
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

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            $items = $query->take($limit)->get();
            return response()->json([
                'status' => 'success',
                'data'   => $items,
                'summary' => [
                    'total_amount'   => (float)$totalAmount,
                    'total_paid'     => (float)$totalPaid,
                    'total_due'      => (float)$totalDue,
                    'cash_total'     => (float)$cashTotal,
                    'digital_total'  => (float)$digitalTotal,
                    'total_records'  => $totalRecords,
                ],
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $items->count(),
                    'total'        => $totalRecords,
                    'last_page'    => (int)ceil($totalRecords / max(1, $limit)),
                ],
            ]);
        }

        $perPage  = max(1, min(1000, (int)$request->input('per_page', 25)));
        $expenses = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $expenses->items(),
            'summary' => [
                'total_amount'   => (float)$totalAmount,
                'total_paid'     => (float)$totalPaid,
                'total_due'      => (float)$totalDue,
                'cash_total'     => (float)$cashTotal,
                'digital_total'  => (float)$digitalTotal,
                'total_records'  => $expenses->total(),
            ],
            'pagination' => [
                'current_page' => $expenses->currentPage(),
                'per_page'     => $expenses->perPage(),
                'total'        => $expenses->total(),
                'last_page'    => $expenses->lastPage(),
            ],
        ]);
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
            fputcsv($output, ['Warehouse Filter', $warehouseFilter]);
            fputcsv($output, ['Category Filter', $categoryFilter]);
            fputcsv($output, ['Payment Method Filter', $paymentMethodFilter]);
            fputcsv($output, ['Payment Status Filter', $paymentStatusFilter]);
            fputcsv($output, ['Created By Filter', $userFilter]);
            fputcsv($output, ['Date Range', ($fromDate ?: 'Beginning') . ' to ' . ($toDate ?: 'Present')]);
            if (!empty($search)) {
                fputcsv($output, ['Search Query', $search]);
            }
            fputcsv($output, []); // Blank separator line

            // 3. Table Column Headers (12 Columns)
            fputcsv($output, [
                'SN',
                'Reference No',
                'Warehouse',
                'Expense Category',
                'Expense Title',
                'Amount',
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
            $flushCounter = 0;

            foreach ($query->toBase()->cursor() as $exp) {
                $amount = (float)$exp->amount;
                $totalAmount += $amount;

                $userName = $exp->user_name ?: ($exp->created_by ?: 'System');
                $userRole = $exp->user_role ?: 'Cashier';

                $dateOnly = $exp->date ? explode(' ', trim($exp->date))[0] : '';
                $timeStr = '';
                if ($exp->created_at) {
                    $timeStr = date('H:i:s', strtotime($exp->created_at));
                } elseif ($exp->date && strpos($exp->date, ' ') !== false) {
                    $timeStr = explode(' ', trim($exp->date))[1];
                }

                fputcsv($output, [
                    $sn++,
                    $exp->reference_no,
                    $exp->warehouse_name ?: 'Main Warehouse Branch',
                    $exp->expense_category_name ?: 'General',
                    $exp->title,
                    number_format($amount, 2, '.', ''),
                    $exp->payment_method ?: 'Cash',
                    $userName,
                    $userRole,
                    $dateOnly,
                    $timeStr,
                    $exp->details ?: '',
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
            'reference_no'          => 'nullable|string|max:255',
            'warehouse_id'          => 'nullable|integer',
            'warehouse_name'        => 'required|string|max:255',
            'expense_category_id'   => 'nullable|integer',
            'expense_category_name' => 'required|string|max:255',
            'title'                 => 'required|string|max:255',
            'amount'                => 'required|numeric|min:0.01',
            'paid_amount'           => 'nullable|numeric|min:0',
            'due_amount'            => 'nullable|numeric|min:0',
            'payment_status'        => 'nullable|string',
            'payment_method'        => 'nullable|string|max:255',
            'payment_method_id'     => 'nullable|integer',
            'date'                  => 'required|string',
            'details'               => 'nullable|string',
            'created_by'            => 'nullable|string|max:255',
            'user_name'             => 'nullable|string|max:255',
            'user_role'             => 'nullable|string|max:255',
            'user_id'               => 'nullable|integer',
            'payments'              => 'nullable|array',
            'payments.*.amount'          => 'nullable|numeric|min:0',
            'payments.*.payment_type'    => 'nullable|string|max:255',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.notes'           => 'nullable|string',
            'payments.*.received_amount' => 'nullable|numeric',
            'payments.*.change_amount'   => 'nullable|numeric',
        ]);

        $refNo = $this->acquireUniqueReference('expense', $validated['reference_no'] ?? null);

        $userName = $validated['user_name'] ?? ($validated['created_by'] ?? ($request->user()?->name ?? 'Admin User'));
        $amount   = (float)$validated['amount'];

        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
        }

        // ── Senior Accounting: Resolve Multi-Tender Payments, Due Amount & Payment Status ──
        $paymentsList = $validated['payments'] ?? [];
        $paymentsList = array_values(array_filter($paymentsList, function($p) {
            return !empty($p['amount']) && (float)$p['amount'] > 0;
        }));

        if (!empty($paymentsList)) {
            $totalPaid = 0.0;
            foreach ($paymentsList as $p) {
                $totalPaid += (float)$p['amount'];
            }
            $dueAmount = max(0, $amount - $totalPaid);
            $paymentStatus = $dueAmount <= 0.001 ? 'Paid' : ($totalPaid > 0.001 ? 'Partial' : 'Unpaid');
            $uniqueTypes = collect($paymentsList)->pluck('payment_type')->filter()->unique();
            $effectiveMethod = $uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?? ($validated['payment_method'] ?? 'Cash'));
        } else {
            if (isset($validated['paid_amount'])) {
                $totalPaid = (float)$validated['paid_amount'];
                $dueAmount = max(0, $amount - $totalPaid);
                $paymentStatus = $validated['payment_status'] ?? ($dueAmount <= 0.001 ? 'Paid' : ($totalPaid > 0.001 ? 'Partial' : 'Unpaid'));
            } else {
                $totalPaid = $amount;
                $dueAmount = 0.00;
                $paymentStatus = 'Paid';
            }
            $effectiveMethod = $validated['payment_method'] ?? 'Cash';
        }

        DB::beginTransaction();
        try {
            $expense = Expense::create([
                'reference_no'          => $refNo,
                'warehouse_id'          => $validated['warehouse_id'] ?? null,
                'warehouse_name'        => $validated['warehouse_name'],
                'expense_category_id'   => $validated['expense_category_id'] ?? null,
                'expense_category_name' => $validated['expense_category_name'],
                'title'                 => $validated['title'],
                'amount'                => $amount,
                'paid_amount'           => $totalPaid,
                'due_amount'            => $dueAmount,
                'payment_status'        => $paymentStatus,
                'payment_method'        => $effectiveMethod,
                'payment_method_id'     => $validated['payment_method_id'] ?? null,
                'date'                  => $txDate,
                'details'               => $validated['details'] ?? null,
                'created_by'            => $userName,
                'user_name'             => $userName,
                'user_role'             => $validated['user_role'] ?? ($request->user()?->role ?? 'Super Admin'),
                'user_id'               => $validated['user_id'] ?? $request->user()?->id,
            ]);

            // Create payment transactions if any payment tendered
            if (!empty($paymentsList)) {
                $pIndex = 1;
                foreach ($paymentsList as $p) {
                    $pAmount = (float)$p['amount'];
                    if ($pAmount <= 0) continue;
                    $pType = $p['payment_type'] ?? 'Cash';
                    ExpensePayment::create([
                        'expense_id'      => $expense->id,
                        'reference_no'    => 'PAY-' . $refNo . '-' . $pIndex++,
                        'payment_date'    => $p['payment_date'] ?? $txDate,
                        'payment_type'    => $pType,
                        'amount'          => $pAmount,
                        'received_amount' => (float)($p['received_amount'] ?? $pAmount),
                        'change_amount'   => (float)($p['change_amount'] ?? 0),
                        'notes'           => $p['notes'] ?? ($validated['details'] ?? 'Disbursement tender'),
                        'created_by'      => $userName,
                        'user_id'         => $expense->user_id,
                        'user_name'       => $userName,
                        'user_role'       => $expense->user_role,
                    ]);
                }
            } elseif ($totalPaid > 0.001) {
                ExpensePayment::create([
                    'expense_id'      => $expense->id,
                    'reference_no'    => 'PAY-' . $refNo,
                    'payment_date'    => $txDate,
                    'payment_type'    => $effectiveMethod,
                    'amount'          => $totalPaid,
                    'received_amount' => $totalPaid,
                    'change_amount'   => 0,
                    'notes'           => $validated['details'] ?? 'Initial disbursement',
                    'created_by'      => $userName,
                    'user_id'         => $expense->user_id,
                    'user_name'       => $userName,
                    'user_role'       => $expense->user_role,
                ]);
            }

            DB::commit();
            ReportController::invalidateDashboardCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Expense created successfully',
                'data'    => $expense->fresh()->load(['category', 'payments']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $expense = Expense::with(['category', 'payments'])->find($id) 
            ?? Expense::with(['category', 'payments'])->where('reference_no', $id)->first();
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $expense]);
    }

    public function update(Request $request, $id)
    {
        $expense = Expense::find($id);
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
        }

        $validated = $request->validate([
            'reference_no'          => 'sometimes|nullable|string|max:255',
            'warehouse_id'          => 'sometimes|nullable|integer',
            'warehouse_name'        => 'sometimes|required|string|max:255',
            'expense_category_id'   => 'nullable|integer',
            'expense_category_name' => 'sometimes|required|string|max:255',
            'title'                 => 'sometimes|required|string|max:255',
            'amount'                => 'sometimes|required|numeric|min:0.01',
            'payment_method'        => 'sometimes|nullable|string|max:255',
            'payment_method_id'     => 'sometimes|nullable|integer',
            'date'                  => 'sometimes|required|string',
            'details'               => 'nullable|string',
            'created_by'            => 'sometimes|nullable|string|max:255',
            'user_name'             => 'sometimes|nullable|string|max:255',
            'user_role'             => 'sometimes|nullable|string|max:255',
            'user_id'               => 'sometimes|nullable|integer',
        ]);

        if (isset($validated['user_name']) && empty($validated['created_by'])) {
            $validated['created_by'] = $validated['user_name'];
        } elseif (isset($validated['created_by']) && empty($validated['user_name'])) {
            $validated['user_name'] = $validated['created_by'];
        }

        DB::beginTransaction();
        try {
            $expense->update($validated);

            // Senior Accounting: Dynamically recalculate paid_amount, due_amount & payment_status
            $allPayments = ExpensePayment::where('expense_id', $expense->id)->get();
            $totalPaid = (float)$allPayments->sum('amount');
            $newDue = max(0, (float)$expense->amount - $totalPaid);
            $newStatus = $newDue <= 0.001 ? 'Paid' : ($totalPaid > 0.001 ? 'Partial' : 'Unpaid');

            $uniqueTypes = $allPayments->pluck('payment_type')->filter()->unique();
            $newPaymentMethod = $uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?? $expense->payment_method);

            $expense->update([
                'paid_amount'    => $totalPaid,
                'due_amount'     => $newDue,
                'payment_status' => $newStatus,
                'payment_method' => $newPaymentMethod,
            ]);

            DB::commit();
            ReportController::invalidateDashboardCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Expense updated successfully',
                'data'    => $expense->fresh()->load(['category', 'payments']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $expense = Expense::find($id);
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
        }

        DB::beginTransaction();
        try {
            $expense->delete();

            DB::commit();
            ReportController::invalidateDashboardCache();

            return response()->json(['status' => 'success', 'message' => 'Expense deleted']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function addPayment(Request $request, $id)
    {
        $expense = Expense::with('payments')->find($id) 
            ?? Expense::with('payments')->where('reference_no', $id)->first();
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
        }

        $validated = $request->validate([
            'amount'          => 'nullable|numeric|min:0',
            'payment_type'    => 'required|string',
            'payment_date'    => 'nullable|string',
            'payment_time'    => 'nullable|string',
            'notes'           => 'nullable|string',
            'received_amount' => 'nullable|numeric',
            'change_amount'   => 'nullable|numeric',
            'payments'        => 'nullable|array',
            'user_name'       => 'nullable|string|max:255',
            'user_role'       => 'nullable|string|max:255',
            'user_id'         => 'nullable|integer',
        ]);

        $authUser    = \App\Models\User::resolveFromRequest($request) ?? $request->user();
        $userName    = $request->input('user_name') ?? ($authUser?->name ?? 'Super Admin');
        $userRole    = $request->input('user_role') ?? ($authUser?->role ?? ($authUser?->role_model?->name ?? 'Super Admin'));
        $userId      = $request->input('user_id') ?? $authUser?->id;

        $timezone    = Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
        $nowTz       = Carbon::now()->timezone($timezone);
        $defaultDate = $nowTz->format('Y-m-d');
        $defaultTime = $nowTz->format('h:i A');

        $pDate       = $validated['payment_date'] ?? $defaultDate;
        $pTime       = $request->input('payment_time');
        if (str_contains($pDate, ' ')) {
            $parts = explode(' ', trim($pDate), 2);
            $pDate = $parts[0];
            if (empty($pTime)) {
                try { $pTime = Carbon::parse($parts[1])->timezone($timezone)->format('h:i A'); } catch (\Throwable $e) {}
            }
        }
        if (empty($pTime)) {
            $pTime = $defaultTime;
        } else {
            try { $pTime = Carbon::parse($pTime)->timezone($timezone)->format('h:i A'); } catch (\Throwable $e) {}
        }

        // Calculate total payment tender
        $totalPaying = 0.0;
        if (!empty($validated['payments']) && is_array($validated['payments'])) {
            foreach ($validated['payments'] as $p) {
                $pAmt = (float)($p['amount'] ?? 0);
                if ($pAmt > 0) $totalPaying += $pAmt;
            }
        } else {
            $totalPaying = (float)($validated['amount'] ?? 0);
        }

        if ($totalPaying <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Payment amount must be greater than 0.'], 422);
        }

        DB::beginTransaction();
        try {
            // Concurrency Lock
            $expense = Expense::where('id', $expense->id)->lockForUpdate()->first();
            $currentPaid = (float)$expense->paid_amount;
            $currentDue  = max(0, (float)$expense->amount - $currentPaid);

            // Senior Accounting Validation: Prevent overpayment exceeding remaining due balance
            if ($totalPaying > $currentDue + 0.01) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Payment amount (' . number_format($totalPaying, 2) . ') cannot exceed the remaining due amount (' . number_format($currentDue, 2) . ')',
                ], 422);
            }

            if (!empty($validated['payments']) && is_array($validated['payments'])) {
                foreach ($validated['payments'] as $p) {
                    $pAmount = (float)($p['amount'] ?? 0);
                    if ($pAmount <= 0) continue;
                    $pType = $p['payment_type'] ?? ($p['type'] ?? $validated['payment_type']);
                    $pCount = ExpensePayment::where('expense_id', $expense->id)->count() + 1;
                    $pRef = 'PAY-' . $expense->reference_no . '-' . $pCount;
                    $pUserName = !empty($p['user_name']) ? $p['user_name'] : $userName;
                    $pUserRole = !empty($p['user_role']) ? $p['user_role'] : $userRole;
                    $pUserId   = !empty($p['user_id']) ? $p['user_id'] : $userId;
                    $itemDate  = !empty($p['payment_date']) ? $p['payment_date'] : $pDate;
                    $itemTime  = !empty($p['payment_time']) ? $p['payment_time'] : $pTime;
                    if (str_contains($itemDate, ' ')) {
                        $parts = explode(' ', trim($itemDate), 2);
                        $itemDate = $parts[0];
                        if (empty($p['payment_time'])) {
                            try { $itemTime = Carbon::parse($parts[1])->timezone($timezone)->format('h:i A'); } catch (\Throwable $e) {}
                        }
                    }
                    try { $itemTime = Carbon::parse($itemTime)->timezone($timezone)->format('h:i A'); } catch (\Throwable $e) {}

                    ExpensePayment::create([
                        'expense_id'      => $expense->id,
                        'reference_no'    => $pRef,
                        'payment_date'    => $itemDate,
                        'payment_time'    => $itemTime,
                        'payment_type'    => $pType,
                        'amount'          => $pAmount,
                        'received_amount' => (float)($p['received_amount'] ?? $pAmount),
                        'change_amount'   => (float)($p['change_amount'] ?? 0),
                        'notes'           => $p['notes'] ?? ($validated['notes'] ?? null),
                        'created_by'      => $pUserName,
                        'user_id'         => $pUserId,
                        'user_name'       => $pUserName,
                        'user_role'       => $pUserRole,
                    ]);
                }
            } else {
                $payingAmount = $totalPaying;
                $pCount = ExpensePayment::where('expense_id', $expense->id)->count() + 1;
                $pRef = 'PAY-' . $expense->reference_no . '-' . $pCount;
                ExpensePayment::create([
                    'expense_id'      => $expense->id,
                    'reference_no'    => $pRef,
                    'payment_date'    => $pDate,
                    'payment_time'    => $pTime,
                    'payment_type'    => $validated['payment_type'],
                    'amount'          => $payingAmount,
                    'received_amount' => (float)($validated['received_amount'] ?? $payingAmount),
                    'change_amount'   => (float)($validated['change_amount'] ?? 0),
                    'notes'           => $validated['notes'] ?? null,
                    'created_by'      => $userName,
                    'user_id'         => $userId,
                    'user_name'       => $userName,
                    'user_role'       => $userRole,
                ]);
            }

            // Recalculate based on active payments
            $allPayments = ExpensePayment::where('expense_id', $expense->id)->get();
            $newPaid = (float)$allPayments->sum('amount');
            $newDue  = max(0, (float)$expense->amount - $newPaid);
            if ($newPaid <= 0.001) {
                $newStatus = 'Unpaid';
            } elseif ($newDue <= 0.001) {
                $newStatus = 'Paid';
            } else {
                $newStatus = 'Partial';
            }

            $uniqueTypes = $allPayments->pluck('payment_type')->filter()->unique();
            $newPaymentMethod = $uniqueTypes->count() > 1 ? 'Split Payment' : ($uniqueTypes->first() ?? $expense->payment_method);

            $expense->update([
                'paid_amount'    => $newPaid,
                'due_amount'     => $newDue,
                'payment_status' => $newStatus,
                'payment_method' => $newPaymentMethod,
            ]);

            DB::commit();
            ReportController::invalidateDashboardCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully',
                'data'    => $expense->fresh()->load(['category', 'payments']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function deletePayment(Request $request, $expenseId, $paymentId = null)
    {
        if ($paymentId === null) {
            $payment = ExpensePayment::find($expenseId);
            if (!$payment) {
                return response()->json(['status' => 'error', 'message' => 'Payment record not found'], 404);
            }
            $expense = Expense::find($payment->expense_id);
        } else {
            $expense = Expense::find($expenseId) ?? Expense::where('reference_no', $expenseId)->first();
            if (!$expense) {
                return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
            }
            $payment = ExpensePayment::where('expense_id', $expense->id)
                ->where(function ($q) use ($paymentId) {
                    $q->where('id', $paymentId)->orWhere('reference_no', $paymentId);
                })->first() ?? ExpensePayment::find($paymentId);
        }

        if (!$payment) {
            // Check if there are ANY payments recorded for this expense
            $existingCount = ExpensePayment::where('expense_id', $expense?->id ?? 0)->count();
            if ($existingCount === 0 && $expense) {
                // The expense had an implicit payment that is being cleared
                $expense->update([
                    'paid_amount'    => 0,
                    'due_amount'     => $expense->amount,
                    'payment_status' => 'Unpaid',
                ]);
                return response()->json([
                    'status'  => 'success',
                    'message' => 'Payment removed and expense set to Unpaid',
                    'data'    => $expense->fresh()->load(['category', 'payments']),
                ]);
            }
            return response()->json(['status' => 'error', 'message' => 'Payment transaction not found for this expense'], 404);
        }

        if (!$expense) {
            $expense = Expense::find($payment->expense_id);
        }

        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Associated expense not found'], 404);
        }

        DB::beginTransaction();
        try {
            $expense = Expense::where('id', $expense->id)->lockForUpdate()->first();
            $payment->delete();

            // Recalculate based on remaining active payments
            $remainingPayments = ExpensePayment::where('expense_id', $expense->id)->get();
            $newPaid = (float)$remainingPayments->sum('amount');
            $newDue  = max(0, (float)$expense->amount - $newPaid);

            if ($newPaid <= 0.001) {
                $newStatus = 'Unpaid';
            } elseif ($newDue <= 0.001) {
                $newStatus = 'Paid';
            } else {
                $newStatus = 'Partial';
            }

            $uniqueTypes = $remainingPayments->pluck('payment_type')->filter()->unique();
            if ($uniqueTypes->count() > 1) {
                $newPaymentType = 'Split Payment';
            } elseif ($uniqueTypes->count() === 1) {
                $newPaymentType = $uniqueTypes->first();
            } else {
                $newPaymentType = $expense->payment_method;
            }

            $expense->update([
                'paid_amount'    => $newPaid,
                'due_amount'     => $newDue,
                'payment_status' => $newStatus,
                'payment_method' => $newPaymentType,
            ]);

            DB::commit();
            ReportController::invalidateDashboardCache();

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully',
                'data'    => $expense->fresh()->load(['category', 'payments']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
