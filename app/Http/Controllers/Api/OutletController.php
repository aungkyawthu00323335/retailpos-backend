<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use Illuminate\Http\Request;

class OutletController extends Controller
{
    /**
     * Display a listing of outlets with summary statistics.
     */
    public function index()
    {
        $outlets = Outlet::orderBy('id', 'desc')->get();

        $totalOutlets = $outlets->count();
        $activeOutlets = $outlets->where('is_active', true)->count();
        $inactiveOutlets = $outlets->where('is_active', false)->count();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_outlets' => $totalOutlets,
                'active_outlets' => $activeOutlets,
                'inactive_outlets' => $inactiveOutlets,
            ],
            'data' => $outlets,
        ]);
    }

    /**
     * Store a newly created outlet.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'manager_name' => 'nullable|string|max:255',
            'logo' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $outlet = Outlet::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'manager_name' => $validated['manager_name'] ?? null,
            'logo' => $validated['logo'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Outlet created successfully',
            'data' => $outlet,
        ], 201);
    }

    /**
     * Display the specified outlet.
     */
    public function show($id)
    {
        $outlet = Outlet::find($id);

        if (!$outlet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Outlet not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $outlet,
        ]);
    }

    /**
     * Update the specified outlet.
     */
    public function update(Request $request, $id)
    {
        $outlet = Outlet::find($id);

        if (!$outlet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Outlet not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'manager_name' => 'nullable|string|max:255',
            'logo' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $outlet->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Outlet updated successfully',
            'data' => $outlet,
        ]);
    }

    /**
     * Remove the specified outlet.
     */
    public function destroy($id)
    {
        $outlet = Outlet::find($id);

        if (!$outlet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Outlet not found',
            ], 404);
        }

        $outlet->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Outlet deleted successfully',
        ]);
    }

    /**
     * Toggle active/inactive status.
     */
    public function toggleStatus($id)
    {
        $outlet = Outlet::find($id);

        if (!$outlet) {
            return response()->json([
                'status' => 'error',
                'message' => 'Outlet not found',
            ], 404);
        }

        $outlet->is_active = !$outlet->is_active;
        $outlet->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Outlet status toggled successfully',
            'data' => $outlet,
        ]);
    }
}
