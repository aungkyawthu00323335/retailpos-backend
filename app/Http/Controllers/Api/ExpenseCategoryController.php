<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseCategoryController extends Controller
{
    private const SYSTEM_CATEGORIES = ['transfer shipping'];

    public function index(Request $request)
    {
        $noStoreHeader = 'no-store, no-cache, must-revalidate, private';
        $query = ExpenseCategory::query();
        if ($request->boolean('compact')) {
            $query->select(['id', 'name', 'status']);
        }
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }
        $categories = $query->orderBy('id', 'desc')->get();
        return response()->json(['status' => 'success', 'data' => $categories])
            ->header('Cache-Control', $noStoreHeader);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:expense_categories,name',
            'code' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $name = trim($validated['name']);
        $category = ExpenseCategory::create([
            'name' => $name,
            'code' => $validated['code'] ?? strtoupper(substr($name, 0, 3)),
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

        $oldName = (string)$category->name;
        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
            if (in_array(strtolower(trim($oldName)), self::SYSTEM_CATEGORIES, true)
                && strcasecmp($validated['name'], $oldName) !== 0
            ) {
                return response()->json([
                    'status' => 'error',
                    'message' => "System category '{$oldName}' cannot be renamed.",
                ], 422);
            }
        }

        DB::transaction(function () use ($category, $validated, $oldName) {
            $category->update($validated);
            if (isset($validated['name']) && $validated['name'] !== $oldName) {
                Expense::where('expense_category_id', $category->id)
                    ->update(['expense_category_name' => $validated['name']]);
            }
        });

        return response()->json(['status' => 'success', 'message' => 'Expense category updated', 'data' => $category->fresh()]);
    }

    public function destroy($id)
    {
        $category = ExpenseCategory::find($id);
        if (!$category) {
            return response()->json(['status' => 'error', 'message' => 'Category not found'], 404);
        }

        if (in_array(strtolower(trim((string)$category->name)), self::SYSTEM_CATEGORIES, true)) {
            return response()->json([
                'status' => 'error',
                'message' => "System category '{$category->name}' cannot be deleted.",
            ], 422);
        }

        $inUse = Expense::where('expense_category_id', $category->id)
            ->orWhereRaw('LOWER(TRIM(expense_category_name)) = ?', [strtolower(trim((string)$category->name))])
            ->exists();
        if ($inUse) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete category '{$category->name}' because it is referenced by existing expenses. Mark it Inactive instead.",
            ], 422);
        }

        $category->delete();
        return response()->json(['status' => 'success', 'message' => 'Expense category deleted']);
    }
}
