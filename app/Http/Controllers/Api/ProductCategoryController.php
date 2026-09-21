<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index()
    {
        $categories = ProductCategory::withCount('products')->orderBy('id', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $category = ProductCategory::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Product category created successfully',
            'data' => $category,
        ], 201);
    }

    public function show($id)
    {
        $category = ProductCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product category not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $category,
        ]);
    }

    public function update(Request $request, $id)
    {
        $category = ProductCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product category not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $category->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Product category updated successfully',
            'data' => $category,
        ]);
    }

    public function destroy($id)
    {
        $category = ProductCategory::withCount('products')->find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product category not found',
            ], 404);
        }

        if ($category->products_count > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete category '{$category->name}' because it contains {$category->products_count} active product(s). Please reassign or delete these products first.",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Product category deleted successfully',
        ]);
    }
}
