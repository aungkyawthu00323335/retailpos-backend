<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductCategoryController extends Controller
{
    private const CACHE_KEY = 'api_product_categories_list';
    private const CACHE_TTL_SECONDS = 300;

    public function index(Request $request)
    {
        $noStoreHeader = 'no-store, no-cache, must-revalidate, private';

        if ($request->boolean('compact')) {
            $compactQuery = DB::table('product_categories')
                ->select(['id', 'name', 'status']);

            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));
                $compactQuery->where('name', 'like', "%{$search}%");
            }
            if ($request->filled('status') && $request->input('status') !== 'All') {
                $compactQuery->where('status', $request->input('status'));
            }

            return response()->json([
                'status' => 'success',
                'data' => $compactQuery->orderByDesc('id')->get(),
            ])->header('Cache-Control', $noStoreHeader);
        }

        $hasSearch = $request->filled('search');
        $hasStatus = $request->filled('status') && $request->input('status') !== 'All';

        if (! $hasSearch && ! $hasStatus) {
            $categories = $this->formatListingRows(
                $this->buildListingQuery()->get()
            );

            return response()->json([
                'status' => 'success',
                'data' => $categories,
            ])->header('Cache-Control', $noStoreHeader);
        }

        $query = $this->buildListingQuery();

        if ($hasSearch) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('pc.name', 'like', "%{$search}%")
                    ->orWhere('pc.description', 'like', "%{$search}%");
            });
        }

        if ($hasStatus) {
            $query->where('pc.status', $request->input('status'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatListingRows($query->get()),
        ])->header('Cache-Control', $noStoreHeader);
    }

    private function buildListingQuery()
    {
        $productCountsSub = DB::table('products')
            ->selectRaw('category_id, COUNT(*) as products_count')
            ->whereNull('deleted_at')
            ->whereNotNull('category_id')
            ->groupBy('category_id');

        return DB::table('product_categories as pc')
            ->leftJoinSub($productCountsSub, 'pc_counts', function ($join) {
                $join->on('pc.id', '=', 'pc_counts.category_id');
            })
            ->select([
                'pc.id',
                'pc.name',
                'pc.description',
                'pc.status',
                'pc.created_at',
                'pc.updated_at',
                DB::raw('COALESCE(pc_counts.products_count, 0) as products_count'),
            ])
            ->orderByDesc('pc.id');
    }

    private function formatListingRows($rows): array
    {
        return collect($rows)->map(static function ($row) {
            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'description' => $row->description,
                'status' => $row->status,
                'products_count' => (int) ($row->products_count ?? 0),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->all();
    }

    private function clearListCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('api_sub_categories_list');
        Cache::forget('tg_bot_active_categories_v2');
        Cache::forget('tg_bot_active_categories');
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

        $this->clearListCache();

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

        $this->clearListCache();

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

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Product category deleted successfully',
        ]);
    }
}
