<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierCategory;
use Illuminate\Http\Request;

class SupplierCategoryController extends Controller
{
    /**
     * Display a listing of supplier categories with suppliers count.
     */
    public function index(Request $request)
    {
        $query = SupplierCategory::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $categories = $query->orderBy('id', 'asc')->get();

        // Calculate mapped supplier counts per category in 1 single fast query
        $supplierCounts = Supplier::selectRaw('category, count(*) as count')
            ->whereNotNull('category')
            ->groupBy('category')
            ->pluck('count', 'category');

        $mapped = $categories->map(function ($cat) use ($supplierCounts) {
            $cat->suppliers_count = (int)($supplierCounts[$cat->name] ?? 0);
            return $cat;
        });

        $summaryData = SupplierCategory::selectRaw("
            COUNT(*) as total_categories,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_categories
        ")->first();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_categories' => (int)($summaryData->total_categories ?? 0),
                'active_categories' => (int)($summaryData->active_categories ?? 0),
                'total_suppliers' => Supplier::count(),
            ],
            'data' => $mapped,
        ]);
    }

    /**
     * Store a newly created supplier category.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $category = SupplierCategory::create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#2563EB',
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        $category->suppliers_count = 0;

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier category created successfully',
            'data' => $category,
        ], 201);
    }

    /**
     * Display the specified supplier category.
     */
    public function show($id)
    {
        $category = SupplierCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier category not found',
            ], 404);
        }

        $category->suppliers_count = Supplier::where('category', $category->name)->count();

        return response()->json([
            'status' => 'success',
            'data' => $category,
        ]);
    }

    /**
     * Update the specified supplier category.
     */
    public function update(Request $request, $id)
    {
        $category = SupplierCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier category not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $oldName = $category->name;
        $category->update($validated);

        // If category name was renamed, update linked suppliers
        if (isset($validated['name']) && $validated['name'] !== $oldName) {
            Supplier::where('category', $oldName)->update(['category' => $validated['name']]);
        }

        $category->suppliers_count = Supplier::where('category', $category->name)->count();

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier category updated successfully',
            'data' => $category,
        ]);
    }

    /**
     * Remove the specified supplier category.
     */
    public function destroy($id)
    {
        $category = SupplierCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier category not found',
            ], 404);
        }

        $category->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier category deleted successfully',
        ]);
    }
}
