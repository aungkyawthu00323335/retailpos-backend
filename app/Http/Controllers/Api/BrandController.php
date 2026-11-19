<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BrandController extends Controller
{
    private const CACHE_KEY = 'api_brands_list';

    /**
     * Shared query builder joining aggregated product counts in one pass
     * instead of correlated Eloquent withCount subqueries.
     */
    private function buildListingQuery()
    {
        $productCountsSub = DB::table('products')
            ->select('brand_id', DB::raw('COUNT(*) as cnt'))
            ->whereNull('deleted_at')
            ->whereNotNull('brand_id')
            ->groupBy('brand_id');

        return DB::table('brands as b')
            ->leftJoinSub($productCountsSub, 'pc', function ($join) {
                $join->on('pc.brand_id', '=', 'b.id');
            })
            ->select([
                'b.id',
                'b.name',
                'b.code',
                'b.image_url',
                'b.description',
                'b.status',
                'b.created_at',
                'b.updated_at',
                DB::raw('COALESCE(pc.cnt, 0) as products_count'),
            ]);
    }

    /**
     * Normalize integer and nullable string fields for API consumers.
     */
    private function formatListingRows($rows): array
    {
        return $rows->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'code' => $row->code ?? '',
                'image_url' => $row->image_url,
                'description' => $row->description ?? '',
                'status' => $row->status ?? 'Active',
                'products_count' => (int) $row->products_count,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->all();
    }

    private function clearListCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('tg_bot_active_brands_v2');
        Cache::forget('tg_bot_active_brands');
    }

    public function index(Request $request)
    {
        $noStoreHeader = 'no-store, no-cache, must-revalidate, private';

        if ($request->boolean('compact')) {
            $compactQuery = DB::table('brands')
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

        $hasFilters = $request->filled('search') ||
            ($request->filled('status') && $request->input('status') !== 'All');

        if (!$hasFilters) {
            $brands = $this->formatListingRows(
                $this->buildListingQuery()
                    ->orderBy('b.id', 'desc')
                    ->get()
            );

            return response()->json([
                'status' => 'success',
                'data' => $brands,
            ])->header('Cache-Control', $noStoreHeader);
        }

        $query = $this->buildListingQuery();

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('b.name', 'like', "%{$search}%")
                  ->orWhere('b.code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('b.status', $request->input('status'));
        }

        $brands = $this->formatListingRows($query->orderBy('b.id', 'desc')->get());

        return response()->json([
            'status' => 'success',
            'data' => $brands,
        ])->header('Cache-Control', $noStoreHeader);
    }

    public function toggleStatus($id)
    {
        $brand = Brand::find($id);
        if (!$brand) {
            return response()->json(['status' => 'error', 'message' => 'Brand not found'], 404);
        }

        $brand->status = ($brand->status === 'Active') ? 'Inactive' : 'Active';
        $brand->save();
        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Brand status toggled successfully',
            'data' => $brand,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
            'code' => 'nullable|string|max:100',
            'image_url' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $brand = Brand::create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?? strtoupper(substr($validated['name'], 0, 3)),
            'image_url' => $validated['image_url'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);
        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Brand created successfully',
            'data' => $brand,
        ], 201);
    }

    public function show($id)
    {
        $brand = Brand::withCount('products')->find($id);
        if (!$brand) {
            return response()->json(['status' => 'error', 'message' => 'Brand not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $brand]);
    }

    public function update(Request $request, $id)
    {
        $brand = Brand::find($id);
        if (!$brand) {
            return response()->json(['status' => 'error', 'message' => 'Brand not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:brands,name,' . $id,
            'code' => 'nullable|string|max:100',
            'image_url' => 'nullable|string',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $brand->update($validated);
        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Brand updated successfully',
            'data' => $brand,
        ]);
    }

    public function destroy($id)
    {
        $brand = Brand::withCount('products')->find($id);
        if (!$brand) {
            return response()->json(['status' => 'error', 'message' => 'Brand not found'], 404);
        }

        if ($brand->products_count > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete brand '{$brand->name}' because it contains {$brand->products_count} active product(s). Please reassign or delete these products first to preserve accounting and inventory integrity.",
            ], 422);
        }

        $brand->delete();
        $this->clearListCache();
        return response()->json(['status' => 'success', 'message' => 'Brand deleted successfully']);
    }
}
