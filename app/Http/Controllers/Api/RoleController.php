<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    /**
     * Display a listing of roles with user counts.
     */
    public function index(Request $request)
    {
        $query = Role::withCount('users');

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

        $roles = $query->orderBy('id', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_roles' => $roles->count(),
                'active_roles' => $roles->where('status', 'Active')->count(),
            ],
            'data' => $roles,
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'permissions' => 'nullable|array',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $caller = User::resolveFromRequest($request);
        if (isset($validated['permissions']) && is_array($validated['permissions']) && in_array('*', $validated['permissions'], true)) {
            if (!$caller || !$caller->isSuperAdmin()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Only Super Admin can create roles with wildcard permissions.',
                ], 403);
            }
        }

        $slug = Str::slug($validated['name'], '_');
        $originalSlug = $slug;
        $counter = 1;
        while (Role::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}_{$counter}";
            $counter++;
        }

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#2563EB',
            'permissions' => $validated['permissions'] ?? [],
            'is_default' => false,
            'status' => $validated['status'] ?? 'Active',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Role created successfully',
            'data' => $role->loadCount('users'),
        ], 201);
    }

    /**
     * Display the specified role.
     */
    public function show($id)
    {
        $role = Role::with('users')->withCount('users')->find($id);

        if (!$role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Role not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $role,
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Role not found',
            ], 404);
        }

        $caller = User::resolveFromRequest($request);

        // SEC-03: Prevent non-super-admins from editing Super Admin role
        if ($role->slug === 'super_admin' || strtolower($role->name) === 'super admin') {
            if (!$caller || !$caller->isSuperAdmin()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Super Admin role cannot be modified by non-super-admin users.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'permissions' => 'nullable|array',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        if (isset($validated['name']) && strtolower(trim($validated['name'])) === 'super admin' && (!$caller || !$caller->isSuperAdmin())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Cannot rename a role to Super Admin.',
            ], 403);
        }

        if (isset($validated['permissions']) && is_array($validated['permissions']) && in_array('*', $validated['permissions'], true)) {
            if (!$caller || !$caller->isSuperAdmin()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Only Super Admin can assign wildcard permissions.',
                ], 403);
            }
        }

        $role->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Role updated successfully',
            'data' => $role->loadCount('users'),
        ]);
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Role not found',
            ], 404);
        }

        if ($role->slug === 'super_admin' || strtolower($role->name) === 'super admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Super Admin role cannot be deleted.',
            ], 403);
        }

        if ($role->is_default) {
            return response()->json([
                'status' => 'error',
                'message' => 'System default role cannot be deleted',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Role deleted successfully',
        ]);
    }
}
