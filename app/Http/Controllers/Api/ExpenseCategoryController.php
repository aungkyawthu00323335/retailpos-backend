<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ExpenseCategory::query();
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }
        $categories = $query->orderBy('id', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $categories]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:expense_categories,name',
            'code' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $category = ExpenseCategory::create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?? strtoupper(substr($validated['name'], 0, 3)),
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        return response()->json(['status' => 'success', 'message' => 'Expense category created', 'data' => $category], 201);
    }

    public function update(Request $request, $id)
    {
        $category = ExpenseCategory::find($id);
        if (!$category) {
            return response()->json(['status' => 'error', 'message' => 'Category not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:expense_categories,name,' . $id,
            'code' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $category->update($validated);
        return response()->json(['status' => 'success', 'message' => 'Expense category updated', 'data' => $category]);
    }

    public function destroy($id)
    {
        $category = ExpenseCategory::find($id);
        if (!$category) {
            return response()->json(['status' => 'error', 'message' => 'Category not found'], 404);
        }
        $category->delete();
        return response()->json(['status' => 'success', 'message' => 'Expense category deleted']);
    }
}
