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

        // Fast Cache hit if unfiltered and clean array
        if (!$hasFilters && Cache::has(self::CACHE_KEY)) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && !empty($cached) && !isset($cached['__PHP_Incomplete_Class_Name'])) {
                return response()->json([
                    'status' => 'success',
                    'data' => $cached,
                ], 200, ['X-Cache' => 'HIT']);
            }
            Cache::forget(self::CACHE_KEY);
        }

        $query = PaymentMethod::query()->select([
            'id', 'name', 'code', 'icon', 'qr_code_image', 'is_active', 'is_default', 'description', 
            'account_number', 'account_name', 'is_bot_available', 'created_at', 'updated_at'
        ]);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('account_number', 'like', "%{$search}%")
                  ->orWhere('account_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $isActive = $request->input('status') === 'Active';
            $query->where('is_active', $isActive);
        }

        if ($request->has('bot_only') && $request->boolean('bot_only')) {
            $query->where('is_bot_available', true);
        }

        $methods = $query->orderBy('id', 'desc')->get();
        $methodsArray = $methods->toArray();

        if (!$hasFilters && !$request->has('bot_only')) {
            Cache::put(self::CACHE_KEY, $methodsArray, 3600);
        }

        return response()->json([
            'status' => 'success',
            'data' => $methodsArray,
        ], 200, ['X-Cache' => 'MISS']);
    }

    /**
     * Upload QR Code image for Payment Method
     */
    public function uploadQrImage(Request $request)
    {
        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
        ]);

        $file = $request->file('image');
        $path = $file->store('payment_methods', 'public');
        $baseHost = $request->getSchemeAndHttpHost() ?: config('app.url');
        $url = rtrim($baseHost, '/') . '/storage/' . ltrim($path, '/');

        return response()->json([
            'status' => 'success',
            'message' => 'QR Code image uploaded successfully',
            'data' => [
                'path' => $path,
                'url' => $url,
            ],
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:payment_methods,name',
            'code' => 'nullable|string|max:100',
            'icon' => 'nullable|string|max:100',
            'qr_code_image' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'description' => 'nullable|string',
            'account_number' => 'nullable|string|max:255',
            'account_name' => 'nullable|string|max:255',
            'is_bot_available' => 'nullable|boolean',
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
                'qr_code_image' => $validated['qr_code_image'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'is_default' => $isDefault,
                'description' => $validated['description'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
                'account_name' => $validated['account_name'] ?? null,
                'is_bot_available' => $validated['is_bot_available'] ?? true,
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
            'qr_code_image' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'description' => 'nullable|string',
            'account_number' => 'nullable|string|max:255',
            'account_name' => 'nullable|string|max:255',
            'is_bot_available' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($method, $validated) {
            if (isset($validated['is_default']) && $validated['is_default']) {
                PaymentMethod::where('id', '!=', $method->id)->update(['is_default' => false]);
            }
            $method->update($validated);
        });

        // Instant Cache Invalidation: Invalidate API cache and Telegram Bot file_id cache
        Cache::forget(self::CACHE_KEY);
        Cache::forget("tg_pm_file_id_{$id}");

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

    public function toggleBot($id)
    {
        $method = PaymentMethod::find($id);
        if (!$method) {
            return response()->json(['status' => 'error', 'message' => 'Payment method not found'], 404);
        }

        $method->is_bot_available = !$method->is_bot_available;
        $method->save();

        Cache::forget(self::CACHE_KEY);

        return response()->json([
            'status' => 'success',
            'message' => 'Telegram Bot availability updated to ' . ($method->is_bot_available ? 'Available' : 'Disabled'),
            'data' => $method,
        ]);
    }

    public function destroy($id)
    {
        $method = PaymentMethod::find($id);
        if (!$method) {
            return response()->json(['status' => 'error', 'message' => 'Payment method not found'], 404);
        }

        if ($method->is_default) {
            return response()->json([
                'status' => 'error',
                'message' => 'Default payment method cannot be deleted. Please set another payment method as default first.',
            ], 422);
        }

        $method->delete();

        Cache::forget(self::CACHE_KEY);

        return response()->json(['status' => 'success', 'message' => 'Payment method deleted']);
    }
}
