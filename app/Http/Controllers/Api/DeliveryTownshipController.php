<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryTownship;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DeliveryTownshipController extends Controller
{
    private const CACHE_KEY = 'delivery_townships_list_all';

    public function index(Request $request)
    {
        $hasFilters = $request->filled('search') ||
            $request->filled('region_id') ||
            ($request->filled('status') && $request->input('status') !== 'All') ||
            $request->filled('cod_only');

        $cacheKey = self::CACHE_KEY . ($request->filled('region_id') ? "_reg_{$request->input('region_id')}" : '');

        if (!$hasFilters && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached) && !isset($cached['__PHP_Incomplete_Class_Name'])) {
                return response()->json([
                    'status' => 'success',
                    'data' => $cached,
                ], 200, ['X-Cache' => 'HIT']);
            }
            Cache::forget($cacheKey);
        }

        $query = DeliveryTownship::query()->with(['region:id,name,name_mm,code,is_active']);

        if ($request->filled('region_id')) {
            $query->where('delivery_region_id', (int)$request->input('region_id'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('name_mm', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhereHas('region', function ($rq) use ($search) {
                      $rq->where('name', 'like', "%{$search}%")
                         ->orWhere('name_mm', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $isActive = $request->input('status') === 'Active';
            $query->where('is_active', $isActive);
        }

        if ($request->has('cod_only') && $request->boolean('cod_only')) {
            $query->where('is_cod_available', true);
        }

        $townships = $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        $townshipsArray = $townships->toArray();

        if (!$hasFilters) {
            Cache::put($cacheKey, $townshipsArray, 3600);
        }

        return response()->json([
            'status' => 'success',
            'data' => $townshipsArray,
        ], 200, ['X-Cache' => 'MISS']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'delivery_region_id' => 'required|exists:delivery_regions,id',
            'name' => 'required|string|max:255',
            'name_mm' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:50',
            'delivery_fee' => 'required|numeric|min:0',
            'free_delivery_threshold' => 'nullable|numeric|min:0',
            'estimated_days' => 'nullable|string|max:50',
            'is_cod_available' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $township = DeliveryTownship::create([
            'delivery_region_id' => $validated['delivery_region_id'],
            'name' => trim($validated['name']),
            'name_mm' => !empty($validated['name_mm']) ? trim($validated['name_mm']) : null,
            'code' => !empty($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'delivery_fee' => (float)$validated['delivery_fee'],
            'free_delivery_threshold' => !empty($validated['free_delivery_threshold']) ? (float)$validated['free_delivery_threshold'] : null,
            'estimated_days' => !empty($validated['estimated_days']) ? trim($validated['estimated_days']) : '1-2 Days',
            'is_cod_available' => $validated['is_cod_available'] ?? true,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Delivery township created successfully',
            'data' => $township->load('region:id,name,name_mm,code'),
        ], 201);
    }

    public function show(int $id)
    {
        $township = DeliveryTownship::with(['region'])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $township,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $township = DeliveryTownship::findOrFail($id);

        $validated = $request->validate([
            'delivery_region_id' => 'required|exists:delivery_regions,id',
            'name' => 'required|string|max:255',
            'name_mm' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:50',
            'delivery_fee' => 'required|numeric|min:0',
            'free_delivery_threshold' => 'nullable|numeric|min:0',
            'estimated_days' => 'nullable|string|max:50',
            'is_cod_available' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $township->update([
            'delivery_region_id' => $validated['delivery_region_id'],
            'name' => trim($validated['name']),
            'name_mm' => !empty($validated['name_mm']) ? trim($validated['name_mm']) : null,
            'code' => !empty($validated['code']) ? strtoupper(trim($validated['code'])) : null,
            'delivery_fee' => (float)$validated['delivery_fee'],
            'free_delivery_threshold' => !empty($validated['free_delivery_threshold']) ? (float)$validated['free_delivery_threshold'] : null,
            'estimated_days' => !empty($validated['estimated_days']) ? trim($validated['estimated_days']) : $township->estimated_days,
            'is_cod_available' => $validated['is_cod_available'] ?? $township->is_cod_available,
            'is_active' => $validated['is_active'] ?? $township->is_active,
            'sort_order' => $validated['sort_order'] ?? $township->sort_order,
        ]);

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Delivery township updated successfully',
            'data' => $township->load('region:id,name,name_mm,code'),
        ]);
    }

    public function destroy(int $id)
    {
        $township = DeliveryTownship::findOrFail($id);
        $township->delete();

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Delivery township deleted successfully',
        ]);
    }

    public function toggleStatus(int $id)
    {
        $township = DeliveryTownship::findOrFail($id);
        $township->is_active = !$township->is_active;
        $township->save();

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => $township->is_active ? 'Township activated' : 'Township deactivated',
            'data' => $township,
        ]);
    }

    public function toggleCod(int $id)
    {
        $township = DeliveryTownship::findOrFail($id);
        $township->is_cod_available = !$township->is_cod_available;
        $township->save();

        $this->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => $township->is_cod_available ? 'COD enabled' : 'COD disabled',
            'data' => $township,
        ]);
    }

    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('delivery_regions_list_all');
        Cache::forget('telegram_active_delivery_matrix');
    }
}
