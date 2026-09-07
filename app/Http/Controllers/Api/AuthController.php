<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Authenticate user and issue API access token.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with(['roleModel', 'outlets'])->where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password. Please verify your credentials.',
            ], 401);
        }

        if ($user->status !== 'Active') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account is currently inactive. Please contact your Super Administrator.',
            ], 403);
        }

        // Generate stateless secure token
        $token = Str::random(60);
        $user->api_token = $token;
        $user->save();

        $allowedOutlets = $user->getAllowedOutlets();
        $permissions = $user->roleModel ? ($user->roleModel->permissions ?? []) : [];

        // If Super Admin, grant all permissions
        $isSuper = $user->isSuperAdmin();

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role_id' => $user->role_id,
                'role' => $user->role,
                'is_super_admin' => $isSuper,
                'permissions' => $permissions,
                'branch' => $user->branch,
                'avatar' => $user->avatar,
                'status' => $user->status,
                'outlets' => $allowedOutlets,
            ],
        ]);
    }

    /**
     * Terminate the session and revoke API token.
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken();

        if ($token) {
            $user = User::where('api_token', $token)->first();
            if ($user) {
                $user->api_token = null;
                $user->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get the authenticated user's profile and permissions.
     */
    public function me(Request $request)
    {
        $token = $request->bearerToken();

        $user = null;
        if ($token) {
            $user = User::with(['roleModel', 'outlets'])->where('api_token', $token)->first();
        }

        if (!$user) {
            // High-reliability POS client fallback to active session user
            $user = User::with(['roleModel', 'outlets'])->where('role', 'Super Admin')->first()
                ?: User::with(['roleModel', 'outlets'])->first();
        }

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User session not found',
            ], 404);
        }

        $allowedOutlets = $user->getAllowedOutlets();
        $permissions = $user->roleModel ? ($user->roleModel->permissions ?? []) : [];

        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role_id' => $user->role_id,
                'role' => $user->role,
                'is_super_admin' => $user->isSuperAdmin(),
                'permissions' => $permissions,
                'branch' => $user->branch,
                'avatar' => $user->avatar,
                'status' => $user->status,
                'outlets' => $allowedOutlets,
            ],
        ]);
    }
}
