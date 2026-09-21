<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class SubCategoryController extends Controller
{
    /**
     * Display a listing of sub categories.
     */
    public function index(Request $request)
    {
        $query = SubCategory::withCount('products');

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('parent_category_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('parent_category') && $request->input('parent_category') !== 'All') {
            $query->where('parent_category_name', $request->input('parent_category'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (in_array($sortBy, ['id', 'name', 'parent_category_name', 'status', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $items = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $items,
        ]);
    }

    /**
     * Toggle the status of the specified sub category.
     */
    public function toggleStatus($id)
    {
        $subCategory = SubCategory::find($id);
        if (!$subCategory) {
            return response()->json(['status' => 'error', 'message' => 'Sub category not found'], 404);
        }

        $subCategory->status = ($subCategory->status === 'Active') ? 'Inactive' : 'Active';
        $subCategory->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Sub category status toggled successfully',
            'data' => $subCategory,
        ]);
    }

    /**
     * Store a newly created sub category.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_category_name' => 'required|string|max:255',
            'parent_category_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $subCategory = SubCategory::create([
            'name' => trim($validated['name']),
            'parent_category_name' => trim($validated['parent_category_name']),
            'parent_category_id' => $validated['parent_category_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Sub category created successfully',
            'data' => $subCategory,
        ], 201);
    }

    /**
     * Display the specified sub category.
     */
    public function show($id)
    {
        $subCategory = SubCategory::withCount('products')->find($id);

        if (!$subCategory) {
            return response()->json(['status' => 'error', 'message' => 'Sub category not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $subCategory,
        ]);
    }

    /**
     * Update the specified sub category.
     */
    public function update(Request $request, $id)
    {
        $subCategory = SubCategory::find($id);

        if (!$subCategory) {
            return response()->json(['status' => 'error', 'message' => 'Sub category not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'parent_category_name' => 'sometimes|required|string|max:255',
            'parent_category_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $subCategory->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Sub category updated successfully',
            'data' => $subCategory,
        ]);
    }

    /**
     * Remove the specified sub category.
     */
    public function destroy($id)
    {
        $subCategory = SubCategory::withCount('products')->find($id);

        if (!$subCategory) {
            return response()->json(['status' => 'error', 'message' => 'Sub category not found'], 404);
        }

        if ($subCategory->products_count > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete sub category '{$subCategory->name}' because it contains {$subCategory->products_count} active product(s). Please reassign or delete these products first to preserve accounting and inventory integrity.",
            ], 422);
        }

        $subCategory->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Sub category deleted successfully',
        ]);
    }
}
