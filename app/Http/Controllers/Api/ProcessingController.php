<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Processing;
use App\Models\ProcessingItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FifoInventoryService;
use App\Services\ProductUnitConversionService;
use App\Services\PurchaseAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProcessingController extends Controller
{
    protected FifoInventoryService $fifoService;
    protected PurchaseAccountingService $accounting;
    protected ProductUnitConversionService $unitService;

    public function __construct(
        FifoInventoryService $fifoService,
        PurchaseAccountingService $accounting,
        ProductUnitConversionService $unitService
    ) {
        $this->fifoService = $fifoService;
        $this->accounting = $accounting;
        $this->unitService = $unitService;
    }

    private function maskProcessingCostFields(?Processing $proc, bool $canViewCost): ?Processing
    {
        if (!$proc || $canViewCost) {
            return $proc;
        }
        $proc->makeHidden(['output_unit_cost', 'total_input_cost', 'additional_cost', 'total_cost']);
        if ($proc->relationLoaded('items')) {
            foreach ($proc->items as $item) {
                $item->makeHidden(['unit_cost', 'base_unit_cost', 'subtotal_cost']);
            }
        }
        return $proc;
    }

    private function clearProcessingCaches(): void
    {
        // Zero-cache mode: lists and reports always query live DB.
    }

    /**
     * Build outlet-scoped & filtered Processing query for both index() and export().
     */
    private function buildFilteredQuery(Request $request)
    {
        $query = Processing::with(['items', 'outputProduct', 'user', 'warehouse']);

        $allowedOutlets = $this->accounting->extractAllowedOutletIds($request);
        $this->accounting->applyOutletScope($query, $allowedOutlets, 'warehouse_id');

        if ($request->filled('warehouse_id') && $request->input('warehouse_id') !== 'All') {
            $query->where('warehouse_id', (int)$request->input('warehouse_id'));
        } elseif ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $whParam = trim((string)$request->input('warehouse'));
            if (is_numeric($whParam)) {
                $query->where('warehouse_id', (int)$whParam);
            } else {
                $query->where('warehouse_name', $whParam);
            }
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', strtolower(trim((string)$request->input('status'))));
        }

        $fromDate = $request->input('from_date') ?: ($request->input('start_date') ?: $request->input('date_from'));
        $toDate = $request->input('to_date') ?: ($request->input('end_date') ?: $request->input('date_to'));
        $this->accounting->applyDateRangeFilter($query, $fromDate, $toDate, 'date');

        if ($request->filled('search')) {
            $search = trim((string)$request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('output_product_name', 'like', "%{$search}%")
                  ->orWhere('output_product_code', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('items', function ($iq) use ($search) {
                      $iq->where('product_name', 'like', "%{$search}%")
                         ->orWhere('product_code', 'like', "%{$search}%");
                  });
            });
        }

        return $query;
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
        $query = $this->buildFilteredQuery($request);

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortMap = [
            'reference' => 'reference_no',
            'product' => 'output_product_name',
            'warehouse' => 'warehouse_name',
            'qty' => 'output_quantity',
            'cost' => 'total_cost',
        ];
        if (isset($sortMap[$sortBy])) {
            $sortBy = $sortMap[$sortBy];
        }
        $allowedSorts = ['id', 'reference_no', 'date', 'output_product_name', 'warehouse_name', 'output_quantity', 'total_cost', 'status', 'created_at'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'id') {
            $query->orderBy('id', $sortOrder);
        }

        $perPage = min(max((int)$request->input('per_page', 25), 1), 500);
        $processings = $query->paginate($perPage);
        $canViewCost = $this->accounting->canViewCost($request);
        $items = $processings->getCollection()->map(function ($proc) use ($canViewCost) {
            return $this->maskProcessingCostFields($proc, $canViewCost);
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $items,
            'pagination' => [
                'current_page' => $processings->currentPage(),
                'per_page' => $processings->perPage(),
                'total' => $processings->total(),
                'last_page' => $processings->lastPage(),
            ],
        ]);
    }

    /**
     * Stream filtered CSV export for Processings with UTF-8 BOM & formula escaping.
     */
    public function export(Request $request)
    {
        $query = $this->buildFilteredQuery($request);
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'reference_no', 'date', 'output_product_name', 'warehouse_name', 'output_quantity', 'total_cost', 'status', 'created_at'];
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortOrder);
        if ($sortBy !== 'id') {
            $query->orderBy('id', $sortOrder);
        }

        $canViewCost = $this->accounting->canViewCost($request);
        $currency = $this->accounting->resolveDefaultCurrencyCode();
        $warehouseFilter = $request->input('warehouse') ?: ($request->input('warehouse_id') ?: 'All');
        $statusFilter = $request->input('status', 'All');
        $fromDate = $request->input('from_date') ?: $request->input('start_date');
        $toDate = $request->input('to_date') ?: $request->input('end_date');
        $searchQuery = $request->input('search');
        $accounting = $this->accounting;

        $fileName = 'processings_export_' . date('Ymd_His') . '.csv';
        return response()->stream(function () use (
            $query,
            $canViewCost,
            $currency,
            $warehouseFilter,
            $statusFilter,
            $fromDate,
            $toDate,
            $searchQuery,
            $accounting
        ) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");

            fputcsv($file, ['Processings Export']);
            fputcsv($file, ['Generated At', date('c')]);
            fputcsv($file, ['Warehouse Filter', $accounting->sanitizeCsvCell($warehouseFilter)]);
            fputcsv($file, ['Status Filter', $accounting->sanitizeCsvCell($statusFilter)]);
            if (!empty($fromDate) || !empty($toDate)) {
                fputcsv($file, ['Date Range', ($fromDate ?: 'Beginning') . ' to ' . ($toDate ?: 'Present')]);
            }
            if (!empty($searchQuery)) {
                fputcsv($file, ['Search Filter', $accounting->sanitizeCsvCell($searchQuery)]);
            }
            fwrite($file, "\r\n");

            $headers = [
                '#',
                'Reference No',
                'Date',
                'Warehouse',
                'Status',
                'Output Product Code',
                'Output Product Name',
                'Output Variant',
                'Output Quantity',
                'Output Unit',
            ];
            if ($canViewCost) {
                $headers[] = "Output Unit Cost ({$currency})";
                $headers[] = "Total Materials Cost ({$currency})";
                $headers[] = "Additional Cost ({$currency})";
                $headers[] = "Total Production Cost ({$currency})";
            }
            array_push($headers, 'Created By', 'Role', 'Ingredients Count', 'Notes');
            fputcsv($file, $headers);

            $sn = 1;
            $sumOutputQty = 0.0;
            $sumInputCost = 0.0;
            $sumAdditionalCost = 0.0;
            $sumTotalCost = 0.0;

            foreach ($query->cursor() as $proc) {
                $sumOutputQty += (float)$proc->output_quantity;
                $sumInputCost += (float)$proc->total_input_cost;
                $sumAdditionalCost += (float)$proc->additional_cost;
                $sumTotalCost += (float)$proc->total_cost;

                $row = [
                    $sn++,
                    $accounting->sanitizeCsvCell($proc->reference_no),
                    $accounting->sanitizeCsvCell($proc->date),
                    $accounting->sanitizeCsvCell($proc->warehouse_name),
                    $accounting->sanitizeCsvCell(ucfirst((string)$proc->status)),
                    $accounting->sanitizeCsvCell($proc->output_product_code),
                    $accounting->sanitizeCsvCell($proc->output_product_name),
                    $accounting->sanitizeCsvCell($proc->output_variant_name ?? ''),
                    number_format((float)$proc->output_quantity, 2, '.', ''),
                    $accounting->sanitizeCsvCell($proc->output_unit_name),
                ];
                if ($canViewCost) {
                    $row[] = number_format((float)$proc->output_unit_cost, 2, '.', '');
                    $row[] = number_format((float)$proc->total_input_cost, 2, '.', '');
                    $row[] = number_format((float)$proc->additional_cost, 2, '.', '');
                    $row[] = number_format((float)$proc->total_cost, 2, '.', '');
                }
                $row[] = $accounting->sanitizeCsvCell($proc->user_name ?: ($proc->created_by ?: 'Admin'));
                $row[] = $accounting->sanitizeCsvCell($proc->user_role ?: 'Staff');
                $row[] = $proc->items ? $proc->items->count() : 0;
                $row[] = $accounting->sanitizeCsvCell($proc->notes ?? '');
                fputcsv($file, $row);
            }

            fputcsv($file, []);
            $totalsRow = [
                'TOTAL',
                ($sn - 1) . ' Processings',
                '',
                '',
                '',
                '',
                '',
                '',
                number_format($sumOutputQty, 2, '.', ''),
                '',
            ];
            if ($canViewCost) {
                $totalsRow[] = '';
                $totalsRow[] = number_format($sumInputCost, 2, '.', '');
                $totalsRow[] = number_format($sumAdditionalCost, 2, '.', '');
                $totalsRow[] = number_format($sumTotalCost, 2, '.', '');
            }
            array_push($totalsRow, '', '', '', '');
            fputcsv($file, $totalsRow);
            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'max-age=0, no-cache, must-revalidate',
        ]);
    }

    /**
     * Store new processing record with atomic FIFO input depletion & output batch creation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:255|unique:processings,reference_no',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',

            // Output Product
            'output_product_id' => 'required|integer|exists:products,id',
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
            'items.*.product_id' => 'required|integer|exists:products,id',
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
        if (!$warehouseId) {
            return response()->json([
                'status' => 'error',
                'message' => "Selected warehouse '{$validated['warehouse_name']}' could not be resolved.",
            ], 422);
        }
        $this->accounting->assertOutletAccess($request, $warehouseId, $validated['warehouse_name']);

        $status = $validated['status'] ?? 'completed';
        $canViewCost = $this->accounting->canViewCost($request);

        DB::beginTransaction();
        try {
            // Lock all involved products in ascending ID order to avoid deadlocks
            $allProductIds = collect($validated['items'])->pluck('product_id')
                ->push($validated['output_product_id'])
                ->map(fn($id) => (int)$id)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $lockedProducts = Product::with('variants')->whereIn('id', $allProductIds)->lockForUpdate()->get()->keyBy('id');

            $outputProd = $lockedProducts->get((int)$validated['output_product_id']);
            if ($outputProd && ($outputProd->has_variants || $outputProd->variants->isNotEmpty()) && empty($validated['output_variant_id'])) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => "ကုန်ချော '{$outputProd->name}' သည် Variation ပစ္စည်းဖြစ်ပါသည်။ Variation ရွေးချယ်ပေးရန် လိုအပ်ပါသည်။",
                ], 422);
            }

            // Validate input stock cumulatively under pessimistic lock
            $inputsByItem = [];
            foreach ($validated['items'] as $item) {
                $pid = (int)$item['product_id'];
                $vid = !empty($item['product_variant_id']) ? (int)$item['product_variant_id'] : null;
                $prod = $lockedProducts->get($pid);
                if ($prod && ($prod->has_variants || $prod->variants->isNotEmpty()) && empty($vid)) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "ကုန်ကြမ်း '{$prod->name}' သည် Variation ပစ္စည်းဖြစ်ပါသည်။ Variation ရွေးချယ်ပေးရန် လိုအပ်ပါသည်။",
                    ], 422);
                }

                $cRate = isset($item['conversion_rate']) ? (float)$item['conversion_rate'] : 1.0;
                $cRate = $cRate > 0 ? $cRate : 1.0;
                $baseQty = round((float)$item['quantity'] * $cRate, 4);
                $key = $pid . '_' . ($vid ? "var_{$vid}" : 'single');

                if (!isset($inputsByItem[$key])) {
                    $inputsByItem[$key] = [
                        'product_id' => $pid,
                        'variant_id' => $vid,
                        'product_name' => $item['product_name'],
                        'needed_base_qty' => 0.0,
                    ];
                }
                $inputsByItem[$key]['needed_base_qty'] = round($inputsByItem[$key]['needed_base_qty'] + $baseQty, 4);
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
                    $curBal = (float)($balQuery->lockForUpdate()->value('quantity') ?? 0.0);

                    if ($needed > ($curBal + 0.0001)) {
                        DB::rollBack();
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

            $prefix = Setting::get('processing_prefix', 'PR_');
            $refNo = !empty($validated['reference_no'])
                ? $validated['reference_no']
                : ($prefix . date('Ymd') . '-' . rand(1000, 9999));

            $outputQty = (float)$validated['output_quantity'];
            $outputRate = (float)($validated['output_conversion_rate'] ?? 1.0);
            if ($outputRate <= 0) $outputRate = 1.0;
            $outputBaseQty = round($outputQty * $outputRate, 4);
            $additionalCost = round(max(0.0, (float)($validated['additional_cost'] ?? 0.0)), 2);

            $targetUser = User::resolveFromRequest($request) ?? $request->user();
            if (!$targetUser && !empty($validated['user_id'])) {
                $targetUser = User::find($validated['user_id']);
            }
            if (!$targetUser) {
                $targetUser = User::first();
            }

            $createdByName = $targetUser?->name ?: ($validated['user_name'] ?? 'Super Admin');
            $userRole = $targetUser ? ($targetUser->roleModel?->name ?? $targetUser->role ?? 'Super Admin') : 'Super Admin';
            $userId = $targetUser?->id ?? ($validated['user_id'] ?? null);

            $txDate = $this->accounting->normalizeTransactionDate($validated['date']);
            $canonicalWarehouseName = Outlet::find($warehouseId)?->name ?? $validated['warehouse_name'];

            $processing = Processing::create([
                'reference_no' => $refNo,
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $canonicalWarehouseName,
                'date' => $txDate,

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
                'output_unit_cost' => 0.0,

                'total_input_cost' => 0.0,
                'additional_cost' => $additionalCost,
                'total_cost' => $additionalCost,

                'status' => $status,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $userId,
                'user_name' => $createdByName,
                'created_by' => $createdByName,
                'user_role' => $userRole,
            ]);

            $authoritativeInputTotal = 0.0;
            $touchedProductIds = [(int)$validated['output_product_id']];
            $touchedVariantIds = !empty($validated['output_variant_id']) ? [(int)$validated['output_variant_id']] : [];

            foreach ($validated['items'] as $itemData) {
                $pid = (int)$itemData['product_id'];
                $vid = !empty($itemData['product_variant_id']) ? (int)$itemData['product_variant_id'] : null;
                $prod = $lockedProducts->get($pid);
                $varObj = ($vid && $prod && $prod->relationLoaded('variants')) ? $prod->variants->firstWhere('id', $vid) : null;

                $cRate = (float)($itemData['conversion_rate'] ?? 1.0);
                if ($cRate <= 0) $cRate = 1.0;
                $qty = (float)$itemData['quantity'];
                $baseQty = round($qty * $cRate, 4);

                // Resolve authoritative server base cost fallback (never trusting spoofed costs)
                $serverBaseCost = (float)($varObj?->cost_price
                    ?: ($varObj?->purchase_price
                    ?: ($prod?->cost_price
                    ?: ($prod?->product_cost
                    ?: ($prod?->purchase_price ?? 0.0)))));
                if ($serverBaseCost <= 0 && $canViewCost && isset($itemData['unit_cost'])) {
                    $serverBaseCost = max(0.0, (float)$itemData['unit_cost'] / $cRate);
                }

                $draftUnitCost = round($serverBaseCost * $cRate, 4);
                $draftSubtotal = round($qty * $draftUnitCost, 2);

                $item = ProcessingItem::create([
                    'processing_id' => $processing->id,
                    'product_id' => $pid,
                    'product_variant_id' => $vid,
                    'product_code' => $itemData['product_code'],
                    'product_name' => $itemData['product_name'],
                    'variant_name' => $itemData['variant_name'] ?? null,
                    'unit_id' => $itemData['unit_id'] ?? null,
                    'unit_name' => $itemData['unit_name'] ?? 'Piece',
                    'conversion_rate' => $cRate,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'unit_cost' => $draftUnitCost,
                    'base_unit_cost' => round($serverBaseCost, 4),
                    'subtotal_cost' => $draftSubtotal,
                ]);

                if ($status === 'completed') {
                    // 1. Deplete exact chronological FIFO input batches & persist ProcessingItemCostLayers
                    $fifoRes = $this->fifoService->depleteProcessingItemFifo(
                        $item,
                        $warehouseId,
                        $txDate,
                        $serverBaseCost
                    );
                    $authBaseCost = (float)$fifoRes['base_unit_cost'];
                    $authTotalCost = (float)$fifoRes['total_cost'];
                    $authUnitCost = $qty > 0 ? round($authTotalCost / $qty, 4) : round($authBaseCost * $cRate, 4);

                    $item->update([
                        'base_unit_cost' => $authBaseCost,
                        'unit_cost' => $authUnitCost,
                        'subtotal_cost' => $authTotalCost,
                    ]);
                    $authoritativeInputTotal = round($authoritativeInputTotal + $authTotalCost, 2);

                    // 2. Deduct input stock balance & record stock_movements with authoritative FIFO unit_cost
                    $this->deductInputStock(
                        $item,
                        $warehouseId,
                        $processing->id,
                        $refNo,
                        $userId,
                        $txDate
                    );
                } else {
                    $authoritativeInputTotal = round($authoritativeInputTotal + $draftSubtotal, 2);
                }

                $touchedProductIds[] = $pid;
                if ($vid) {
                    $touchedVariantIds[] = $vid;
                }
            }

            $finalTotalCost = round($authoritativeInputTotal + $additionalCost, 2);
            $finalOutputUnitCost = $outputQty > 0 ? round($finalTotalCost / $outputQty, 4) : 0.0;
            $processing->update([
                'total_input_cost' => $authoritativeInputTotal,
                'total_cost' => $finalTotalCost,
                'output_unit_cost' => $finalOutputUnitCost,
            ]);

            if ($status === 'completed') {
                $this->addOutputStock($processing, $warehouseId, $userId, $txDate);
                $this->unitService->syncAggregatesForProducts($touchedProductIds, $touchedVariantIds);
            }

            DB::commit();
            $this->clearProcessingCaches();

            $fresh = $processing->fresh('items');
            $this->maskProcessingCostFields($fresh, $canViewCost);

            return response()->json([
                'status' => 'success',
                'message' => 'Processing completed successfully',
                'data' => $fresh,
            ], 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: 'Forbidden',
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ProcessingController store error: ' . $e->getMessage(), [
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
    public function show(Request $request, $id)
    {
        $processing = Processing::with(['items', 'outputProduct', 'user', 'warehouse'])->find($id);
        if (!$processing) {
            return response()->json(['status' => 'error', 'message' => 'Processing record not found'], 404);
        }

        $this->accounting->assertOutletAccess($request, $processing->warehouse_id, $processing->warehouse_name);
        $this->maskProcessingCostFields($processing, $this->accounting->canViewCost($request));

        return response()->json([
            'status' => 'success',
            'data' => $processing,
        ]);
    }

    /**
     * Delete / Reverse processing record atomically with full FIFO layer reversal
     */
    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $processing = Processing::with('items')->lockForUpdate()->find($id);
            if (!$processing) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Processing record not found'], 404);
            }

            $warehouseId = $processing->warehouse_id ?: $this->resolveWarehouseId(null, $processing->warehouse_name);
            if (!$warehouseId) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Warehouse not found for processing record'], 422);
            }
            $this->accounting->assertOutletAccess($request, $warehouseId, $processing->warehouse_name);

            if ($processing->status === 'completed') {
                // Verify output batch is unconsumed and stock balance is intact
                if ($fifoErr = $this->fifoService->reverseProcessingFifo($processing)) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => $fifoErr], 422);
                }

                $outBalQuery = StockBalance::where('warehouse_id', $warehouseId)
                    ->where('product_id', $processing->output_product_id);
                if ($processing->output_variant_id) {
                    $outBalQuery->where('product_variant_id', $processing->output_variant_id);
                } else {
                    $outBalQuery->whereNull('product_variant_id');
                }
                $outBalRow = (clone $outBalQuery)->lockForUpdate()->first();
                $curOutBal = (float)($outBalRow?->quantity ?? 0.0);

                if ($curOutBal < ($processing->output_base_quantity - 0.0001)) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "ထုတ်လုပ်ထားသော ပစ္စည်းများ ရောင်းချပြီးဖြစ်သဖြင့် Processing စာရင်းကို ဖျက်၍မရပါ (လက်ကျန် {$curOutBal} < ပြန်နုတ်ရမည့် {$processing->output_base_quantity})",
                    ], 422);
                }

                // 1. Deduct output finished good
                $outBalQuery->decrement('quantity', $processing->output_base_quantity);
                $outProd = Product::lockForUpdate()->find($processing->output_product_id);
                if ($outProd) {
                    $outProd->decrement('stock_quantity', $processing->output_base_quantity);
                }

                $outMoveUnitCost = $processing->output_conversion_rate > 0
                    ? round((float)$processing->output_unit_cost / (float)$processing->output_conversion_rate, 4)
                    : round((float)$processing->output_unit_cost, 4);

                $outMoveData = [
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
                ];
                if (Schema::hasColumn('stock_movements', 'unit_cost')) {
                    $outMoveData['unit_cost'] = $outMoveUnitCost;
                }
                StockMovement::create($outMoveData);

                $touchedProductIds = [(int)$processing->output_product_id];
                $touchedVariantIds = $processing->output_variant_id ? [(int)$processing->output_variant_id] : [];

                // 2. Refund input materials (StockBalance & StockMovement; InventoryBatches already restored by reverseProcessingFifo)
                foreach ($processing->items as $item) {
                    $inBalQuery = StockBalance::where('warehouse_id', $warehouseId)
                        ->where('product_id', $item->product_id);
                    if ($item->product_variant_id) {
                        $inBalQuery->where('product_variant_id', $item->product_variant_id);
                    } else {
                        $inBalQuery->whereNull('product_variant_id');
                    }
                    $inBal = $inBalQuery->lockForUpdate()->first();
                    if ($inBal) {
                        $inBal->increment('quantity', $item->base_quantity);
                        $inBal->refresh();
                    } else {
                        $inBal = StockBalance::create([
                            'warehouse_id' => $warehouseId,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity' => $item->base_quantity,
                        ]);
                    }

                    $inProd = Product::lockForUpdate()->find($item->product_id);
                    if ($inProd) {
                        $inProd->increment('stock_quantity', $item->base_quantity);
                    }

                    $inMoveData = [
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
                    ];
                    if (Schema::hasColumn('stock_movements', 'unit_cost')) {
                        $inMoveData['unit_cost'] = round((float)$item->base_unit_cost, 4);
                    }
                    StockMovement::create($inMoveData);

                    $touchedProductIds[] = (int)$item->product_id;
                    if ($item->product_variant_id) {
                        $touchedVariantIds[] = (int)$item->product_variant_id;
                    }
                }

                $this->unitService->syncAggregatesForProducts($touchedProductIds, $touchedVariantIds);
            }

            $processing->items()->delete();
            $processing->delete();

            DB::commit();
            $this->clearProcessingCaches();

            return response()->json([
                'status' => 'success',
                'message' => 'Processing deleted and stock reversed successfully',
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: 'Forbidden',
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ProcessingController destroy error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Deduct input raw material stock balance and record FIFO-costed movement
     */
    private function deductInputStock(
        ProcessingItem $item,
        int $warehouseId,
        int $processingId,
        string $refNo,
        ?int $userId,
        string $txDate
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

        $balance = $balQuery->lockForUpdate()->first();
        if ($balance) {
            $balance->decrement('quantity', $baseQty);
            $balance->refresh();
        }

        $prod = Product::find($pid);
        if ($prod) {
            $prod->decrement('stock_quantity', $baseQty);
        }

        $moveData = [
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
            'created_at' => $txDate,
        ];
        if (Schema::hasColumn('stock_movements', 'unit_cost')) {
            $moveData['unit_cost'] = round((float)$item->base_unit_cost, 4);
        }
        StockMovement::create($moveData);
    }

    /**
     * Add output finished good stock and create new FIFO batch
     */
    private function addOutputStock(
        Processing $processing,
        int $warehouseId,
        ?int $userId,
        string $txDate
    ): void {
        $pid = $processing->output_product_id;
        $vid = $processing->output_variant_id;
        $baseQty = (float)$processing->output_base_quantity;
        $baseUnitCost = $processing->output_conversion_rate > 0
            ? round((float)$processing->output_unit_cost / (float)$processing->output_conversion_rate, 4)
            : round((float)$processing->output_unit_cost, 4);

        $balQuery = StockBalance::where('warehouse_id', $warehouseId)->where('product_id', $pid);
        if ($vid && $vid > 0) {
            $balQuery->where('product_variant_id', $vid);
        } else {
            $balQuery->whereNull('product_variant_id');
        }

        $balance = $balQuery->lockForUpdate()->first();
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

        $moveData = [
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
            'created_at' => $txDate,
        ];
        if (Schema::hasColumn('stock_movements', 'unit_cost')) {
            $moveData['unit_cost'] = $baseUnitCost;
        }
        StockMovement::create($moveData);

        // Record FIFO batch atomically (never swallow failures)
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
    }

    private function resolveWarehouseId(?int $warehouseId, string $warehouseName): ?int
    {
        $resolved = $this->unitService->warehouseId($warehouseId, $warehouseName);
        if ($resolved !== null && $resolved > 0) {
            return (int)$resolved;
        }
        if (($warehouseId === null || $warehouseId <= 0) && trim($warehouseName) === '') {
            $first = Outlet::orderBy('id')->value('id');
            return $first ? (int)$first : null;
        }
        return null;
    }
}
