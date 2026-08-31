<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Setting;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::with('category');

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

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('expense_category_name', 'like', "%{$search}%")
                  ->orWhere('details', 'like', "%{$search}%");
            });
        }

        // Aggregate total spend for filtered result
        $totalAmount = (clone $query)->sum('amount');

        // High-performance sorting
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'date', 'amount', 'title', 'reference_no', 'warehouse_name', 'expense_category_name'];
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
            'warehouse_name' => 'required|string|max:255',
            'expense_category_id' => 'nullable|integer',
            'expense_category_name' => 'required|string|max:255',
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|string',
            'details' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
        ]);

        $prefix = Setting::get('expense_prefix', 'EXP_');
        $refNo = $prefix . date('Ymd') . '-' . rand(1000, 9999);

        $expense = Expense::create([
            'reference_no' => $refNo,
            'warehouse_name' => $validated['warehouse_name'],
            'expense_category_id' => $validated['expense_category_id'] ?? null,
            'expense_category_name' => $validated['expense_category_name'],
            'title' => $validated['title'],
            'amount' => (float)$validated['amount'],
            'date' => $validated['date'],
            'details' => $validated['details'] ?? null,
            'created_by' => $validated['created_by'] ?? 'Admin User',
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
            'warehouse_name' => 'sometimes|required|string|max:255',
            'expense_category_id' => 'nullable|integer',
            'expense_category_name' => 'sometimes|required|string|max:255',
            'title' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'date' => 'sometimes|required|string',
            'details' => 'nullable|string',
        ]);

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
