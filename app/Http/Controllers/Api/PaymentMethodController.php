<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentMethodController extends Controller
{
    private const CACHE_KEY = 'payment_methods_list_all';

    public function index(Request $request)
    {
        $hasFilters = $request->filled('search') || ($request->filled('status') && $request->input('status') !== 'All');

        // Fast Cache hit if unfiltered
        if (!$hasFilters && Cache::has(self::CACHE_KEY)) {
            return response()->json([
                'status' => 'success',
                'data' => Cache::get(self::CACHE_KEY),
            ], 200, ['X-Cache' => 'HIT']);
        }

        // Seed default methods if empty
        if (PaymentMethod::count() === 0) {
            $defaultMethods = [
                ['name' => 'Cash', 'code' => 'CASH', 'icon' => 'payments_rounded', 'is_active' => true, 'is_default' => true, 'description' => 'Direct cash currency exchange at counter'],
                ['name' => 'Cheque', 'code' => 'CHEQUE', 'icon' => 'receipt_long_rounded', 'is_active' => true, 'is_default' => false, 'description' => 'Commercial bank draft or order cheque'],
                ['name' => 'Bank Transfer', 'code' => 'BANK', 'icon' => 'account_balance_rounded', 'is_active' => true, 'is_default' => false, 'description' => 'Direct electronic banking transfer or invoice'],
                ['name' => 'Other', 'code' => 'OTHER', 'icon' => 'category_rounded', 'is_active' => true, 'is_default' => false, 'description' => 'Custom tender or gift voucher payment'],
                ['name' => 'Razorpay', 'code' => 'RAZORPAY', 'icon' => 'bolt_rounded', 'is_active' => true, 'is_default' => false, 'description' => 'Razorpay payment gateway integration'],
                ['name' => 'cred', 'code' => 'CRED', 'icon' => 'credit_card_rounded', 'is_active' => true, 'is_default' => false, 'description' => 'cred digital payment and credit rewards'],
                ['name' => 'Gpay', 'code' => 'GPAY', 'icon' => 'credit_card_rounded', 'is_active' => true, 'is_default' => false, 'description' => 'Google Pay mobile contactless payment'],
            ];

            PaymentMethod::insert(array_map(function ($method) {
                $now = now();
                $method['created_at'] = $now;
                $method['updated_at'] = $now;
                return $method;
            }, $defaultMethods));
        }

        $query = PaymentMethod::query()->select([
            'id', 'name', 'code', 'icon', 'is_active', 'is_default', 'description', 'created_at', 'updated_at'
        ]);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $isActive = $request->input('status') === 'Active';
            $query->where('is_active', $isActive);
        }

        $methods = $query->orderBy('id', 'desc')->get();

        if (!$hasFilters) {
            Cache::put(self::CACHE_KEY, $methods, 3600);
        }

        return response()->json([
            'status' => 'success',
            'data' => $methods,
        ], 200, ['X-Cache' => 'MISS']);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:payment_methods,name',
            'code' => 'nullable|string|max:100',
            'icon' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        $name = trim($validated['name']);
        $code = !empty($validated['code'])
            ? trim($validated['code'])
            : strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 8));

        $icon = $validated['icon'] ?? 'payments_rounded';
        if (empty($validated['icon'])) {
            $lower = strtolower($name);
            if (str_contains($lower, 'card') || str_contains($lower, 'visa') || str_contains($lower, 'master') || str_contains($lower, 'cred')) {
                $icon = 'credit_card_rounded';
            } elseif (str_contains($lower, 'bank') || str_contains($lower, 'transfer')) {
                $icon = 'account_balance_rounded';
            } elseif (str_contains($lower, 'kpay') || str_contains($lower, 'kbz') || str_contains($lower, 'qr')) {
                $icon = 'qr_code_scanner_rounded';
            } elseif (str_contains($lower, 'wave') || str_contains($lower, 'phone')) {
                $icon = 'phone_android_rounded';
            } elseif (str_contains($lower, 'razor') || str_contains($lower, 'stripe') || str_contains($lower, 'online')) {
                $icon = 'bolt_rounded';
            } elseif (str_contains($lower, 'cheque') || str_contains($lower, 'check')) {
                $icon = 'receipt_long_rounded';
            } elseif (str_contains($lower, 'paypal') || str_contains($lower, 'wallet')) {
                $icon = 'account_balance_wallet_rounded';
            }
        }

        $method = DB::transaction(function () use ($name, $code, $icon, $validated) {
            $isDefault = $validated['is_default'] ?? false;

            if ($isDefault) {
                PaymentMethod::where('is_default', true)->update(['is_default' => false]);
            }

            return PaymentMethod::create([
                'name' => $name,
                'code' => $code,
                'icon' => $icon,
                'is_active' => $validated['is_active'] ?? true,
                'is_default' => $isDefault,
                'description' => $validated['description'] ?? null,
            ]);
        });

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment method created successfully',
            'data' => $method,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $method = PaymentMethod::find($id);
        if (!$method) {
            return response()->json(['status' => 'error', 'message' => 'Payment method not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:payment_methods,name,' . $id,
            'code' => 'nullable|string|max:100',
            'icon' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        DB::transaction(function () use ($method, $validated) {
            if (isset($validated['is_default']) && $validated['is_default']) {
                PaymentMethod::where('id', '!=', $method->id)->update(['is_default' => false]);
            }
            $method->update($validated);
        });

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'status' => 'success',
            'message' => 'Payment method updated successfully',
            'data' => $method->fresh(),
        ]);
    }

    public function toggleStatus($id)
    {
        $method = PaymentMethod::find($id);
        if (!$method) {
            return response()->json(['status' => 'error', 'message' => 'Payment method not found'], 404);
        }

        $method->is_active = !$method->is_active;
        $method->save();

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'status' => 'success',
            'message' => 'Status updated to ' . ($method->is_active ? 'Active' : 'Inactive'),
            'data' => $method,
        ]);
    }

    public function destroy($id)
    {
        $method = PaymentMethod::find($id);
        if (!$method) {
            return response()->json(['status' => 'error', 'message' => 'Payment method not found'], 404);
        }
        $method->delete();

        Cache::forget(self::CACHE_KEY);

        return response()->json(['status' => 'success', 'message' => 'Payment method deleted']);
    }
}
