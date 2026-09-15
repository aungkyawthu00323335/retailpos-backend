<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Setting;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /**
     * Generate next numeric expense code (numbers only).
     */
    public function generateCode()
    {
        $expenses = Expense::select('reference_no')->orderBy('id', 'desc')->take(200)->get();
        $maxNum = 100000;
        foreach ($expenses as $exp) {
            $digits = preg_replace('/\D/', '', (string)$exp->reference_no);
            if (!empty($digits)) {
                $val = (int)$digits;
                if ($val >= 100000 && $val < 999999999 && $val > $maxNum) {
                    $maxNum = $val;
                }
            }
        }
        $nextCode = (string)($maxNum + 1);

        return response()->json([
            'status' => 'success',
            'data' => [
                'code' => $nextCode,
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

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('created_by') && $request->input('created_by') !== 'All') {
            $query->where('created_by', $request->input('created_by'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('expense_category_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%")
                  ->orWhere('details', 'like', "%{$search}%");
            });
        }

        // Aggregate total spend for filtered result
        $totalAmount = (clone $query)->sum('amount');

        // High-performance sorting
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'date', 'amount', 'title', 'reference_no', 'warehouse_name', 'expense_category_name', 'created_by', 'user_name'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $perPage = max(1, min(100, (int)$request->input('per_page', 25)));
        $expenses = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $expenses->items(),
            'summary' => [
                'total_amount' => (float)$totalAmount,
                'total_records' => $expenses->total(),
            ],
            'pagination' => [
                'current_page' => $expenses->currentPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
                'last_page' => $expenses->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:255',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'expense_category_id' => 'nullable|integer',
            'expense_category_name' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|string',
            'details' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'user_name' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer',
        ]);

        $refNo = !empty($validated['reference_no']) ? trim($validated['reference_no']) : null;
        if (empty($refNo)) {
            $prefix = Setting::get('expense_prefix', 'EXP_');
            $refNo = $prefix . date('Ymd') . '-' . rand(1000, 9999);
        }

        $userName = $validated['user_name'] ?? ($validated['created_by'] ?? ($request->user()?->name ?? 'Admin User'));

        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
        }

        $expense = Expense::create([
            'reference_no' => $refNo,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'warehouse_name' => $validated['warehouse_name'],
            'expense_category_id' => $validated['expense_category_id'] ?? null,
            'expense_category_name' => $validated['expense_category_name'],
            'title' => $validated['title'],
            'amount' => (float)$validated['amount'],
            'date' => $txDate,
            'details' => $validated['details'] ?? null,
            'created_by' => $userName,
            'user_name' => $userName,
            'user_role' => $validated['user_role'] ?? ($request->user()?->role ?? 'Super Admin'),
            'user_id' => $validated['user_id'] ?? $request->user()?->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Expense created successfully',
            'data' => $expense,
        ], 201);
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
            'reference_no' => 'sometimes|nullable|string|max:255',
            'warehouse_id' => 'sometimes|nullable|integer',
            'warehouse_name' => 'sometimes|required|string|max:255',
            'expense_category_id' => 'nullable|integer',
            'expense_category_name' => 'sometimes|required|string|max:255',
            'title' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'date' => 'sometimes|required|string',
            'details' => 'nullable|string',
            'created_by' => 'sometimes|nullable|string|max:255',
            'user_name' => 'sometimes|nullable|string|max:255',
            'user_role' => 'sometimes|nullable|string|max:255',
            'user_id' => 'sometimes|nullable|integer',
        ]);

        if (isset($validated['user_name']) && empty($validated['created_by'])) {
            $validated['created_by'] = $validated['user_name'];
        } elseif (isset($validated['created_by']) && empty($validated['user_name'])) {
            $validated['user_name'] = $validated['created_by'];
        }

        $expense->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Expense updated successfully',
            'data' => $expense,
        ]);
    }

    public function destroy($id)
    {
        $expense = Expense::find($id);
        if (!$expense) {
            return response()->json(['status' => 'error', 'message' => 'Expense not found'], 404);
        }
        $expense->delete();
        return response()->json(['status' => 'success', 'message' => 'Expense deleted']);
    }
}
