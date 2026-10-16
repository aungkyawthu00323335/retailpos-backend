<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Processing;
use App\Models\ProcessingItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Setting;
use App\Services\FifoInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessingController extends Controller
{
    protected FifoInventoryService $fifoService;

    public function __construct(FifoInventoryService $fifoService)
    {
        $this->fifoService = $fifoService;
    }

    /**
     * Generate reference code for new processing
     */
    public function generateCode()
    {
        $prefix = Setting::get('processing_prefix', 'PR_');
        $dateStr = date('Ymd');
        $count = Processing::whereDate('created_at', today())->count() + 1;
        $code = sprintf('%s%s-%04d', $prefix, $dateStr, $count);

        while (Processing::where('reference_no', $code)->exists()) {
            $count++;
            $code = sprintf('%s%s-%04d', $prefix, $dateStr, $count);
        }

        return response()->json([
            'status' => 'success',
            'code' => $code,
        ]);
    }

    /**
     * List all processing records
     */
    public function index(Request $request)
    {
        $query = Processing::with(['items', 'outputProduct', 'user', 'warehouse']);

        if ($request->filled('warehouse_id') && $request->warehouse_id !== 'All') {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('output_product_name', 'like', "%{$search}%")
                  ->orWhere('output_product_code', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhereHas('items', function ($iq) use ($search) {
                      $iq->where('product_name', 'like', "%{$search}%")
                         ->orWhere('product_code', 'like', "%{$search}%");
                  });
            });
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'reference_no', 'date', 'output_product_name', 'output_quantity', 'total_cost', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortOrder);

        $perPage = min(max((int)$request->input('per_page', 25), 1), 500);
        $processings = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $processings->items(),
            'pagination' => [
                'current_page' => $processings->currentPage(),
                'per_page' => $processings->perPage(),
                'total' => $processings->total(),
                'last_page' => $processings->lastPage(),
            ],
        ]);
    }

    /**
     * Store new processing record
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:255|unique:processings,reference_no',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            
            // Output Product
            'output_product_id' => 'required|integer',
            'output_variant_id' => 'nullable|integer',
            'output_product_code' => 'required|string|max:255',
            'output_product_name' => 'required|string|max:255',
            'output_variant_name' => 'nullable|string|max:255',
            'output_unit_id' => 'nullable|integer',
            'output_unit_name' => 'required|string|max:100',
            'output_conversion_rate' => 'nullable|numeric|min:0.0001',
            'output_quantity' => 'required|numeric|min:0.0001',
            'output_unit_cost' => 'nullable|numeric|min:0',

            // Inputs / Raw materials
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string|max:255',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.variant_name' => 'nullable|string|max:255',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_name' => 'nullable|string|max:100',
            'items.*.conversion_rate' => 'nullable|numeric|min:0.0001',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.subtotal_cost' => 'nullable|numeric|min:0',

            // Financial Summary
            'additional_cost' => 'nullable|numeric|min:0',
            'total_cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:completed,draft',
            'notes' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
        ]);

        $warehouseId = $this->resolveWarehouseId($validated['warehouse_id'] ?? null, $validated['warehouse_name']);
        $status = $validated['status'] ?? 'completed';

        // 1. Senior Accounting Stock Verification:
        // Ensure warehouse has sufficient stock for all input items before proceeding!
        $inputsByItem = [];
        foreach ($validated['items'] as $item) {
            $pid = (int)$item['product_id'];
            $vid = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
            $key = $pid . '_' . ($vid ? "var_{$vid}" : 'single');
            $cRate = isset($item['conversion_rate']) ? (float)$item['conversion_rate'] : 1.0;
            $qty = (float)$item['quantity'];
            $baseQty = $qty * ($cRate > 0 ? $cRate : 1.0);

            if (!isset($inputsByItem[$key])) {
                $inputsByItem[$key] = [
                    'product_id' => $pid,
                    'variant_id' => $vid,
                    'product_name' => $item['product_name'],
                    'needed_base_qty' => 0.0,
                ];
            }
            $inputsByItem[$key]['needed_base_qty'] += $baseQty;
        }

        if ($status === 'completed') {
            foreach ($inputsByItem as $reqItem) {
                $pid = $reqItem['product_id'];
                $vid = $reqItem['variant_id'];
                $needed = $reqItem['needed_base_qty'];

                $balQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $pid);
                if ($vid && $vid > 0) {
                    $balQuery->where('product_variant_id', $vid);
                } else {
                    $balQuery->whereNull('product_variant_id');
                }
                $curBal = (float)($balQuery->value('quantity') ?? 0.0);

                if ($needed > ($curBal + 0.0001)) {
                    $pName = $reqItem['product_name'];
                    $outletObj = Outlet::find($warehouseId);
                    $outletName = $outletObj ? $outletObj->name : $validated['warehouse_name'];
                    return response()->json([
                        'status' => 'error',
                        'message' => "လက်ကျန်စတော့ မလုံလောက်ပါ: '{$outletName}' တွင် '{$pName}' စုစုပေါင်း လက်ကျန် {$curBal} သာရှိသော်လည်း ထုတ်လုပ်ရန်အတွက် {$needed} အသုံးပြုရန် တောင်းဆိုထားပါသည်။",
                    ], 422);
                }
            }
        }

        DB::beginTransaction();
        try {
            $prefix = Setting::get('processing_prefix', 'PR_');
            $refNo = !empty($validated['reference_no'])
                ? $validated['reference_no']
                : ($prefix . date('Ymd') . '-' . rand(1000, 9999));

            $outputQty = (float)$validated['output_quantity'];
            $outputRate = (float)($validated['output_conversion_rate'] ?? 1.0);
            if ($outputRate <= 0) $outputRate = 1.0;
            $outputBaseQty = $outputQty * $outputRate;

            $totalInputCost = 0.0;
            foreach ($validated['items'] as $item) {
                $qty = (float)$item['quantity'];
                $cost = (float)($item['unit_cost'] ?? 0.0);
                $sub = isset($item['subtotal_cost']) && (float)$item['subtotal_cost'] > 0
                    ? (float)$item['subtotal_cost']
                    : ($qty * $cost);
                $totalInputCost += $sub;
            }

            $additionalCost = (float)($validated['additional_cost'] ?? 0.0);
            $totalCost = $totalInputCost + $additionalCost;
            $outputUnitCost = $outputQty > 0 ? round($totalCost / $outputQty, 4) : 0.0;

            $targetUser = $request->user();
            if (!$targetUser && !empty($validated['user_id'])) {
                $targetUser = User::find($validated['user_id']);
            }
            if (!$targetUser) {
                $targetUser = User::first();
            }

            $createdByName = !empty($validated['user_name'])
                ? $validated['user_name']
                : ($targetUser ? $targetUser->name : 'Super Admin');
            $userRole = $targetUser ? ($targetUser->roleModel?->name ?? $targetUser->role ?? 'Super Admin') : 'Super Admin';
            $userId = !empty($validated['user_id']) ? $validated['user_id'] : ($targetUser ? $targetUser->id : null);

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $processing = Processing::create([
                'reference_no' => $refNo,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $txDate,
                
                // Output Product
                'output_product_id' => $validated['output_product_id'],
                'output_variant_id' => $validated['output_variant_id'] ?? null,
                'output_product_code' => $validated['output_product_code'],
                'output_product_name' => $validated['output_product_name'],
                'output_variant_name' => $validated['output_variant_name'] ?? null,
                'output_unit_id' => $validated['output_unit_id'] ?? null,
                'output_unit_name' => $validated['output_unit_name'],
                'output_conversion_rate' => $outputRate,
                'output_quantity' => $outputQty,
                'output_base_quantity' => $outputBaseQty,
                'output_unit_cost' => $outputUnitCost,

                // Financials
                'total_input_cost' => $totalInputCost,
                'additional_cost' => $additionalCost,
                'total_cost' => $totalCost,

                'status' => $status,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $userId,
                'user_name' => $createdByName,
                'created_by' => $createdByName,
                'user_role' => $userRole,
            ]);

            // Create item records
            foreach ($validated['items'] as $itemData) {
                $cRate = (float)($itemData['conversion_rate'] ?? 1.0);
                if ($cRate <= 0) $cRate = 1.0;
                $qty = (float)$itemData['quantity'];
                $baseQty = $qty * $cRate;
                $unitCost = (float)($itemData['unit_cost'] ?? 0.0);
                $baseUnitCost = $cRate > 0 ? ($unitCost / $cRate) : $unitCost;
                $subtotal = isset($itemData['subtotal_cost']) && (float)$itemData['subtotal_cost'] > 0
                    ? (float)$itemData['subtotal_cost']
                    : ($qty * $unitCost);

                $item = ProcessingItem::create([
                    'processing_id' => $processing->id,
                    'product_id' => $itemData['product_id'],
                    'product_variant_id' => $itemData['product_variant_id'] ?? null,
                    'product_code' => $itemData['product_code'],
                    'product_name' => $itemData['product_name'],
                    'variant_name' => $itemData['variant_name'] ?? null,
                    'unit_id' => $itemData['unit_id'] ?? null,
                    'unit_name' => $itemData['unit_name'] ?? 'Piece',
                    'conversion_rate' => $cRate,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'unit_cost' => $unitCost,
                    'base_unit_cost' => $baseUnitCost,
                    'subtotal_cost' => $subtotal,
                ]);

                // If completed, deduct input raw material stock & deplete FIFO
                if ($status === 'completed') {
                    $this->deductInputStock(
                        $item,
                        $warehouseId,
                        $processing->id,
                        $refNo,
                        $userId
                    );
                }
            }

            // If completed, increment finished good stock & record new FIFO batch
            if ($status === 'completed') {
                $this->addOutputStock(
                    $processing,
                    $warehouseId,
                    $userId
                );
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Processing completed successfully',
                'data' => $processing->load('items'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("ProcessingController store error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show single processing record
     */
    public function show($id)
    {
        $processing = Processing::with(['items', 'outputProduct', 'user', 'warehouse'])->find($id);
        if (!$processing) {
            return response()->json(['status' => 'error', 'message' => 'Processing record not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $processing,
        ]);
    }

    /**
     * Delete / Reverse processing record
     */
    public function destroy($id)
    {
        $processing = Processing::with('items')->find($id);
        if (!$processing) {
            return response()->json(['status' => 'error', 'message' => 'Processing record not found'], 404);
        }

        DB::beginTransaction();
        try {
            $warehouseId = $processing->warehouse_id ?: $this->resolveWarehouseId(null, $processing->warehouse_name);

            if ($processing->status === 'completed') {
                // Verify output stock is still available to reverse (accounting safety)
                $outBalQuery = StockBalance::where('warehouse_id', $warehouseId)
                    ->where('product_id', $processing->output_product_id);
                if ($processing->output_variant_id) {
                    $outBalQuery->where('product_variant_id', $processing->output_variant_id);
                } else {
                    $outBalQuery->whereNull('product_variant_id');
                }
                $curOutBal = (float)($outBalQuery->value('quantity') ?? 0.0);

                if ($curOutBal < ($processing->output_base_quantity - 0.0001)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "ထုတ်လုပ်ထားသော ပစ္စည်းများ ရောင်းချပြီးဖြစ်သဖြင့် Processing စာရင်းကို ဖျက်၍မရပါ (လက်ကျန် {$curOutBal} < ပြန်နုတ်ရမည့် {$processing->output_base_quantity})",
                    ], 422);
                }

                // 1. Deduct output finished good
                $outBalQuery->decrement('quantity', $processing->output_base_quantity);
                $outProd = Product::find($processing->output_product_id);
                if ($outProd) {
                    $outProd->decrement('stock_quantity', $processing->output_base_quantity);
                }

                StockMovement::create([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $processing->output_product_id,
                    'product_variant_id' => $processing->output_variant_id,
                    'movement_type' => 'processing_rollback_out',
                    'reference_type' => 'processing',
                    'reference_id' => $processing->id,
                    'quantity_in' => 0,
                    'quantity_out' => $processing->output_base_quantity,
                    'balance_after' => (float)$outBalQuery->value('quantity'),
                    'created_by' => auth()->id() ?: 1,
                    'note' => "Processing Deleted Rollback ({$processing->reference_no})",
                    'created_at' => now(),
                ]);

                // 2. Refund input materials
                foreach ($processing->items as $item) {
                    $inBalQuery = StockBalance::where('warehouse_id', $warehouseId)
                        ->where('product_id', $item->product_id);
                    if ($item->product_variant_id) {
                        $inBalQuery->where('product_variant_id', $item->product_variant_id);
                    } else {
                        $inBalQuery->whereNull('product_variant_id');
                    }
                    $inBal = $inBalQuery->first();
                    if ($inBal) {
                        $inBal->increment('quantity', $item->base_quantity);
                    } else {
                        $inBal = StockBalance::create([
                            'warehouse_id' => $warehouseId,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity' => $item->base_quantity,
                        ]);
                    }

                    $inProd = Product::find($item->product_id);
                    if ($inProd) {
                        $inProd->increment('stock_quantity', $item->base_quantity);
                    }

                    StockMovement::create([
                        'warehouse_id' => $warehouseId,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'movement_type' => 'processing_rollback_in',
                        'reference_type' => 'processing',
                        'reference_id' => $processing->id,
                        'quantity_in' => $item->base_quantity,
                        'quantity_out' => 0,
                        'balance_after' => (float)$inBal->quantity,
                        'created_by' => auth()->id() ?: 1,
                        'note' => "Processing Deleted Rollback Raw Material ({$processing->reference_no})",
                        'created_at' => now(),
                    ]);
                }
            }

            $processing->items()->delete();
            $processing->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Processing deleted and stock reversed successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("ProcessingController destroy error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Deduct input raw material stock and record movement
     */
    private function deductInputStock(
        ProcessingItem $item,
        int $warehouseId,
        int $processingId,
        string $refNo,
        ?int $userId
    ): void {
        $pid = $item->product_id;
        $vid = $item->product_variant_id;
        $baseQty = (float)$item->base_quantity;

        $balQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $pid);
        if ($vid && $vid > 0) {
            $balQuery->where('product_variant_id', $vid);
        } else {
            $balQuery->whereNull('product_variant_id');
        }

        $balance = $balQuery->first();
        if ($balance) {
            $balance->decrement('quantity', $baseQty);
            $balance->refresh();
        }

        $prod = Product::find($pid);
        if ($prod) {
            $prod->decrement('stock_quantity', $baseQty);
        }

        StockMovement::create([
            'warehouse_id' => $warehouseId,
            'product_id' => $pid,
            'product_variant_id' => $vid,
            'movement_type' => 'processing_out',
            'reference_type' => 'processing',
            'reference_id' => $processingId,
            'quantity_in' => 0,
            'quantity_out' => $baseQty,
            'balance_after' => (float)($balance?->quantity ?? 0.0),
            'created_by' => $userId ?: (auth()->id() ?: 1),
            'note' => "Processing Raw Material Used ({$refNo})",
            'created_at' => now(),
        ]);
    }

    /**
     * Add output finished good stock and create new FIFO batch
     */
    private function addOutputStock(
        Processing $processing,
        int $warehouseId,
        ?int $userId
    ): void {
        $pid = $processing->output_product_id;
        $vid = $processing->output_variant_id;
        $baseQty = (float)$processing->output_base_quantity;
        $baseUnitCost = $processing->output_conversion_rate > 0
            ? ($processing->output_unit_cost / $processing->output_conversion_rate)
            : $processing->output_unit_cost;

        $balQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $pid);
        if ($vid && $vid > 0) {
            $balQuery->where('product_variant_id', $vid);
        } else {
            $balQuery->whereNull('product_variant_id');
        }

        $balance = $balQuery->first();
        if ($balance) {
            $balance->increment('quantity', $baseQty);
            $balance->refresh();
        } else {
            $balance = StockBalance::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $pid,
                'product_variant_id' => $vid,
                'quantity' => $baseQty,
            ]);
        }

        $prod = Product::find($pid);
        if ($prod) {
            $prod->increment('stock_quantity', $baseQty);
        }

        StockMovement::create([
            'warehouse_id' => $warehouseId,
            'product_id' => $pid,
            'product_variant_id' => $vid,
            'movement_type' => 'processing_in',
            'reference_type' => 'processing',
            'reference_id' => $processing->id,
            'quantity_in' => $baseQty,
            'quantity_out' => 0,
            'balance_after' => (float)$balance->quantity,
            'created_by' => $userId ?: (auth()->id() ?: 1),
            'note' => "Processing Finished Good ({$processing->reference_no})",
            'created_at' => now(),
        ]);

        // Record FIFO batch for the finished good
        try {
            $this->fifoService->recordBatch(
                $warehouseId,
                $pid,
                $vid,
                $baseQty,
                $baseUnitCost,
                'processing',
                $processing->reference_no,
                $processing->date,
                null,
                $userId ?: (auth()->id() ?: 1),
                "Processing finished good ({$processing->reference_no})"
            );
        } catch (\Throwable $e) {
            Log::warning("FIFO batch record for processing failed: " . $e->getMessage());
        }
    }

    private function resolveWarehouseId(?int $warehouseId, string $warehouseName): int
    {
        if ($warehouseId && $warehouseId > 0) {
            $w = Outlet::find($warehouseId);
            if ($w) return $w->id;
        }

        if (!empty($warehouseName)) {
            $w = Outlet::where('name', $warehouseName)->first();
            if ($w) return $w->id;
        }

        $fallback = Outlet::first();
        return $fallback ? $fallback->id : 1;
    }
}
