<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductUnit;
use Illuminate\Http\Request;

class ProductUnitController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductUnit::withCount(['products', 'saleUnits', 'purchaseUnits'])->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        $units = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $units,
        ]);
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

        return response()->json([
            'status' => 'success',
            'message' => 'Base unit deleted successfully',
        ]);
    }
}
