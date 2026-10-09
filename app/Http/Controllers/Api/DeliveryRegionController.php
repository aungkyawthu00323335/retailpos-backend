<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRegion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DeliveryRegionController extends Controller
{
    private const CACHE_KEY = 'delivery_regions_list_all';

    public function index(Request $request)
    {
        $hasFilters = $request->filled('search') || ($request->filled('status') && $request->input('status') !== 'All');

        if (!$hasFilters && Cache::has(self::CACHE_KEY)) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && !empty($cached) && !isset($cached['__PHP_Incomplete_Class_Name'])) {
                return response()->json([
                    'status' => 'success',
                    'data' => $cached,
                ], 200, ['X-Cache' => 'HIT']);
            }
            Cache::forget(self::CACHE_KEY);
        }

        $query = DeliveryRegion::query()
            ->withCount([
                'townships',
                'townships as active_townships_count' => function ($q) {
                    $q->where('is_active', true);
                }
            ]);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('name_mm', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $isActive = $request->input('status') === 'Active';
            $query->where('is_active', $isActive);
        }

        $regions = $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        $regionsArray = $regions->toArray();

        if (!$hasFilters) {
            Cache::put(self::CACHE_KEY, $regionsArray, 3600);
        }

        return response()->json([
            'status' => 'success',
            'data' => $regionsArray,
        ], 200, ['X-Cache' => 'MISS']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:delivery_regions,name',
            'name_mm' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $region = DeliveryRegion::create([
            'name' => trim($validated['name']),
            'name_mm' => !empty($validated['name_mm']) ? trim($validated['name_mm']) : null,
            'code' => !empty($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Delivery region created successfully',
            'data' => $region->loadCount('townships'),
        ], 201);
    }

    public function show(int $id)
    {
        $region = DeliveryRegion::with(['townships'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $region,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $region = DeliveryRegion::findOrFail($id);

        $validated = $request->validate([
            'name' => "required|string|max:255|unique:delivery_regions,name,{$id}",
            'name_mm' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $region->update([
            'name' => trim($validated['name']),
            'name_mm' => !empty($validated['name_mm']) ? trim($validated['name_mm']) : null,
            'code' => !empty($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'is_active' => $validated['is_active'] ?? $region->is_active,
            'sort_order' => $validated['sort_order'] ?? $region->sort_order,
        ]);

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Delivery region updated successfully',
            'data' => $region->loadCount('townships'),
        ]);
    }

    public function destroy(int $id)
    {
        $region = DeliveryRegion::findOrFail($id);
        $region->delete();

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Delivery region deleted successfully',
        ]);
    }

    public function toggleStatus(int $id)
    {
        $region = DeliveryRegion::findOrFail($id);
        $region->is_active = !$region->is_active;
        $region->save();

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => $region->is_active ? 'Region activated' : 'Region deactivated',
            'data' => $region,
        ]);
    }

    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('delivery_townships_list_all');
        Cache::forget('telegram_active_delivery_matrix');
    }
}
