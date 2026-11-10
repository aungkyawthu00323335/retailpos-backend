<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerGroupController extends Controller
{
    /**
     * Display a listing of customer groups.
     */
    public function index(Request $request)
    {
        $query = CustomerGroup::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $groups = $query->orderBy('id', 'asc')->get();

        // Calculate member counts for each group in 1 single fast query
        $memberCounts = Customer::selectRaw('tier, count(*) as count')
            ->groupBy('tier')
            ->pluck('count', 'tier');

        $mapped = $groups->map(function ($g) use ($memberCounts) {
            $g->members_count = (int)($memberCounts[$g->name] ?? 0);
            return $g;
        });

        $summaryData = CustomerGroup::selectRaw("
            COUNT(*) as total_groups,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_groups,
            SUM(CASE WHEN discount_percentage > 0 THEN 1 ELSE 0 END) as discounted_groups
        ")->first();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_groups' => (int)($summaryData->total_groups ?? 0),
                'active_groups' => (int)($summaryData->active_groups ?? 0),
                'discounted_groups' => (int)($summaryData->discounted_groups ?? 0),
            ],
            'data' => $mapped,
        ]);
    }

    /**
     * Store a newly created customer group.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:customer_groups,name',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $group = CustomerGroup::create([
            'name' => $validated['name'],
            'discount_percentage' => $validated['discount_percentage'] ?? 0.00,
            'color' => $validated['color'] ?? '#2563EB',
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        $group->members_count = 0;

        return response()->json([
            'status' => 'success',
            'message' => 'Customer group created successfully',
            'data' => $group,
        ], 201);
    }

    /**
     * Display the specified customer group.
     */
    public function show($id)
    {
        $group = CustomerGroup::find($id);

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer group not found',
            ], 404);
        }

        $group->members_count = Customer::where('tier', $group->name)->count();

        return response()->json([
            'status' => 'success',
            'data' => $group,
        ]);
    }

    /**
     * Update the specified customer group.
     */
    public function update(Request $request, $id)
    {
        $group = CustomerGroup::find($id);

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer group not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:customer_groups,name,' . $id,
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $group->update($validated);
        $group->members_count = Customer::where('tier', $group->name)->count();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer group updated successfully',
            'data' => $group,
        ]);
    }

    /**
     * Toggle active/inactive status of the customer group.
     */
    public function toggleStatus($id)
    {
        $group = CustomerGroup::find($id);

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer group not found',
            ], 404);
        }

        $group->status = ($group->status === 'Active') ? 'Inactive' : 'Active';
        $group->save();
        $group->members_count = Customer::where('tier', $group->name)->count();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer group status updated to ' . $group->status,
            'data' => $group,
        ]);
    }

    /**
     * Remove the specified customer group with Senior Accountant safeguards.
     */
    public function destroy($id)
    {
        $group = CustomerGroup::find($id);

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer group not found',
            ], 404);
        }

        $membersCount = Customer::where('tier', $group->name)->count();

        if ($membersCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete customer group "' . $group->name . '" because it currently has ' . $membersCount . ' active registered customer member(s). Deleting this group would create orphaned customer membership tiers and break POS discount automation. Please reassign the customers or deactivate the group instead.',
                'members_count' => $membersCount,
            ], 422);
        }

        $group->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer group deleted successfully',
        ]);
    }
}
