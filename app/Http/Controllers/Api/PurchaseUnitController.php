<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductUnit;
use App\Models\PurchaseUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PurchaseUnitController extends Controller
{
    private const CACHE_KEY = 'api_purchase_units_list';
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
                    ->orWhere('pu.base_unit_name', 'like', "%{$search}%")
                    ->orWhere('bu.name', 'like', "%{$search}%")
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
            ->selectRaw('purchase_unit_id, COUNT(*) as products_count')
            ->whereNull('deleted_at')
            ->whereNotNull('purchase_unit_id')
            ->groupBy('purchase_unit_id');

        $linkCountsSub = DB::table('product_unit_links')
            ->selectRaw('unit_id, COUNT(DISTINCT product_id) as links_count')
            ->where('unit_type', 'purchase')
            ->groupBy('unit_id');

        $purchaseItemCountsSub = DB::table('purchase_items')
            ->selectRaw('unit_id, COUNT(*) as purchases_count')
            ->whereNotNull('unit_id')
            ->groupBy('unit_id');

        return DB::table('purchase_units as pu')
            ->leftJoin('product_units as bu', 'pu.base_unit_id', '=', 'bu.id')
            ->leftJoinSub($productCountsSub, 'p_counts', function ($join) {
                $join->on('pu.id', '=', 'p_counts.purchase_unit_id');
            })
            ->leftJoinSub($linkCountsSub, 'pul_counts', function ($join) {
                $join->on('pu.id', '=', 'pul_counts.unit_id');
            })
            ->leftJoinSub($purchaseItemCountsSub, 'pi_counts', function ($join) {
                $join->on('pu.id', '=', 'pi_counts.unit_id');
            })
            ->select([
                'pu.id',
                'pu.name',
                'pu.base_unit_id',
                'pu.base_unit_name',
                'bu.name as base_unit_rel_name',
                'pu.description',
                'pu.status',
                'pu.created_at',
                'pu.updated_at',
                DB::raw('GREATEST(COALESCE(p_counts.products_count, 0), COALESCE(pul_counts.links_count, 0)) as products_count'),
                DB::raw('COALESCE(pi_counts.purchases_count, 0) as purchases_count'),
            ])
            ->orderByDesc('pu.id');
    }

    private function formatListingRows($rows): array
    {
        return collect($rows)->map(static function ($row) {
            $baseUnitId = $row->base_unit_id !== null ? (int) $row->base_unit_id : null;
            $baseUnitName = $row->base_unit_name ?? $row->base_unit_rel_name;
            $purchasesCount = (int) ($row->purchases_count ?? 0);

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
                'purchases_count' => $purchasesCount,
                'purchase_items_count' => $purchasesCount,
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

        $unit = PurchaseUnit::create([
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
            'message' => 'Purchase unit created successfully',
            'data' => $unit,
        ], 201);
    }

    public function show($id)
    {
        $unit = PurchaseUnit::with('baseUnit')->withCount(['products', 'purchaseItems'])->find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Purchase unit not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $unit,
        ]);
    }

    public function update(Request $request, $id)
    {
        $unit = PurchaseUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Purchase unit not found',
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
            'message' => 'Purchase unit updated successfully',
            'data' => $unit,
        ]);
    }

    public function toggleStatus($id)
    {
        $unit = PurchaseUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Purchase unit not found',
            ], 404);
        }

        $nextStatus = strtolower($unit->status) === 'active' ? 'Inactive' : 'Active';
        $unit->status = $nextStatus;
        $unit->save();
        $unit->load('baseUnit');

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => "Purchase unit status updated to {$nextStatus}",
            'data' => $unit,
        ]);
    }

    public function destroy($id)
    {
        $unit = PurchaseUnit::find($id);

        if (!$unit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Purchase unit not found',
            ], 404);
        }

        // Senior Accountant & Business Analyst Safeguard:
        // Check active dependencies before allowing deletion.
        $productsCount = max($unit->products()->count(), $unit->productUnitLinks()->count());
        $purchasesCount = $unit->purchaseItems()->count();

        if ($productsCount > 0 || $purchasesCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete purchase unit '{$unit->name}'. It is currently linked to {$productsCount} active product(s) and {$purchasesCount} purchase invoice line item(s). Please reassign or remove these dependencies first to preserve procurement audit trails, accounts payable ledgers, and inventory valuation formulas.",
                'products_count' => $productsCount,
                'purchases_count' => $purchasesCount,
            ], 422);
        }

        $unit->delete();

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Purchase unit deleted successfully',
        ]);
    }
}

