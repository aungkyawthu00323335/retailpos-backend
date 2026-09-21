<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaleUnit;
use App\Models\ProductUnit;
use Illuminate\Http\Request;

class SaleUnitController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleUnit::with('baseUnit')->withCount(['products', 'saleItems'])->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('base_unit_name', 'like', "%{$search}%")
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

        return response()->json([
            'status' => 'success',
            'message' => 'Sale unit deleted successfully',
        ]);
    }
}

