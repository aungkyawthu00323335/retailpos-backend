<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    /**
     * Generate next numeric transfer reference code (numbers only).
     */
    public function generateCode()
    {
        $transfers = StockTransfer::select('reference_no')->orderBy('id', 'desc')->take(200)->get();
        $maxNum = 100000;
        foreach ($transfers as $trf) {
            $digits = preg_replace('/\D/', '', (string)$trf->reference_no);
            if (!empty($digits)) {
                $val = (int)$digits;
                if ($val >= 100000 && $val < 999999999 && $val > $maxNum) {
                    $maxNum = $val;
                }
            }
        }
        $nextCode = (string)($maxNum + 1);

        return response()->json([
            'status' => 'success',
            'data' => [
                'code' => $nextCode,
                'reference_no' => $nextCode,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = StockTransfer::with('items');

        if ($request->filled('from_warehouse') && $request->input('from_warehouse') !== 'All') {
            $query->where('from_warehouse', $request->input('from_warehouse'));
        }

        if ($request->filled('to_warehouse') && $request->input('to_warehouse') !== 'All') {
            $query->where('to_warehouse', $request->input('to_warehouse'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('created_by') && $request->input('created_by') !== 'All') {
            $query->where('created_by', $request->input('created_by'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('from_warehouse', 'like', "%{$search}%")
                  ->orWhere('to_warehouse', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%");
            });
        }

        $transfers = $query->orderBy('id', 'desc')->paginate((int)$request->input('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $transfers->items(),
            'pagination' => [
                'current_page' => $transfers->currentPage(),
                'per_page' => $transfers->perPage(),
                'total' => $transfers->total(),
                'last_page' => $transfers->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $transfer = StockTransfer::with('items')->find($id);
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $transfer]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:255',
            'from_warehouse_id' => 'nullable|integer',
            'from_warehouse' => 'required|string|max:255',
            'to_warehouse_id' => 'nullable|integer',
            'to_warehouse' => 'required|string|max:255|different:from_warehouse',
            'date' => 'required|string',
            'transfer_time' => 'nullable|string|max:50',
            'shipping_charge' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:Sent,Pending,Completed',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.variant_name' => 'nullable|string|max:255',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|min:0.0001',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.base_unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $refNo = !empty($validated['reference_no'])
                ? $validated['reference_no']
                : (date('Ymd') . rand(1000, 9999));
            $totalQty = 0;

            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $transfer = StockTransfer::create([
                'reference_no' => $refNo,
                'from_warehouse_id' => $validated['from_warehouse_id'] ?? null,
                'from_warehouse' => $validated['from_warehouse'],
                'to_warehouse_id' => $validated['to_warehouse_id'] ?? null,
                'to_warehouse' => $validated['to_warehouse'],
                'date' => $txDate,
                'transfer_time' => $validated['transfer_time'] ?? date('h:i A'),
                'items_count' => count($validated['items']),
                'total_quantity' => $totalQty,
                'shipping_charge' => (float)($validated['shipping_charge'] ?? 0),
                'status' => $validated['status'] ?? 'Completed',
                'notes' => $validated['notes'] ?? null,
                'created_by' => $validated['created_by'] ?? ($validated['user_name'] ?? 'Admin User'),
                'user_id' => $validated['user_id'] ?? null,
                'user_name' => $validated['user_name'] ?? null,
                'user_role' => $validated['user_role'] ?? 'Super Admin',
            ]);

            foreach ($validated['items'] as $item) {
                $convRate = (float)($item['conversion_rate'] ?? 1.0);
                $qty = (float)$item['quantity'];
                $baseQty = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                    ? (float)$item['base_quantity']
                    : ($qty * $convRate);
                $unitCost = (float)($item['unit_cost'] ?? 0);
                $baseCost = isset($item['base_unit_cost']) && (float)$item['base_unit_cost'] > 0
                    ? (float)$item['base_unit_cost']
                    : ($convRate > 0 ? $unitCost / $convRate : $unitCost);

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'variant_name' => $item['variant_name'] ?? null,
                    'unit_id' => $item['unit_id'] ?? null,
                    'unit_name' => $item['unit_name'] ?? 'Pcs',
                    'conversion_rate' => $convRate,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'unit_cost' => $unitCost,
                    'base_unit_cost' => $baseCost,
                    'subtotal' => (float)($item['subtotal'] ?? ($qty * $unitCost)),
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock transfer recorded successfully',
                'data' => $transfer->load('items'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $transfer = StockTransfer::with('items')->find($id);
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }

        $validated = $request->validate([
            'from_warehouse_id' => 'nullable|integer',
            'from_warehouse' => 'required|string|max:255',
            'to_warehouse_id' => 'nullable|integer',
            'to_warehouse' => 'required|string|max:255|different:from_warehouse',
            'date' => 'required|string',
            'transfer_time' => 'nullable|string|max:50',
            'shipping_charge' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:Sent,Pending,Completed',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
            'user_role' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.variant_name' => 'nullable|string|max:255',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|min:0.0001',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.base_quantity' => 'nullable|numeric|min:0',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.base_unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $transfer->items()->delete();

            $totalQty = 0;
            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $transfer->update([
                'from_warehouse_id' => $validated['from_warehouse_id'] ?? $transfer->from_warehouse_id,
                'from_warehouse' => $validated['from_warehouse'],
                'to_warehouse_id' => $validated['to_warehouse_id'] ?? $transfer->to_warehouse_id,
                'to_warehouse' => $validated['to_warehouse'],
                'date' => $validated['date'],
                'transfer_time' => $validated['transfer_time'] ?? $transfer->transfer_time,
                'items_count' => count($validated['items']),
                'total_quantity' => $totalQty,
                'shipping_charge' => (float)($validated['shipping_charge'] ?? 0),
                'status' => $validated['status'] ?? $transfer->status,
                'notes' => $validated['notes'] ?? $transfer->notes,
                'created_by' => $validated['created_by'] ?? $transfer->created_by,
                'user_id' => $validated['user_id'] ?? $transfer->user_id,
                'user_name' => $validated['user_name'] ?? $transfer->user_name,
                'user_role' => $validated['user_role'] ?? $transfer->user_role,
            ]);

            foreach ($validated['items'] as $item) {
                $convRate = (float)($item['conversion_rate'] ?? 1.0);
                $qty = (float)$item['quantity'];
                $baseQty = isset($item['base_quantity']) && (float)$item['base_quantity'] > 0
                    ? (float)$item['base_quantity']
                    : ($qty * $convRate);
                $unitCost = (float)($item['unit_cost'] ?? 0);
                $baseCost = isset($item['base_unit_cost']) && (float)$item['base_unit_cost'] > 0
                    ? (float)$item['base_unit_cost']
                    : ($convRate > 0 ? $unitCost / $convRate : $unitCost);

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'variant_name' => $item['variant_name'] ?? null,
                    'unit_id' => $item['unit_id'] ?? null,
                    'unit_name' => $item['unit_name'] ?? 'Pcs',
                    'conversion_rate' => $convRate,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'unit_cost' => $unitCost,
                    'base_unit_cost' => $baseCost,
                    'subtotal' => (float)($item['subtotal'] ?? ($qty * $unitCost)),
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock transfer updated successfully',
                'data' => $transfer->load('items'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $transfer = StockTransfer::find($id);
        if (!$transfer) {
            return response()->json(['status' => 'error', 'message' => 'Transfer not found'], 404);
        }
        $transfer->items()->delete();
        $transfer->delete();
        return response()->json(['status' => 'success', 'message' => 'Transfer deleted successfully']);
    }
}
