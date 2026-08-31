<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseUnit;
use App\Models\ProductUnit;
use Illuminate\Http\Request;

class PurchaseUnitController extends Controller
{
    public function index()
    {
        $units = PurchaseUnit::with('baseUnit')->orderBy('id', 'desc')->get();

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

        $unit = PurchaseUnit::create([
            'name' => $validated['name'],
            'base_unit_id' => $validated['base_unit_id'],
            'base_unit_name' => $validated['base_unit_name'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        $unit->load('baseUnit');

        return response()->json([
            'status' => 'success',
            'message' => 'Purchase unit created successfully',
            'data' => $unit,
        ], 201);
    }

    public function show($id)
    {
        $unit = PurchaseUnit::with('baseUnit')->find($id);

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

        return response()->json([
            'status' => 'success',
            'message' => 'Purchase unit updated successfully',
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

        $unit->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Purchase unit deleted successfully',
        ]);
    }
}

