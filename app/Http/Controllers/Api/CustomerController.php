<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request)
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('tier', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tier') && $request->input('tier') !== 'All') {
            $query->where('tier', $request->input('tier'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'name', 'phone', 'email', 'tier', 'reward_points', 'total_spent', 'total_visits', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $perPage = (int)$request->input('per_page', 25);
        if ($perPage <= 0) $perPage = 25;
        if ($perPage > 100) $perPage = 100;

        $paginated = $query->paginate($perPage);

        $summaryData = Customer::selectRaw("
            COUNT(*) as total_customers,
            SUM(CASE WHEN tier IN ('VIP Platinum', 'Gold VIP') THEN 1 ELSE 0 END) as vip_members,
            COALESCE(SUM(reward_points), 0) as total_points,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_customers
        ")->first();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_customers' => (int)($summaryData->total_customers ?? 0),
                'vip_members' => (int)($summaryData->vip_members ?? 0),
                'total_points' => (float)($summaryData->total_points ?? 0),
                'active_customers' => (int)($summaryData->active_customers ?? 0),
            ],
            'data' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'tier' => 'nullable|string|max:100',
            'reward_points' => 'nullable|integer',
            'total_spent' => 'nullable|numeric',
            'total_visits' => 'nullable|integer',
            'avatar' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
            'notes' => 'nullable|string',
        ]);

        $customer = Customer::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'tier' => $validated['tier'] ?? 'Silver Regular',
            'reward_points' => $validated['reward_points'] ?? 0,
            'total_spent' => $validated['total_spent'] ?? 0.00,
            'total_visits' => $validated['total_visits'] ?? 1,
            'avatar' => $validated['avatar'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
            'status' => $validated['status'] ?? 'Active',
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Customer created successfully',
            'data' => $customer,
        ], 201);
    }

    /**
     * Display the specified customer.
     */
    public function show($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $customer,
        ]);
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'tier' => 'nullable|string|max:100',
            'reward_points' => 'nullable|integer',
            'total_spent' => 'nullable|numeric',
            'total_visits' => 'nullable|integer',
            'avatar' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
            'notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Customer updated successfully',
            'data' => $customer,
        ]);
    }

    /**
     * Remove the specified customer.
     */
    public function destroy($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        $customer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer deleted successfully',
        ]);
    }
}
