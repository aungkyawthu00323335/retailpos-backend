<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Variation;
use Illuminate\Http\Request;

class VariationController extends Controller
{
    /**
     * Display a listing of variations.
     */
    public function index(Request $request)
    {
        // Default seed if empty
        if (Variation::count() === 0) {
            $defaults = [
                ['name' => 'Size', 'variation_types' => ['S', 'M', 'L', 'XL', 'XXL'], 'status' => 'Active'],
                ['name' => 'Color', 'variation_types' => ['Red', 'Blue', 'Black', 'White', 'Navy'], 'status' => 'Active'],
                ['name' => 'Material', 'variation_types' => ['Cotton', 'Polyester', 'Leather', 'Silk'], 'status' => 'Active'],
                ['name' => 'Storage', 'variation_types' => ['64GB', '128GB', '256GB', '512GB', '1TB'], 'status' => 'Active'],
                ['name' => 'RAM', 'variation_types' => ['4GB', '8GB', '16GB', '32GB'], 'status' => 'Active'],
            ];

            foreach ($defaults as $item) {
                Variation::create($item);
            }
        }

        $query = Variation::query();

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (in_array($sortBy, ['id', 'name', 'status', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $variations = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $variations,
        ]);
    }

    /**
     * Store a newly created variation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:variations,name',
            'variation_types' => 'required|array|min:1',
            'variation_types.*' => 'required|string|max:100',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $variation = Variation::create([
            'name' => trim($validated['name']),
            'variation_types' => array_values(array_unique(array_map('trim', $validated['variation_types']))),
            'status' => $validated['status'] ?? 'Active',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Variation created successfully',
            'data' => $variation,
        ], 201);
    }

    /**
     * Display the specified variation.
     */
    public function show($id)
    {
        $variation = Variation::find($id);

        if (!$variation) {
            return response()->json(['status' => 'error', 'message' => 'Variation not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $variation,
        ]);
    }

    /**
     * Update the specified variation.
     */
    public function update(Request $request, $id)
    {
        $variation = Variation::find($id);

        if (!$variation) {
            return response()->json(['status' => 'error', 'message' => 'Variation not found'], 404);
        }

        $oldName = $variation->name;
        $oldTypes = is_array($variation->variation_types) ? $variation->variation_types : [];

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:variations,name,' . $id,
            'variation_types' => 'sometimes|required|array|min:1',
            'variation_types.*' => 'required|string|max:100',
            'type_renames' => 'nullable|array',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $newName = isset($validated['name']) ? trim($validated['name']) : $oldName;
        $newTypes = isset($validated['variation_types'])
            ? array_values(array_unique(array_map('trim', $validated['variation_types'])))
            : $oldTypes;

        $validated['name'] = $newName;
        $validated['variation_types'] = $newTypes;

        // 1. Detect / Collect Type Renames
        $renames = [];
        if ($request->filled('type_renames') && is_array($request->input('type_renames'))) {
            foreach ($request->input('type_renames') as $k => $v) {
                if (!empty($k) && !empty($v) && $k !== $v) {
                    $renames[trim($k)] = trim($v);
                }
            }
        }

        // Auto-detect positional renames if same count
        if (empty($renames) && count($oldTypes) === count($newTypes)) {
            for ($i = 0; $i < count($oldTypes); $i++) {
                if ($oldTypes[$i] !== $newTypes[$i]) {
                    $renames[$oldTypes[$i]] = $newTypes[$i];
                }
            }
        }

        // Auto-detect single replacement if 1 removed and 1 added
        if (empty($renames)) {
            $removed = array_values(array_diff($oldTypes, $newTypes));
            $added = array_values(array_diff($newTypes, $oldTypes));
            if (count($removed) === 1 && count($added) === 1) {
                $renames[$removed[0]] = $added[0];
            }
        }

        \DB::transaction(function () use ($variation, $validated, $oldName, $newName, $renames) {
            // Update variation record
            $variation->update([
                'name' => $validated['name'],
                'variation_types' => $validated['variation_types'],
                'status' => $validated['status'] ?? $variation->status,
            ]);

            // Synchronize ProductVariantValue and ProductVariant records
            foreach ($renames as $oldVal => $newVal) {
                // Update variant values
                \App\Models\ProductVariantValue::where(function ($q) use ($oldName, $newName) {
                    $q->where('attribute_name', $oldName)
                      ->orWhere('attribute_name', $newName);
                })
                ->where('attribute_value', $oldVal)
                ->update(['attribute_value' => $newVal]);

                // Find variants that need name update
                $variants = \App\Models\ProductVariant::where('name', $oldVal)
                    ->orWhere('name', 'like', "{$oldName} - {$oldVal}")
                    ->orWhere('name', 'like', "{$newName} - {$oldVal}")
                    ->orWhere('name', 'like', "% / {$oldVal}%")
                    ->orWhere('name', 'like', "%{$oldVal} / %")
                    ->get();

                foreach ($variants as $variant) {
                    $updatedName = $variant->name;
                    if ($updatedName === $oldVal) {
                        $updatedName = $newVal;
                    } else if ($updatedName === "{$oldName} - {$oldVal}") {
                        $updatedName = "{$newName} - {$newVal}";
                    } else if ($updatedName === "{$newName} - {$oldVal}") {
                        $updatedName = "{$newName} - {$newVal}";
                    } else {
                        $updatedName = str_replace($oldVal, $newVal, $updatedName);
                    }
                    $variant->update(['name' => $updatedName]);
                }
            }

            // If variation name changed (e.g. blue -> navy_blue)
            if ($oldName !== $newName) {
                \App\Models\ProductVariantValue::where('attribute_name', $oldName)
                    ->update(['attribute_name' => $newName]);

                $variantsWithNamePrefix = \App\Models\ProductVariant::where('name', 'like', "{$oldName} - %")->get();
                foreach ($variantsWithNamePrefix as $variant) {
                    $rest = substr($variant->name, strlen("{$oldName} - "));
                    $variant->update(['name' => "{$newName} - {$rest}"]);
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Variation and connected product variants updated successfully',
            'data' => $variation->fresh(),
        ]);
    }

    /**
     * Remove the specified variation.
     */
    public function destroy($id)
    {
        $variation = Variation::find($id);

        if (!$variation) {
            return response()->json(['status' => 'error', 'message' => 'Variation not found'], 404);
        }

        $variation->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Variation deleted successfully',
        ]);
    }
}