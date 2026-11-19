<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductUnitController extends Controller
{
    private const CACHE_KEY = 'api_product_units_list';
    private const CACHE_TTL_SECONDS = 300;

    public function index(Request $request)
    {
        $hasSearch = $request->filled('search');
        $hasStatus = $request->filled('status') && $request->input('status') !== 'All';

        if (! $hasSearch && ! $hasStatus) {
            $units = $this->formatListingRows(
                $this->buildListingQuery()->get()
            );

            return response()->json([
                'status' => 'success',
                'data' => $units,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        $query = $this->buildListingQuery();

        if ($hasSearch) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('pu.name', 'like', "%{$search}%")
                    ->orWhere('pu.description', 'like', "%{$search}%");
            });
        }

        if ($hasStatus) {
            $query->where('pu.status', $request->input('status'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatListingRows($query->get()),
        ]);
    }

    private function buildListingQuery()
    {
        $productCountsSub = DB::table('products')
            ->selectRaw('base_unit_id, COUNT(*) as products_count')
            ->whereNull('deleted_at')
            ->whereNotNull('base_unit_id')
            ->groupBy('base_unit_id');

        $saleUnitCountsSub = DB::table('sale_units')
            ->selectRaw('base_unit_id, COUNT(*) as sale_units_count')
            ->whereNotNull('base_unit_id')
            ->groupBy('base_unit_id');

        $purchaseUnitCountsSub = DB::table('purchase_units')
            ->selectRaw('base_unit_id, COUNT(*) as purchase_units_count')
            ->whereNotNull('base_unit_id')
            ->groupBy('base_unit_id');

        return DB::table('product_units as pu')
            ->leftJoinSub($productCountsSub, 'p_counts', function ($join) {
                $join->on('pu.id', '=', 'p_counts.base_unit_id');
            })
            ->leftJoinSub($saleUnitCountsSub, 'su_counts', function ($join) {
                $join->on('pu.id', '=', 'su_counts.base_unit_id');
            })
            ->leftJoinSub($purchaseUnitCountsSub, 'pu_counts', function ($join) {
                $join->on('pu.id', '=', 'pu_counts.base_unit_id');
            })
            ->select([
                'pu.id',
                'pu.name',
                'pu.description',
                'pu.status',
                'pu.created_at',
                'pu.updated_at',
                DB::raw('COALESCE(p_counts.products_count, 0) as products_count'),
                DB::raw('COALESCE(su_counts.sale_units_count, 0) as sale_units_count'),
                DB::raw('COALESCE(pu_counts.purchase_units_count, 0) as purchase_units_count'),
            ])
            ->orderByDesc('pu.id');
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
                'sale_units_count' => (int) ($row->sale_units_count ?? 0),
                'purchase_units_count' => (int) ($row->purchase_units_count ?? 0),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->all();
    }

    private function clearListCache(bool $clearLinkedUnits = false): void
    {
        Cache::forget(self::CACHE_KEY);
        if ($clearLinkedUnits) {
            Cache::forget('api_sale_units_list');
            Cache::forget('api_purchase_units_list');
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $unit = ProductUnit::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Base unit created successfully',
            'data' => $unit,
        ], 201);
    }

    public function show($id)
    {
        $unit = ProductUnit::withCount(['products', 'saleUnits', 'purchaseUnits'])->find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Base unit not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $unit,
        ]);
    }

    public function update(Request $request, $id)
    {
        $unit = ProductUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Base unit not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $unit->update($validated);

        $this->clearListCache(true);

        return response()->json([
            'status' => 'success',
            'message' => 'Base unit updated successfully',
            'data' => $unit,
        ]);
    }

    public function toggleStatus($id)
    {
        $unit = ProductUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Base unit not found',
            ], 404);
        }

        $nextStatus = strtolower($unit->status) === 'active' ? 'Inactive' : 'Active';
        $unit->status = $nextStatus;
        $unit->save();

        $this->clearListCache(true);

        return response()->json([
            'status' => 'success',
            'message' => "Base unit status updated to {$nextStatus}",
            'data' => $unit,
        ]);
    }

    public function destroy($id)
    {
        $unit = ProductUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Base unit not found',
            ], 404);
        }

        // Senior Accountant & Business Analyst Safeguard:
        // Check active dependencies before allowing deletion.
        $productsCount = $unit->products()->count();
        $saleUnitsCount = $unit->saleUnits()->count();
        $purchaseUnitsCount = $unit->purchaseUnits()->count();

        if ($productsCount > 0 || $saleUnitsCount > 0 || $purchaseUnitsCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete base unit '{$unit->name}'. It is currently linked to {$productsCount} product(s), {$saleUnitsCount} sale unit(s), and {$purchaseUnitsCount} purchase unit(s). Please reassign or remove these dependencies first to preserve inventory audit trails and valuation ledgers.",
                'products_count' => $productsCount,
                'sale_units_count' => $saleUnitsCount,
                'purchase_units_count' => $purchaseUnitsCount,
            ], 422);
        }

        $unit->delete();

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Base unit deleted successfully',
        ]);
    }
}
