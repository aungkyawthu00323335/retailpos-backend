<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of user accounts.
     */
    public function index(Request $request)
    {
        $query = User::with('roleModel');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('role', 'like', "%{$search}%")
                  ->orWhere('branch', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('branch')) {
            $query->where('branch', $request->input('branch'));
        }

        $users = $query->orderBy('id', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_users' => $users->count(),
                'active_users' => $users->where('status', 'Active')->count(),
            ],
            'data' => $users,
        ]);
    }

    /**
     * Store a newly created user account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:50',
            'password' => 'nullable|string|min:6',
            'role_id' => 'nullable|exists:roles,id',
            'role' => 'nullable|string|max:100',
            'branch' => 'nullable|string|max:255',
            'avatar' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $roleName = $validated['role'] ?? 'Cashier';
        $roleId = $validated['role_id'] ?? null;

        if (!$roleId && !empty($roleName)) {
            $matchedRole = Role::where('name', $roleName)->first();
            if ($matchedRole) {
                $roleId = $matchedRole->id;
            }
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password'] ?? 'password123'),
            'role_id' => $roleId,
            'role' => $roleName,
            'branch' => $validated['branch'] ?? 'Main Branch Outlet',
            'avatar' => $validated['avatar'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
            'status' => $validated['status'] ?? 'Active',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'User account created successfully',
            'data' => $user->load('roleModel'),
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::with('roleModel')->find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $user,
        ]);
    }

    /**
     * Update the specified user account.
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:50',
            'password' => 'nullable|string|min:6',
            'role_id' => 'nullable|exists:roles,id',
            'role' => 'nullable|string|max:100',
            'branch' => 'nullable|string|max:255',
            'avatar' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        if (isset($validated['password']) && !empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (isset($validated['role']) && !isset($validated['role_id'])) {
            $matchedRole = Role::where('name', $validated['role'])->first();
            if ($matchedRole) {
                $validated['role_id'] = $matchedRole->id;
            }
        }

        $user->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'User account updated successfully',
            'data' => $user->load('roleModel'),
        ]);
    }

    /**
     * Remove the specified user account.
     */
    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found',
            ], 404);
        }

        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'User account deleted successfully',
        ]);
    }
}
