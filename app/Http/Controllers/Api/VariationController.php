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

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:variations,name,' . $id,
            'variation_types' => 'sometimes|required|array|min:1',
            'variation_types.*' => 'required|string|max:100',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }
        if (isset($validated['variation_types'])) {
            $validated['variation_types'] = array_values(array_unique(array_map('trim', $validated['variation_types'])));
        }

        $variation->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Variation updated successfully',
            'data' => $variation,
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