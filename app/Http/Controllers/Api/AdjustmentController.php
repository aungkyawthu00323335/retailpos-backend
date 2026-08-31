<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdjustmentController extends Controller
{
    public function index(Request $request)
    {
        $query = StockAdjustment::with('items');

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('type') && $request->input('type') !== 'All') {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $adjustments = $query->orderBy('id', 'desc')->paginate((int)$request->input('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $adjustments->items(),
            'pagination' => [
                'current_page' => $adjustments->currentPage(),
                'per_page' => $adjustments->perPage(),
                'total' => $adjustments->total(),
                'last_page' => $adjustments->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'type' => 'required|string|in:Addition,Subtraction',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.type' => 'nullable|string|in:Addition,Subtraction',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $refNo = !empty($validated['reference_no']) 
                ? $validated['reference_no'] 
                : ('ADJ-' . date('Ymd') . '-' . rand(1000, 9999));
            $totalQty = 0;

            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $adjustment = StockAdjustment::create([
                'reference_no' => $refNo,
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $validated['date'],
                'total_products' => count($validated['items']),
                'total_quantity' => $totalQty,
                'type' => $validated['type'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $validated['created_by'] ?? 'Admin User',
            ]);

            foreach ($validated['items'] as $item) {
                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'quantity' => (float)$item['quantity'],
                    'type' => $item['type'] ?? $validated['type'],
                    'unit_cost' => (float)($item['unit_cost'] ?? 0),
                ]);

                // Update product stock
                $prod = !empty($item['product_id']) ? Product::find($item['product_id']) : Product::where('code', $item['product_code'])->first();
                if ($prod) {
                    $itemType = $item['type'] ?? $validated['type'];
                    if ($itemType === 'Addition') {
                        $prod->increment('stock_quantity', (float)$item['quantity']);
                    } else {
                        $prod->decrement('stock_quantity', (float)$item['quantity']);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment saved successfully',
                'data' => $adjustment->load('items'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $adjustment]);
    }

    public function update(Request $request, $id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }

        $validated = $request->validate([
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'type' => 'nullable|string|in:Addition,Subtraction',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.type' => 'nullable|string|in:Addition,Subtraction',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($adjustment->items as $oldItem) {
                $prod = !empty($oldItem->product_id) ? Product::find($oldItem->product_id) : Product::where('code', $oldItem->product_code)->first();
                if ($prod) {
                    if ($oldItem->type === 'Addition') {
                        $prod->decrement('stock_quantity', (float)$oldItem->quantity);
                    } else {
                        $prod->increment('stock_quantity', (float)$oldItem->quantity);
                    }
                }
            }
            $adjustment->items()->delete();

            $totalQty = 0;
            foreach ($validated['items'] as $item) {
                $totalQty += (float)$item['quantity'];
            }

            $adjustment->update([
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $validated['date'],
                'total_products' => count($validated['items']),
                'total_quantity' => $totalQty,
                'type' => $validated['type'] ?? $adjustment->type,
                'notes' => $validated['notes'] ?? $adjustment->notes,
                'created_by' => $validated['created_by'] ?? $adjustment->created_by,
            ]);

            foreach ($validated['items'] as $item) {
                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'quantity' => (float)$item['quantity'],
                    'type' => $item['type'] ?? $adjustment->type,
                    'unit_cost' => (float)($item['unit_cost'] ?? 0),
                ]);

                $prod = !empty($item['product_id']) ? Product::find($item['product_id']) : Product::where('code', $item['product_code'])->first();
                if ($prod) {
                    $itemType = $item['type'] ?? $adjustment->type;
                    if ($itemType === 'Addition') {
                        $prod->increment('stock_quantity', (float)$item['quantity']);
                    } else {
                        $prod->decrement('stock_quantity', (float)$item['quantity']);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Stock adjustment updated successfully',
                'data' => $adjustment->load('items'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $adjustment = StockAdjustment::with('items')->find($id);
        if (!$adjustment) {
            return response()->json(['status' => 'error', 'message' => 'Adjustment not found'], 404);
        }

        DB::beginTransaction();
        try {
            foreach ($adjustment->items as $oldItem) {
                $prod = !empty($oldItem->product_id) ? Product::find($oldItem->product_id) : Product::where('code', $oldItem->product_code)->first();
                if ($prod) {
                    if ($oldItem->type === 'Addition') {
                        $prod->decrement('stock_quantity', (float)$oldItem->quantity);
                    } else {
                        $prod->increment('stock_quantity', (float)$oldItem->quantity);
                    }
                }
            }

            $adjustment->items()->delete();
            $adjustment->delete();
            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Stock adjustment deleted']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
