<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $query = Expense::with('category');

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
        $totalAmount = (clone $query)->sum('amount');

        // ── Cash vs Non-Cash Breakdown ────────────────────────────────────────
        $cashTotal    = (clone $query)->where('payment_method', 'Cash')->sum('amount');
        $digitalTotal = (clone $query)->whereIn('payment_method', ['KBZPay', 'WavePay', 'Bank Transfer'])->sum('amount');

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
            $items = $query->get();
            return response()->json([
                'status' => 'success',
                'data'   => $items,
                'summary' => [
                    'total_amount'   => (float)$totalAmount,
                    'cash_total'     => (float)$cashTotal,
                    'digital_total'  => (float)$digitalTotal,
                    'total_records'  => $items->count(),
                ],
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $items->count(),
                    'total'        => $items->count(),
                    'last_page'    => 1,
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
            'payment_method'        => 'nullable|string|max:255',
            'payment_method_id'     => 'nullable|integer',
            'date'                  => 'required|string',
            'details'               => 'nullable|string',
            'created_by'            => 'nullable|string|max:255',
            'user_name'             => 'nullable|string|max:255',
            'user_role'             => 'nullable|string|max:255',
            'user_id'               => 'nullable|integer',
        ]);

        $refNo = !empty($validated['reference_no']) ? trim($validated['reference_no']) : null;
        if (empty($refNo)) {
            $prefix = Setting::get('expense_prefix', 'EXP_');
            $refNo  = $prefix . date('Ymd') . '-' . rand(1000, 9999);
        }

        $userName = $validated['user_name'] ?? ($validated['created_by'] ?? ($request->user()?->name ?? 'Admin User'));
        $amount   = (float)$validated['amount'];
        $paymentMethod = strtolower(trim($validated['payment_method'] ?? 'Cash'));

        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
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
                'payment_method'        => $validated['payment_method'] ?? 'Cash',
                'payment_method_id'     => $validated['payment_method_id'] ?? null,
                'date'                  => $txDate,
                'details'               => $validated['details'] ?? null,
                'created_by'            => $userName,
                'user_name'             => $userName,
                'user_role'             => $validated['user_role'] ?? ($request->user()?->role ?? 'Super Admin'),
                'user_id'               => $validated['user_id'] ?? $request->user()?->id,
            ]);

            // Store operating expense created independently of POS till cash drawer
            // (Cash Register tracks POS till shift sales/drawers, whereas general expenses
            // are maintained in the general ledger/petty cash).

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Expense created successfully',
                'data'    => $expense,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $expense = Expense::with('category')->find($id);
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

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Expense updated successfully',
                'data'    => $expense,
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
}
