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

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('from_warehouse', 'like', "%{$search}%")
                  ->orWhere('to_warehouse', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
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
            'from_warehouse' => 'required|string|max:255',
            'to_warehouse' => 'required|string|max:255|different:from_warehouse',
            'date' => 'required|string',
            'shipping_charge' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:Sent,Pending,Completed',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $prefix = Setting::get('transfer_prefix', 'TR_');
            $refNo = !empty($validated['reference_no'])
                ? $validated['reference_no']
                : ($prefix . date('Ymd') . '-' . rand(1000, 9999));
            $totalQty = 0;

            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $transfer = StockTransfer::create([
                'reference_no' => $refNo,
                'from_warehouse' => $validated['from_warehouse'],
                'to_warehouse' => $validated['to_warehouse'],
                'date' => $validated['date'],
                'items_count' => count($validated['items']),
                'total_quantity' => $totalQty,
                'shipping_charge' => (float)($validated['shipping_charge'] ?? 0),
                'status' => $validated['status'] ?? 'Completed',
                'notes' => $validated['notes'] ?? null,
                'created_by' => $validated['created_by'] ?? 'Admin User',
            ]);

            foreach ($validated['items'] as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'quantity' => (float)$item['quantity'],
                    'unit_cost' => (float)($item['unit_cost'] ?? 0),
                    'subtotal' => (float)($item['subtotal'] ?? ($item['quantity'] * ($item['unit_cost'] ?? 0))),
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
            'from_warehouse' => 'required|string|max:255',
            'to_warehouse' => 'required|string|max:255|different:from_warehouse',
            'date' => 'required|string',
            'shipping_charge' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:Sent,Pending,Completed',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
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
                'from_warehouse' => $validated['from_warehouse'],
                'to_warehouse' => $validated['to_warehouse'],
                'date' => $validated['date'],
                'items_count' => count($validated['items']),
                'total_quantity' => $totalQty,
                'shipping_charge' => (float)($validated['shipping_charge'] ?? 0),
                'status' => $validated['status'] ?? $transfer->status,
                'notes' => $validated['notes'] ?? $transfer->notes,
                'created_by' => $validated['created_by'] ?? $transfer->created_by,
            ]);

            foreach ($validated['items'] as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'quantity' => (float)$item['quantity'],
                    'unit_cost' => (float)($item['unit_cost'] ?? 0),
                    'subtotal' => (float)($item['subtotal'] ?? ($item['quantity'] * ($item['unit_cost'] ?? 0))),
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
