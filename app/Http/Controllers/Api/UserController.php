<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * Generate the next sequential numeric user ID (numbers only).
     */
    public function generateUserId(Request $request)
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $existing = User::whereNotNull('user_id')->pluck('user_id');
            $numericIds = $existing->filter(fn($id) => ctype_digit((string)$id))->map(fn($id) => (int)$id);
            $maxNum = $numericIds->max();
        } else {
            $maxNum = User::whereNotNull('user_id')
                ->where('user_id', 'REGEXP', '^[0-9]+$')
                ->selectRaw('MAX(CAST(user_id AS UNSIGNED)) as max_id')
                ->value('max_id');
        }

        $nextId = ($maxNum && (int)$maxNum >= 100000) ? (int)$maxNum + 1 : 100001;

        return response()->json([
            'status' => 'success',
            'data' => [
                'user_id' => (string)$nextId,
            ],
        ]);
    }

    /**
     * Display a listing of user accounts.
     */
    public function index(Request $request)
    {
        if ($request->boolean('compact')) {
            $users = User::query()
                ->with('roleModel:id,slug')
                ->select(['id', 'user_id', 'name', 'role_id', 'role', 'branch', 'status'])
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->input('search');
                    $q->where(function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('user_id', 'like', "%{$search}%")
                            ->orWhere('role', 'like', "%{$search}%");
                    });
                })
                ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
                ->when($request->filled('branch'), fn ($q) => $q->where('branch', $request->input('branch')))
                ->orderBy('id', 'asc')
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'user_id' => $u->user_id,
                    'name' => $u->name,
                    'role' => $u->role,
                    'role_id' => $u->role_id,
                    'branch' => $u->branch,
                    'status' => $u->status,
                    'is_super_admin' => (bool) $u->isSuperAdmin(),
                ]);

            return response()->json([
                'status' => 'success',
                'data' => $users,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        $caller = User::resolveFromRequest($request);
        if (!$caller) {
            return response()->json([
                'status' => 'error',
                'code' => 'UNAUTHENTICATED',
                'message' => 'Unauthenticated session. Please provide a valid Bearer token.',
            ], 401);
        }
        if (!$caller->isSuperAdmin() && !$caller->hasPermission('users.view')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access Denied: You do not have permission (users.view) to access this resource.',
            ], 403);
        }

        $query = User::with(['roleModel', 'outlets'])
            ->withCount(['sales', 'purchases', 'expenses', 'cashRegisters', 'stockAdjustments']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('user_id', 'like', "%{$search}%")
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

        $users = $query->orderBy('id', 'asc')->get()->map(function ($u) {
            $u->outlet_ids = $u->outlets->pluck('id')->toArray();
            $u->total_dependencies = (int) (
                ($u->sales_count ?? 0) +
                ($u->purchases_count ?? 0) +
                ($u->expenses_count ?? 0) +
                ($u->cash_registers_count ?? 0) +
                ($u->stock_adjustments_count ?? 0)
            );
            return $u;
        });

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_users' => $users->count(),
                'active_users' => $users->filter(fn($u) => $u->isActive())->count(),
                'super_admins' => $users->filter(fn($u) => $u->isSuperAdmin())->count(),
                'cashiers' => $users->filter(fn($u) => str_contains(strtolower((string)$u->role), 'cashier'))->count(),
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
            'user_id' => 'nullable|string|max:50|unique:users,user_id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:50',
            'password' => 'nullable|string|min:6',
            'role_id' => 'nullable|exists:roles,id',
            'role' => 'nullable|string|max:100',
            'branch' => 'nullable|string|max:255',
            'outlet_ids' => 'nullable|array',
            'outlet_ids.*' => 'exists:outlets,id',
            'avatar' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $userId = !empty($validated['user_id']) ? trim($validated['user_id']) : null;
        if (!$userId) {
            $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $existing = User::whereNotNull('user_id')->pluck('user_id');
                $numericIds = $existing->filter(fn($id) => ctype_digit((string)$id))->map(fn($id) => (int)$id);
                $maxNum = $numericIds->max();
            } else {
                $maxNum = User::whereNotNull('user_id')
                    ->where('user_id', 'REGEXP', '^[0-9]+$')
                    ->selectRaw('MAX(CAST(user_id AS UNSIGNED)) as max_id')
                    ->value('max_id');
            }
            $userId = (string)(($maxNum && (int)$maxNum >= 100000) ? (int)$maxNum + 1 : 100001);
        }

        $caller = User::resolveFromRequest($request);

        $roleId = $validated['role_id'] ?? null;
        $roleName = isset($validated['role']) ? trim((string) $validated['role']) : '';
        $assignedRole = null;

        if ($roleId) {
            $assignedRole = Role::find($roleId);
            if ($assignedRole) {
                $roleName = $assignedRole->name;
            }
        } elseif ($roleName !== '') {
            $assignedRole = Role::whereRaw('LOWER(name) = ?', [strtolower($roleName)])->first();
            if ($assignedRole) {
                $roleId = $assignedRole->id;
                $roleName = $assignedRole->name;
            }
        }

        if ($roleName === '') {
            $roleName = 'Cashier';
            $assignedRole = Role::whereRaw('LOWER(name) = ?', ['cashier'])->first();
            if ($assignedRole) {
                $roleId = $assignedRole->id;
                $roleName = $assignedRole->name;
            }
        }

        // SEC-03: Privilege escalation prevention - only Super Admin can create Super Admin accounts
        $targetIsSuper = (strtolower(trim($roleName)) === 'super admin')
            || ($assignedRole && ($assignedRole->slug === 'super_admin' || strtolower(trim((string) $assignedRole->name)) === 'super admin'));

        if ($targetIsSuper && (!$caller || !$caller->isSuperAdmin())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Only Super Admin can create Super Admin accounts.',
            ], 403);
        }

        // R1.10: Non-Super-Admin cannot assign a role containing wildcard or unpossessed permissions
        if ($caller && !$caller->isSuperAdmin() && $assignedRole) {
            $rolePerms = is_array($assignedRole->permissions) ? $assignedRole->permissions : [];
            foreach ($rolePerms as $perm) {
                $permStr = trim((string) $perm);
                if ($permStr === '') {
                    continue;
                }
                if ($permStr === '*' || !$caller->hasPermission($permStr)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Unauthorized. You cannot assign a role containing permission '{$permStr}' which you do not possess.",
                    ], 403);
                }
            }
        }

        $outletIds = $validated['outlet_ids'] ?? [];
        $branchName = $validated['branch'] ?? null;
        if (empty($branchName) && !empty($outletIds)) {
            $branchName = Outlet::whereIn('id', $outletIds)->pluck('name')->join(', ');
        }
        if (empty($branchName)) {
            $branchName = 'Main Branch Outlet';
        }

        $user = User::create([
            'user_id' => $userId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password'] ?? 'password123'),
            'role_id' => $roleId,
            'role' => $roleName,
            'branch' => $branchName,
            'avatar' => $validated['avatar'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
            'status' => $validated['status'] ?? 'Active',
        ]);

        if (!empty($outletIds)) {
            $user->outlets()->sync($outletIds);
        } else {
            // Default to first active outlet if none provided
            $defaultOutlet = Outlet::where('is_active', true)->first();
            if ($defaultOutlet) {
                $user->outlets()->sync([$defaultOutlet->id]);
            }
        }

        $user->load(['roleModel', 'outlets']);
        $user->outlet_ids = $user->outlets->pluck('id')->toArray();

        return response()->json([
            'status' => 'success',
            'message' => 'User account created successfully',
            'data' => $user,
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::with(['roleModel', 'outlets'])
            ->withCount(['sales', 'purchases', 'expenses', 'cashRegisters', 'stockAdjustments'])
            ->find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found',
            ], 404);
        }

        $user->outlet_ids = $user->outlets->pluck('id')->toArray();
        $user->total_dependencies = (int) (
            ($user->sales_count ?? 0) +
            ($user->purchases_count ?? 0) +
            ($user->expenses_count ?? 0) +
            ($user->cash_registers_count ?? 0) +
            ($user->stock_adjustments_count ?? 0)
        );

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
        $user = User::with('roleModel')->find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found',
            ], 404);
        }

        $caller = User::resolveFromRequest($request);

        // SEC-03: Only Super Admin can modify Super Admin accounts
        if ($user->isSuperAdmin() && (!$caller || !$caller->isSuperAdmin())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Only Super Admin can modify Super Admin accounts.',
            ], 403);
        }

        $validated = $request->validate([
            'user_id' => 'sometimes|nullable|string|max:50|unique:users,user_id,' . $id,
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:50',
            'password' => 'nullable|string|min:6',
            'role_id' => 'nullable|exists:roles,id',
            'role' => 'nullable|string|max:100',
            'branch' => 'nullable|string|max:255',
            'outlet_ids' => 'nullable|array',
            'outlet_ids.*' => 'exists:outlets,id',
            'avatar' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        // R1.10: Non-Super-Admin cannot reset password or email on a user whose existing role has permissions caller lacks
        if ($caller && !$caller->isSuperAdmin()) {
            $changingCredentials = !empty($validated['password'])
                || (array_key_exists('email', $validated) && strcasecmp(trim((string) $validated['email']), trim((string) $user->email)) !== 0);
            if ($changingCredentials && $user->roleModel) {
                $existingPerms = is_array($user->roleModel->permissions) ? $user->roleModel->permissions : [];
                foreach ($existingPerms as $perm) {
                    $permStr = trim((string) $perm);
                    if ($permStr === '') {
                        continue;
                    }
                    if ($permStr === '*' || !$caller->hasPermission($permStr)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => "Unauthorized. You cannot modify credentials for a user holding permission '{$permStr}' which you do not possess.",
                        ], 403);
                    }
                }
            }
        }

        if (isset($validated['password']) && !empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        // Sync role_id <-> role BEFORE escalation and sole-Super-Admin guards
        $targetRoleModel = $user->roleModel;
        if (array_key_exists('role_id', $validated) && $validated['role_id'] !== null) {
            $matchedRole = Role::find($validated['role_id']);
            if ($matchedRole) {
                $targetRoleModel = $matchedRole;
                $validated['role'] = $matchedRole->name;
            }
        } elseif (array_key_exists('role', $validated) && trim((string) $validated['role']) !== '') {
            $trimmedRole = trim((string) $validated['role']);
            $matchedRole = Role::whereRaw('LOWER(name) = ?', [strtolower($trimmedRole)])->first();
            if ($matchedRole) {
                $targetRoleModel = $matchedRole;
                $validated['role_id'] = $matchedRole->id;
                $validated['role'] = $matchedRole->name;
            } else {
                $targetRoleModel = null;
                $validated['role_id'] = null;
                $validated['role'] = $trimmedRole;
            }
        }

        $finalRoleName = array_key_exists('role', $validated) ? (string) $validated['role'] : (string) $user->role;
        $willBeSuperAdmin = (strtolower(trim($finalRoleName)) === 'super admin')
            || ($targetRoleModel && ($targetRoleModel->slug === 'super_admin' || strtolower(trim((string) $targetRoleModel->name)) === 'super admin'));

        // SEC-03: Privilege escalation prevention - only Super Admin can assign Super Admin role
        if ($willBeSuperAdmin && !$user->isSuperAdmin() && (!$caller || !$caller->isSuperAdmin())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Only Super Admin can assign the Super Admin role.',
            ], 403);
        }

        // R1.10: Non-Super-Admin cannot assign a role containing wildcard or unpossessed permissions
        if ((array_key_exists('role_id', $validated) || array_key_exists('role', $validated))
            && $caller
            && !$caller->isSuperAdmin()
            && $targetRoleModel
        ) {
            $targetPerms = is_array($targetRoleModel->permissions) ? $targetRoleModel->permissions : [];
            foreach ($targetPerms as $perm) {
                $permStr = trim((string) $perm);
                if ($permStr === '') {
                    continue;
                }
                if ($permStr === '*' || !$caller->hasPermission($permStr)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Unauthorized. You cannot assign a role containing permission '{$permStr}' which you do not possess.",
                    ], 403);
                }
            }
        }

        // R1.5: Sole active Super Admin protection (cannot deactivate or demote the last active Super Admin)
        $wasActiveSuperAdmin = $user->isSuperAdmin() && $user->isActive();
        $finalStatus = $validated['status'] ?? $user->status;
        $willBeActive = strcasecmp(trim((string) $finalStatus), 'active') === 0;
        if ($wasActiveSuperAdmin && (!$willBeActive || !$willBeSuperAdmin)) {
            $otherActiveSuperAdmins = User::where('id', '!=', $user->id)
                ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
                ->where(function ($q) {
                    $q->whereRaw("LOWER(COALESCE(role, '')) = 'super admin'")
                      ->orWhereHas('roleModel', function ($rq) {
                          $rq->where('slug', 'super_admin')
                             ->whereRaw("LOWER(COALESCE(status, '')) = 'active'");
                      });
                })
                ->count();

            if ($otherActiveSuperAdmins === 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot deactivate or demote the sole active Super Admin account. At least one active Super Admin is required.',
                ], 422);
            }
        }

        // R1.2: Revoke active session token on deactivation
        if (array_key_exists('status', $validated) && strcasecmp(trim((string) $validated['status']), 'active') !== 0) {
            $validated['api_token'] = null;
            $validated['api_token_created_at'] = null;
        }

        if (array_key_exists('outlet_ids', $validated)) {
            $outletIds = $validated['outlet_ids'] ?? [];
            $user->outlets()->sync($outletIds);

            if (!isset($validated['branch']) || empty($validated['branch'])) {
                if (!empty($outletIds)) {
                    $validated['branch'] = Outlet::whereIn('id', $outletIds)->pluck('name')->join(', ');
                }
            }
        }

        $oldName = $user->name;
        $user->update($validated);

        // Propagate user name & role updates to existing stock adjustments
        if (isset($validated['name']) || isset($validated['role'])) {
            $adjUpdate = [];
            if (isset($validated['name'])) {
                $adjUpdate['created_by'] = $validated['name'];
            }
            if (isset($validated['role'])) {
                $adjUpdate['user_role'] = $validated['role'];
            }
            if (!empty($adjUpdate)) {
                \App\Models\StockAdjustment::where('user_id', $user->id)
                    ->orWhere('created_by', $oldName)
                    ->update($adjUpdate);
                \App\Models\StockTransfer::where('user_id', $user->id)
                    ->orWhere('created_by', $oldName)
                    ->update($adjUpdate);
            }
        }

        $user->load(['roleModel', 'outlets']);
        $user->outlet_ids = $user->outlets->pluck('id')->toArray();

        return response()->json([
            'status' => 'success',
            'message' => 'User account updated successfully',
            'data' => $user,
        ]);
    }

    /**
     * Toggle the active status of a user account.
     */
    public function toggleStatus(Request $request, $id)
    {
        $user = User::with('roleModel')->find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found',
            ], 404);
        }

        $caller = User::resolveFromRequest($request);
        if ($user->isSuperAdmin() && (!$caller || !$caller->isSuperAdmin())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Only Super Admin can change the status of Super Admin accounts.',
            ], 403);
        }

        // Prevent deactivating the only active Super Admin
        $isCurrentlyActive = $user->isActive();
        if ($user->isSuperAdmin() && $isCurrentlyActive) {
            $otherActiveSuperAdmins = User::where('id', '!=', $user->id)
                ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
                ->where(function ($q) {
                    $q->whereRaw("LOWER(COALESCE(role, '')) = 'super admin'")
                      ->orWhereHas('roleModel', function ($rq) {
                          $rq->where('slug', 'super_admin')
                             ->whereRaw("LOWER(COALESCE(status, '')) = 'active'");
                      });
                })
                ->count();
            if ($otherActiveSuperAdmins === 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot deactivate the primary Super Admin account. At least one active Super Admin is required.',
                ], 422);
            }
        }

        $newStatus = $isCurrentlyActive ? 'Inactive' : 'Active';
        $user->status = $newStatus;
        if ($newStatus === 'Inactive') {
            $user->api_token = null;
            $user->api_token_created_at = null;
        }
        $user->save();

        $user->load(['roleModel', 'outlets']);
        $user->outlet_ids = $user->outlets->pluck('id')->toArray();

        return response()->json([
            'status' => 'success',
            'message' => "Staff member '{$user->name}' marked as {$newStatus}",
            'data' => $user,
        ]);
    }

    /**
     * Remove the specified user account.
     * Senior Accountant Safeguard: Protects against orphaned financial journals and broken audit trails.
     */
    public function destroy(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account not found',
            ], 404);
        }

        if ($user->isSuperAdmin() || ($user->roleModel && $user->roleModel->slug === 'super_admin')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Super Admin user cannot be deleted.',
            ], 422);
        }

        $caller = User::resolveFromRequest($request);
        if (!$caller || !$caller->isSuperAdmin()) {
            if ($user->roleModel && ($user->roleModel->slug === 'store_manager' || strtolower((string)$user->role) === 'store manager')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Only Super Admin can delete Store Manager accounts.',
                ], 403);
            }
        }

        // Senior Accountant Safeguard: Check operational & financial dependencies
        $salesCount = \App\Models\Sale::where('user_id', $user->id)->count();
        $purchasesCount = \App\Models\Purchase::where('user_id', $user->id)->count();
        $expensesCount = \App\Models\Expense::where('user_id', $user->id)->count();
        $registersCount = \App\Models\CashRegister::where('user_id', $user->id)->count();
        $adjustmentsCount = \App\Models\StockAdjustment::where('user_id', $user->id)->count();
        $totalDependencies = $salesCount + $purchasesCount + $expensesCount + $registersCount + $adjustmentsCount;

        if ($totalDependencies > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete staff account '{$user->name}'. This user has {$totalDependencies} linked operational & financial record(s) ({$salesCount} sales, {$purchasesCount} purchases, {$expensesCount} expenses, {$registersCount} cash shifts). Please deactivate the user account instead to preserve financial audit trails and ledger integrity.",
            ], 422);
        }

        $user->outlets()->detach();
        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'User account deleted successfully',
        ]);
    }
}
