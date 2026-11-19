<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductUnit;
use App\Models\SaleUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SaleUnitController extends Controller
{
    private const CACHE_KEY = 'api_sale_units_list';
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
                $q->where('su.name', 'like', "%{$search}%")
                    ->orWhere('su.base_unit_name', 'like', "%{$search}%")
                    ->orWhere('bu.name', 'like', "%{$search}%")
                    ->orWhere('su.description', 'like', "%{$search}%");
            });
        }

        if ($hasStatus) {
            $query->where('su.status', $request->input('status'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatListingRows($query->get()),
        ]);
    }

    private function buildListingQuery()
    {
        $productCountsSub = DB::table('products')
            ->selectRaw('sale_unit_id, COUNT(*) as products_count')
            ->whereNull('deleted_at')
            ->whereNotNull('sale_unit_id')
            ->groupBy('sale_unit_id');

        $linkCountsSub = DB::table('product_unit_links')
            ->selectRaw('unit_id, COUNT(DISTINCT product_id) as links_count')
            ->where('unit_type', 'sale')
            ->groupBy('unit_id');

        $saleItemCountsSub = DB::table('sale_items')
            ->selectRaw('unit_id, COUNT(*) as sales_count')
            ->whereNotNull('unit_id')
            ->groupBy('unit_id');

        return DB::table('sale_units as su')
            ->leftJoin('product_units as bu', 'su.base_unit_id', '=', 'bu.id')
            ->leftJoinSub($productCountsSub, 'p_counts', function ($join) {
                $join->on('su.id', '=', 'p_counts.sale_unit_id');
            })
            ->leftJoinSub($linkCountsSub, 'pul_counts', function ($join) {
                $join->on('su.id', '=', 'pul_counts.unit_id');
            })
            ->leftJoinSub($saleItemCountsSub, 'si_counts', function ($join) {
                $join->on('su.id', '=', 'si_counts.unit_id');
            })
            ->select([
                'su.id',
                'su.name',
                'su.base_unit_id',
                'su.base_unit_name',
                'bu.name as base_unit_rel_name',
                'su.description',
                'su.status',
                'su.created_at',
                'su.updated_at',
                DB::raw('GREATEST(COALESCE(p_counts.products_count, 0), COALESCE(pul_counts.links_count, 0)) as products_count'),
                DB::raw('COALESCE(si_counts.sales_count, 0) as sales_count'),
            ])
            ->orderByDesc('su.id');
    }

    private function formatListingRows($rows): array
    {
        return collect($rows)->map(static function ($row) {
            $baseUnitId = $row->base_unit_id !== null ? (int) $row->base_unit_id : null;
            $baseUnitName = $row->base_unit_name ?? $row->base_unit_rel_name;
            $salesCount = (int) ($row->sales_count ?? 0);

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'base_unit_id' => $baseUnitId,
                'base_unit_name' => $baseUnitName,
                'base_unit' => $baseUnitId !== null ? [
                    'id' => $baseUnitId,
                    'name' => $row->base_unit_rel_name ?? $baseUnitName,
                ] : null,
                'description' => $row->description,
                'status' => $row->status,
                'products_count' => (int) ($row->products_count ?? 0),
                'sales_count' => $salesCount,
                'sale_items_count' => $salesCount,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->all();
    }

    private function clearListCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('api_product_units_list');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_unit_id' => 'required|integer|exists:product_units,id',
            'base_unit_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        if (empty($validated['base_unit_name']) && !empty($validated['base_unit_id'])) {
            $baseUnit = ProductUnit::find($validated['base_unit_id']);
            if ($baseUnit) {
                $validated['base_unit_name'] = $baseUnit->name;
            }
        }

        $unit = SaleUnit::create([
            'name' => $validated['name'],
            'base_unit_id' => $validated['base_unit_id'],
            'base_unit_name' => $validated['base_unit_name'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        $unit->load('baseUnit');

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Sale unit created successfully',
            'data' => $unit,
        ], 201);
    }

    public function show($id)
    {
        $unit = SaleUnit::with('baseUnit')->withCount(['products', 'saleItems'])->find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sale unit not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $unit,
        ]);
    }

    public function update(Request $request, $id)
    {
        $unit = SaleUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sale unit not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'base_unit_id' => 'sometimes|required|integer|exists:product_units,id',
            'base_unit_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        if (array_key_exists('base_unit_id', $validated)) {
            if (!empty($validated['base_unit_id']) && empty($validated['base_unit_name'])) {
                $baseUnit = ProductUnit::find($validated['base_unit_id']);
                if ($baseUnit) {
                    $validated['base_unit_name'] = $baseUnit->name;
                }
            }
        }

        $unit->update($validated);
        $unit->load('baseUnit');

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Sale unit updated successfully',
            'data' => $unit,
        ]);
    }

    public function toggleStatus($id)
    {
        $unit = SaleUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sale unit not found',
            ], 404);
        }

        $nextStatus = strtolower($unit->status) === 'active' ? 'Inactive' : 'Active';
        $unit->status = $nextStatus;
        $unit->save();
        $unit->load('baseUnit');

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => "Sale unit status updated to {$nextStatus}",
            'data' => $unit,
        ]);
    }

    public function destroy($id)
    {
        $unit = SaleUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Sale unit not found',
            ], 404);
        }

        // Senior Accountant & Business Analyst Safeguard:
        // Check active dependencies before allowing deletion.
        $productsCount = max($unit->products()->count(), $unit->productUnitLinks()->count());
        $salesCount = $unit->saleItems()->count();

        if ($productsCount > 0 || $salesCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete sale unit '{$unit->name}'. It is currently linked to {$productsCount} product(s) and {$salesCount} sales transaction line item(s). Please reassign or remove these dependencies first to preserve revenue audit trails, sales invoices, and inventory deduction ledgers.",
                'products_count' => $productsCount,
                'sales_count' => $salesCount,
            ], 422);
        }

        $unit->delete();

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Sale unit deleted successfully',
        ]);
    }
}

