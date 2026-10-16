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
    /**
     * Generate next expense reference code (e.g. EXP_1234567).
     */
    public function generateCode()
    {
        $prefix = Setting::get('expense_prefix', 'EXP_');
        $expenses = Expense::select('reference_no')->orderBy('id', 'desc')->take(200)->get();
        $existingCodes = $expenses->pluck('reference_no')->filter()->toArray();

        do {
            $num = rand(1000000, 9999999);
            $candidate = $prefix . $num;
        } while (in_array($candidate, $existingCodes) || Expense::where('reference_no', $candidate)->exists());

        return response()->json([
            'status' => 'success',
            'data'   => [
                'code'         => $candidate,
                'reference_no' => $candidate,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = Expense::with(['category', 'payments']);

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('category_id') && $request->input('category_id') !== 'All') {
            $query->where('expense_category_id', $request->input('category_id'));
        }

        if ($request->filled('category_name') && $request->input('category_name') !== 'All') {
            $query->where('expense_category_name', $request->input('category_name'));
        }

        if ($request->filled('payment_method') && $request->input('payment_method') !== 'All') {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('created_by') && $request->input('created_by') !== 'All') {
            $filterUser = $request->input('created_by');
            $query->where(function ($q) use ($filterUser) {
                $q->where('created_by', $filterUser)
                  ->orWhere('user_name', $filterUser);
            });
        }

        if ($request->filled('user_role') && $request->input('user_role') !== 'All') {
            $query->where('user_role', $request->input('user_role'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
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

        // Aggregate total spend for filtered result
        $baseQuery = (clone $query)->without(['category', 'payments']);
        $totalAmount = (float)(clone $baseQuery)->sum('amount');
        $totalRecords = (int)(clone $baseQuery)->count();

        // ── Cash vs Non-Cash Breakdown (Ultra-fast SQL Subquery without PHP memory overhead) ──
        $expenseIdSubquery = (clone $baseQuery)->select('id');
        $cashTotal    = (float)ExpensePayment::whereIn('expense_id', $expenseIdSubquery)
            ->where('payment_type', 'Cash')
            ->sum('amount');
        $digitalTotal = (float)ExpensePayment::whereIn('expense_id', $expenseIdSubquery)
            ->whereIn('payment_type', ['KBZPay', 'WavePay', 'Bank Transfer', 'Credit Card'])
            ->sum('amount');

        // High-performance sorting
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'date', 'amount', 'title', 'reference_no', 'warehouse_name', 'expense_category_name', 'payment_method', 'created_by', 'user_name'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            $items = $query->take($limit)->get();
            return response()->json([
                'status' => 'success',
                'data'   => $items,
                'summary' => [
                    'total_amount'   => (float)$totalAmount,
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

        $refNo = !empty($validated['reference_no']) ? trim($validated['reference_no']) : null;
        if (empty($refNo)) {
            $prefix = Setting::get('expense_prefix', 'EXP_');
            $refNo  = $prefix . date('Ymd') . '-' . rand(1000, 9999);
        }

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
