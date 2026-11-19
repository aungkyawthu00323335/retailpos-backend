<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubCategory;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubCategoryController extends Controller
{
    /**
     * Shared query builder joining aggregated product counts in one pass
     * instead of correlated Eloquent withCount subqueries.
     */
    private function buildListingQuery()
    {
        $productCountsSub = DB::table('products')
            ->select('sub_category_id', DB::raw('COUNT(*) as cnt'))
            ->whereNull('deleted_at')
            ->whereNotNull('sub_category_id')
            ->groupBy('sub_category_id');

        return DB::table('sub_categories as sc')
            ->leftJoinSub($productCountsSub, 'pc', function ($join) {
                $join->on('pc.sub_category_id', '=', 'sc.id');
            })
            ->select([
                'sc.id',
                'sc.parent_category_id',
                'sc.parent_category_name',
                'sc.name',
                'sc.description',
                'sc.status',
                'sc.created_at',
                DB::raw('COALESCE(pc.cnt, 0) as products_count'),
            ]);
    }

    /**
     * Normalize integer types cleanly for JSON serialization.
     */
    private function formatListingRows($rows): array
    {
        return $rows->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'parent_category_id' => $row->parent_category_id !== null ? (int) $row->parent_category_id : null,
                'parent_category_name' => $row->parent_category_name ?? 'General',
                'name' => $row->name,
                'description' => $row->description ?? '',
                'status' => $row->status ?? 'Active',
                'products_count' => (int) $row->products_count,
                'created_at' => $row->created_at,
            ];
        })->all();
    }

    /**
     * Display a listing of sub categories.
     */
    public function index(Request $request)
    {
        $hasFilters = $request->filled('search') ||
            ($request->filled('parent_category') && $request->input('parent_category') !== 'All') ||
            ($request->filled('status') && $request->input('status') !== 'All') ||
            $request->filled('sort_by');

        if (!$hasFilters) {
            $items = $this->formatListingRows(
                $this->buildListingQuery()
                    ->orderByDesc('sc.id')
                    ->get()
            );

            return response()->json([
                'status' => 'success',
                'data' => $items,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        $query = $this->buildListingQuery();

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('sc.name', 'like', "%{$search}%")
                  ->orWhere('sc.parent_category_name', 'like', "%{$search}%")
                  ->orWhere('sc.description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('parent_category') && $request->input('parent_category') !== 'All') {
            $query->where('sc.parent_category_name', $request->input('parent_category'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('sc.status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (in_array($sortBy, ['id', 'name', 'parent_category_name', 'status', 'created_at'])) {
            $query->orderBy("sc.{$sortBy}", $sortOrder);
        } else {
            $query->orderByDesc('sc.id');
        }

        $items = $this->formatListingRows($query->get());

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

        Cache::forget('api_sub_categories_list');

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

        Cache::forget('api_sub_categories_list');

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

        Cache::forget('api_sub_categories_list');

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

        Cache::forget('api_sub_categories_list');

        return response()->json([
            'status' => 'success',
            'message' => 'Sub category deleted successfully',
        ]);
    }
}
