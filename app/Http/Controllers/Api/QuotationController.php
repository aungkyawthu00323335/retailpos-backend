<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CashRegister;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Services\ProductUnitConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;
    /**
     * Generate next sequential quotation code and formatted reference number.
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('quotation'));
    }

    /**
     * Build filtered quotation query based on request parameters
     */
    protected function buildFilteredQuery(Request $request, bool $includeItems = false)
    {
        $withRelations = ['customer:id,name,phone,email,credit_limit'];
        if ($includeItems) {
            $withRelations[] = 'items:id,quotation_id,product_id,product_variant_id,unit_id,unit_name,conversion_rate,product_code,product_name,variant_name,unit_price,base_unit_price,quantity,base_quantity,tax,discount,subtotal';
        }

        $query = Quotation::select([
            'id', 'reference_no', 'customer_id', 'customer_name', 'warehouse_id', 'warehouse_name',
            'date', 'expiry_date', 'tax_rate', 'tax_amount', 'discount', 'shipping', 'grand_total',
            'status', 'notes', 'created_by', 'user_id', 'user_name', 'user_role', 'created_at', 'updated_at'
        ])
        ->withCount('items')
        ->withSum('items as total_quantity', 'quantity')
        ->with($withRelations);

        // Filter: Warehouse / Outlet
        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $warehouse = $request->input('warehouse');
            $query->where(function ($q) use ($warehouse) {
                $q->where('warehouse_name', $warehouse);
                if (is_numeric($warehouse)) {
                    $q->orWhere('warehouse_id', (int)$warehouse);
                }
            });
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int)$request->input('warehouse_id'));
        }

        // Filter: Status
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        // Filter: Search (Reference #, Customer, Staff Name, Role, Notes, Product Name/Code)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%")
                  ->orWhere('user_role', 'like', "%{$search}%")
                  ->orWhere('created_by', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('items', function ($iq) use ($search) {
                      $iq->where('product_name', 'like', "%{$search}%")
                         ->orWhere('product_code', 'like', "%{$search}%");
                  });
            });
        }

        // Filter: Created By / Staff
        if ($request->filled('created_by') && $request->input('created_by') !== 'All') {
            $filterUser = $request->input('created_by');
            $query->where(function ($q) use ($filterUser) {
                $q->where('created_by', $filterUser)
                  ->orWhere('user_name', $filterUser);
            });
        }
        if ($request->filled('user_role') && $request->input('user_role') !== 'All') {
            $query->where('user_role', $request->input('user_role'));
        }

        // Filter: Customer
        if ($request->filled('customer') && $request->input('customer') !== 'All') {
            $customer = $request->input('customer');
            $query->where(function ($q) use ($customer) {
                $q->where('customer_name', $customer);
                if (is_numeric($customer)) {
                    $q->orWhere('customer_id', (int)$customer);
                }
            });
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int)$request->input('customer_id'));
        }

        // Filter: Date Range (Optimized for MySQL Index Usage)
        $fromDate = $request->input('from_date') ?? $request->input('date_from');
        if (!empty($fromDate)) {
            $from = trim($fromDate);
            $query->where('date', '>=', str_contains($from, ':') ? $from : $from . ' 00:00:00');
        }
        $toDate = $request->input('to_date') ?? $request->input('date_to');
        if (!empty($toDate)) {
            $to = trim($toDate);
            $query->where('date', '<=', str_contains($to, ':') ? $to : $to . ' 23:59:59');
        }

        return $query;
    }

    /**
     * Display a paginated listing of quotations with optional filters and sorting.
     */
    public function index(Request $request)
    {
        $includeItems = $request->boolean('include_items') || $request->input('with_items') === '1';
        $query = $this->buildFilteredQuery($request, $includeItems);

        // Sorting
        $allowedSortCols = [
            'id' => 'id',
            'reference' => 'reference_no',
            'reference_no' => 'reference_no',
            'customer' => 'customer_name',
            'customer_name' => 'customer_name',
            'warehouse' => 'warehouse_name',
            'warehouse_name' => 'warehouse_name',
            'date' => 'date',
            'status' => 'status',
            'grand_total' => 'grand_total',
            'total' => 'grand_total',
            'user_name' => 'user_name',
            'user_role' => 'user_role',
            'created_by' => 'created_by',
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        $sortBy = $allowedSortCols[$sortByInput] ?? 'id';
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';
        if ($isAll) {
            $totalCount = (clone $query)->count();
            $limit = min(1000, max(1, (int)$request->input('limit', 500)));
            $items = $query->orderBy($sortBy, $sortOrder)->take($limit)->get();
            return response()->json([
                'status' => 'success',
                'data' => $items,
                'total' => $totalCount,
                'summary' => [
                    'total_records' => $totalCount,
                    'total_grand_total' => (float)(clone $query)->sum('grand_total'),
                ],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $items->count(),
                    'total' => $totalCount,
                    'last_page' => (int)ceil($totalCount / max(1, $limit)),
                ],
            ])
            ->header('Cache-Control', 'private, max-age=60');
        }

        $perPage = min(1000, max(1, (int)$request->input('per_page', 25)));
        $page = (int)$request->input('page', 1);

        $quotations = $query->orderBy($sortBy, $sortOrder)->paginate($perPage, ['*'], 'page', $page);

        // HTTP ETag & Cache-Control for fast conditional responses with filter & update awareness
        $total = $quotations->total();
        $maxUpdated = (clone $query)->max('quotations.updated_at') ?? 'none';
        $filterSig = http_build_query($request->only(['warehouse', 'warehouse_id', 'status', 'search', 'customer', 'customer_id', 'created_by', 'user_role', 'date_from', 'date_to', 'from_date', 'to_date']));
        $etag = md5("quo|{$page}|{$perPage}|{$total}|{$sortBy}|{$sortOrder}|{$maxUpdated}|{$filterSig}");

        if ($request->header('If-None-Match') === '"' . $etag . '"') {
            return response()->noContent(304)->header('ETag', '"' . $etag . '"');
        }

        $summary = [
            'total_records' => $total,
            'total_grand_total' => (float)(clone $query)->sum('grand_total'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $quotations->items(),
            'total' => $total,
            'summary' => $summary,
            'pagination' => [
                'current_page' => $quotations->currentPage(),
                'per_page' => $quotations->perPage(),
                'total' => $total,
                'last_page' => $quotations->lastPage(),
            ],
        ])
        ->header('Cache-Control', 'private, max-age=60')
        ->header('ETag', '"' . $etag . '"');
    }

    /**
     * Streamed UTF-8 BOM CSV Export for Quotations matching active filters
     */
    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $query = $this->buildFilteredQuery($request, false);

        // Sorting
        $allowedSortCols = [
            'id' => 'quotations.id',
            'reference' => 'quotations.reference_no',
            'reference_no' => 'quotations.reference_no',
            'customer' => 'quotations.customer_name',
            'customer_name' => 'quotations.customer_name',
            'warehouse' => 'quotations.warehouse_name',
            'warehouse_name' => 'quotations.warehouse_name',
            'date' => 'quotations.date',
            'status' => 'quotations.status',
            'grand_total' => 'quotations.grand_total',
            'total' => 'quotations.grand_total',
            'user_name' => 'quotations.user_name',
            'user_role' => 'quotations.user_role',
            'created_by' => 'quotations.created_by',
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        $sortBy = $allowedSortCols[$sortByInput] ?? 'quotations.id';
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="quotations_export_' . date('Ymd_His') . '.csv"',
            'X-Accel-Buffering' => 'no',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Expires' => '0',
        ];

        $warehouseFilter = $request->input('warehouse', 'All');
        $statusFilter = $request->input('status', 'All');
        $customerFilter = $request->input('customer');
        $userFilter = $request->input('created_by');
        $fromDate = $request->input('from_date') ?? $request->input('date_from');
        $toDate = $request->input('to_date') ?? $request->input('date_to');
        $searchQuery = $request->input('search');

        // Join customer phone directly with explicit column qualification to avoid ambiguity
        $query->leftJoin('customers', 'quotations.customer_id', '=', 'customers.id')
              ->select([
                  'quotations.id', 'quotations.reference_no', 'quotations.customer_name',
                  'quotations.warehouse_name', 'quotations.date', 'quotations.tax_rate',
                  'quotations.tax_amount', 'quotations.discount', 'quotations.shipping',
                  'quotations.grand_total', 'quotations.status', 'quotations.notes',
                  'quotations.created_by', 'quotations.user_name', 'quotations.user_role',
                  'customers.phone as customer_phone'
              ]);

        $callback = function () use (
            $query,
            $sortBy,
            $sortOrder,
            $warehouseFilter,
            $statusFilter,
            $customerFilter,
            $userFilter,
            $fromDate,
            $toDate,
            $searchQuery
        ) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");

            // 1. Metadata Header
            fputcsv($file, ['Quotations / Estimates Export']);
            fputcsv($file, ['Generated At', date('c')]);
            fputcsv($file, ['Warehouse Filter', $warehouseFilter ?: 'All']);
            fputcsv($file, ['Status Filter', $statusFilter ?: 'All']);
            if (!empty($customerFilter) && $customerFilter !== 'All') {
                fputcsv($file, ['Customer Filter', $customerFilter]);
            }
            if (!empty($userFilter) && $userFilter !== 'All') {
                fputcsv($file, ['Created By Filter', $userFilter]);
            }
            if (!empty($fromDate) || !empty($toDate)) {
                $fromStr = !empty($fromDate) ? explode(' ', $fromDate)[0] : 'Beginning';
                $toStr = !empty($toDate) ? explode(' ', $toDate)[0] : 'Present';
                fputcsv($file, ['Date Range', "$fromStr to $toStr"]);
            }
            if (!empty($searchQuery)) {
                fputcsv($file, ['Search Filter', $searchQuery]);
            }
            fwrite($file, "\r\n");

            // 2. Column Headers
            $columns = [
                'SN',
                'Quotation Reference',
                'Customer Name',
                'Customer Phone',
                'Warehouse / Outlet',
                'Tax Rate (%)',
                'Tax Amount (MMK)',
                'Discount (MMK)',
                'Shipping (MMK)',
                'Grand Total (MMK)',
                'Status',
                'Created By',
                'User Role',
                'Date',
                'Time',
                'Notes',
            ];
            fputcsv($file, $columns);

            $sn = 1;
            $formatNum = function ($num) {
                $floatVal = (float)$num;
                return (fmod($floatVal, 1.0) == 0.0) ? (string)((int)$floatVal) : (string)number_format($floatVal, 2, '.', '');
            };

            $formatPhone = function ($phone) {
                $phone = trim((string)$phone);
                if ($phone === '') return '';
                if (str_starts_with($phone, '0') || str_starts_with($phone, '+') || str_contains($phone, ' ')) {
                    return '="' . $phone . '"';
                }
                return $phone;
            };

            $totalCount = 0;
            $totalTax = 0.0;
            $totalDiscount = 0.0;
            $totalShipping = 0.0;
            $totalGrandTotal = 0.0;

            $flushCount = 0;
            foreach ($query->orderBy($sortBy, $sortOrder)->toBase()->cursor() as $q) {
                $totalCount++;
                $totalTax += (float)$q->tax_amount;
                $totalDiscount += (float)$q->discount;
                $totalShipping += (float)$q->shipping;
                $totalGrandTotal += (float)$q->grand_total;

                $dateParts = explode(' ', (string)$q->date);
                $dateOnly = $dateParts[0] ?? '';
                $timeStr = $dateParts[1] ?? '';

                $phone = $q->customer_phone ?? '';

                // Clean user name / created_by
                $userName = trim((string)($q->user_name ?: $q->created_by));
                if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $userName, $m)) {
                    $userName = trim($m[1]);
                }
                if (empty($userName)) $userName = 'Sales Staff';

                $userRole = trim((string)($q->user_role ?: 'Sales Staff'));

                $row = [
                    $sn++,
                    $q->reference_no,
                    $q->customer_name,
                    $formatPhone($phone),
                    $q->warehouse_name,
                    $formatNum($q->tax_rate),
                    $formatNum($q->tax_amount),
                    $formatNum($q->discount),
                    $formatNum($q->shipping),
                    $formatNum($q->grand_total),
                    $q->status,
                    $userName,
                    $userRole,
                    $dateOnly,
                    $timeStr,
                    $q->notes ?? '',
                ];
                fputcsv($file, $row);

                if (++$flushCount % 500 === 0) {
                    flush();
                }
            }

            // Summary row
            fputcsv($file, []);
            fputcsv($file, [
                'TOTALS',
                "$totalCount Quotations",
                '',
                '',
                '',
                '',
                $formatNum($totalTax),
                $formatNum($totalDiscount),
                $formatNum($totalShipping),
                $formatNum($totalGrandTotal),
                '',
                '',
                '',
                '',
                '',
                '',
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Store a newly created quotation with its line items.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no'       => 'nullable|string|max:100|unique:quotations,reference_no',
            'customer_id'        => 'nullable|integer',
            'customer_name'      => 'required|string|max:255',
            'warehouse_id'       => 'nullable|integer',
            'warehouse_name'     => 'required|string|max:255',
            'user_id'            => 'nullable|integer',
            'user_name'          => 'nullable|string|max:255',
            'user_role'          => 'nullable|string|max:255',
            'date'               => 'required|string',
            'expiry_date'        => 'nullable|date',
            'tax_rate'           => 'nullable|numeric|min:0',
            'tax_amount'         => 'nullable|numeric|min:0',
            'discount'           => 'nullable|numeric|min:0',
            'shipping'           => 'nullable|numeric|min:0',
            'grand_total'        => 'required|numeric|min:0',
            'status'             => 'nullable|string|in:Sent,Pending,Accepted,Ordered,Declined,Expired',
            'notes'              => 'nullable|string',
            'created_by'         => 'nullable|string|max:255',
            'items'              => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0',
            'items.*.base_unit_price'    => 'nullable|numeric|min:0',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.tax'                => 'nullable|numeric|min:0',
            'items.*.discount'           => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $refNo = $this->acquireUniqueReference('quotation', $request->input('reference_no'));

            $user = $request->user();
            $userId = $validated['user_id'] ?? $user?->id;
            $userName = $validated['user_name'] ?? $user?->name;
            $userRole = $validated['user_role'] ?? $user?->role ?? 'Sales Staff';
            $creator = $validated['created_by'] ?? ($userName ?: ($user?->name ?: 'Sales Staff'));

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            // Determine status — if expiry_date is in the past, set to Expired automatically
            $expiryDate  = $validated['expiry_date'] ?? null;
            $autoStatus  = $validated['status'] ?? 'Sent';
            if ($expiryDate && \Carbon\Carbon::parse($expiryDate)->endOfDay()->isPast() && $autoStatus !== 'Ordered') {
                $autoStatus = 'Expired';
            }

            // Server-side financial integrity calculation (Prevents client manipulation)
            $itemsSubtotal = 0.0;
            foreach ($validated['items'] as $it) {
                $q = (float)$it['quantity'];
                $up = (float)$it['unit_price'];
                $t = (float)($it['tax'] ?? 0);
                $d = (float)($it['discount'] ?? 0);
                $itemsSubtotal += round(($q * $up) - $d + $t, 2);
            }
            $discount = (float)($validated['discount'] ?? 0);
            $taxRate = (float)($validated['tax_rate'] ?? 0);
            $taxableBase = max(0.0, $itemsSubtotal - $discount);
            $taxAmount = $taxRate > 0
                ? round($taxableBase * ($taxRate / 100), 2)
                : (isset($validated['tax_amount']) ? round((float)$validated['tax_amount'], 2) : 0.0);
            $shipping = (float)($validated['shipping'] ?? 0);
            $grandTotal = round(max(0.0, $itemsSubtotal - $discount + $taxAmount + $shipping), 2);

            $quotation = Quotation::create([
                'reference_no' => $refNo,
                'customer_id'  => $validated['customer_id'] ?? null,
                'customer_name'=> $validated['customer_name'],
                'warehouse_id' => $validated['warehouse_id'] ?? null,
                'warehouse_name'=> $validated['warehouse_name'],
                'date'         => $txDate,
                'expiry_date'  => $expiryDate,
                'tax_rate'     => $taxRate,
                'tax_amount'   => $taxAmount,
                'discount'     => $discount,
                'shipping'     => $shipping,
                'grand_total'  => $grandTotal,
                'status'       => $autoStatus,
                'notes'        => $validated['notes'] ?? null,
                'created_by'   => $creator,
                'user_id'      => $userId,
                'user_name'    => $userName,
                'user_role'    => $userRole,
            ]);


            $itemsData = [];
            $now = now();
            foreach ($validated['items'] as $item) {
                $convRate = (float)($item['conversion_rate'] ?? 1.0);
                if ($convRate <= 0) $convRate = 1.0;
                $qty = (float)$item['quantity'];
                $unitPrice = (float)$item['unit_price'];
                $baseQty = isset($item['base_quantity']) ? (float)$item['base_quantity'] : ($qty * $convRate);
                $basePrice = isset($item['base_unit_price']) ? (float)$item['base_unit_price'] : ($unitPrice / $convRate);

                $itemTax = (float)($item['tax'] ?? 0);
                $itemDiscount = (float)($item['discount'] ?? 0);
                $lineSubtotal = round(max(0.0, ($qty * $unitPrice) - $itemDiscount + $itemTax), 2);

                $itemsData[] = [
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'unit_id' => $item['unit_id'] ?? null,
                    'unit_name' => $item['unit_name'] ?? null,
                    'conversion_rate' => $convRate,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'variant_name' => $item['variant_name'] ?? null,
                    'unit_price' => $unitPrice,
                    'base_unit_price' => $basePrice,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'tax' => $itemTax,
                    'discount' => $itemDiscount,
                    'subtotal' => $lineSubtotal,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            QuotationItem::insert($itemsData);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation created successfully',
                'data' => $quotation->load(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified quotation with all child items.
     */
    public function show($id)
    {
        $quotation = Quotation::with(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer'])->find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }
        return response()->json(['status' => 'success', 'data' => $quotation]);
    }

    /**
     * Update the specified quotation and sync its items in a single transaction.
     */
    public function update(Request $request, $id)
    {
        $quotation = Quotation::find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        // Commercial Audit Guard: Prevent modifying already converted quotation
        if ($quotation->status === 'Ordered') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot update a quotation that has already been converted to an order/sale.',
            ], 422);
        }

        $validated = $request->validate([
            'reference_no'               => ['nullable', 'string', 'max:100', Rule::unique('quotations', 'reference_no')->ignore($quotation->id)],
            'customer_id'                => 'nullable|integer',
            'customer_name'              => 'required|string|max:255',
            'warehouse_id'               => 'nullable|integer',
            'warehouse_name'             => 'required|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:255',
            'date'                       => 'required|string',
            'expiry_date'                => 'nullable|date',
            'tax_rate'                   => 'nullable|numeric|min:0',
            'tax_amount'                 => 'nullable|numeric|min:0',
            'discount'                   => 'nullable|numeric|min:0',
            'shipping'                   => 'nullable|numeric|min:0',
            'grand_total'                => 'required|numeric|min:0',
            'status'                     => 'nullable|string|in:Sent,Pending,Accepted,Ordered,Declined,Expired',
            'notes'                      => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|min:0',
            'items.*.base_unit_price'    => 'nullable|numeric|min:0',
            'items.*.base_quantity'      => 'nullable|numeric|min:0',
            'items.*.product_code'       => 'required|string',
            'items.*.product_name'       => 'required|string',
            'items.*.variant_name'       => 'nullable|string',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.tax'                => 'nullable|numeric|min:0',
            'items.*.discount'           => 'nullable|numeric|min:0',
            'items.*.subtotal'           => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $expiryDate = $validated['expiry_date'] ?? $quotation->expiry_date;
            $newStatus  = $validated['status'] ?? $quotation->status;
            // Auto-expire if expiry_date is in the past (and not already Ordered)
            if ($expiryDate && $newStatus !== 'Ordered' && \Carbon\Carbon::parse($expiryDate)->endOfDay()->isPast()) {
                $newStatus = 'Expired';
            }

            // Server-side financial integrity calculation
            $itemsSubtotal = 0.0;
            foreach ($validated['items'] as $it) {
                $q = (float)$it['quantity'];
                $up = (float)$it['unit_price'];
                $t = (float)($it['tax'] ?? 0);
                $d = (float)($it['discount'] ?? 0);
                $itemsSubtotal += round(($q * $up) - $d + $t, 2);
            }
            $discount = (float)($validated['discount'] ?? 0);
            $taxRate = (float)($validated['tax_rate'] ?? 0);
            $taxableBase = max(0.0, $itemsSubtotal - $discount);
            $taxAmount = $taxRate > 0
                ? round($taxableBase * ($taxRate / 100), 2)
                : (isset($validated['tax_amount']) ? round((float)$validated['tax_amount'], 2) : 0.0);
            $shipping = (float)($validated['shipping'] ?? 0);
            $grandTotal = round(max(0, $itemsSubtotal - $discount + $taxAmount + $shipping), 2);

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $updateData = [
                'customer_id'   => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'warehouse_id'  => $validated['warehouse_id'] ?? $quotation->warehouse_id,
                'warehouse_name'=> $validated['warehouse_name'],
                'date'          => $txDate,
                'expiry_date'   => $expiryDate,
                'tax_rate'      => $taxRate,
                'tax_amount'    => $taxAmount,
                'discount'      => $discount,
                'shipping'      => $shipping,
                'grand_total'   => $grandTotal,
                'status'        => $newStatus,
                'notes'         => $validated['notes'] ?? null,
            ];


            $user = $request->user();
            if (!empty($validated['reference_no'])) {
                $updateData['reference_no'] = $validated['reference_no'];
            }
            if (!empty($validated['created_by'])) {
                $updateData['created_by'] = $validated['created_by'];
            } elseif (empty($quotation->created_by) && $user?->name) {
                $updateData['created_by'] = $user->name;
            }
            if (!empty($validated['user_id'])) {
                $updateData['user_id'] = $validated['user_id'];
            } elseif (empty($quotation->user_id) && $user?->id) {
                $updateData['user_id'] = $user->id;
            }
            if (!empty($validated['user_name'])) {
                $updateData['user_name'] = $validated['user_name'];
            } elseif (empty($quotation->user_name) && $user?->name) {
                $updateData['user_name'] = $user->name;
            }
            if (!empty($validated['user_role'])) {
                $updateData['user_role'] = $validated['user_role'];
            } elseif (empty($quotation->user_role) && $user?->role) {
                $updateData['user_role'] = $user->role;
            }

            $quotation->update($updateData);

            // Replace items in batch
            $quotation->items()->delete();

            $itemsData = [];
            $now = now();
            foreach ($validated['items'] as $item) {
                $convRate = (float)($item['conversion_rate'] ?? 1.0);
                if ($convRate <= 0) $convRate = 1.0;
                $qty = (float)$item['quantity'];
                $unitPrice = (float)$item['unit_price'];
                $baseQty = isset($item['base_quantity']) ? (float)$item['base_quantity'] : ($qty * $convRate);
                $basePrice = isset($item['base_unit_price']) ? (float)$item['base_unit_price'] : ($unitPrice / $convRate);

                $itemTax = (float)($item['tax'] ?? 0);
                $itemDiscount = (float)($item['discount'] ?? 0);
                $lineSubtotal = round(max(0.0, ($qty * $unitPrice) - $itemDiscount + $itemTax), 2);

                $itemsData[] = [
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'unit_id' => $item['unit_id'] ?? null,
                    'unit_name' => $item['unit_name'] ?? null,
                    'conversion_rate' => $convRate,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'variant_name' => $item['variant_name'] ?? null,
                    'unit_price' => $unitPrice,
                    'base_unit_price' => $basePrice,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'tax' => $itemTax,
                    'discount' => $itemDiscount,
                    'subtotal' => $lineSubtotal,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            QuotationItem::insert($itemsData);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation updated successfully',
                'data' => $quotation->fresh(['items.variant.values', 'items.variant.attributeValues', 'items.product', 'customer']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified quotation and all its child items.
     */
    public function destroy($id)
    {
        $quotation = Quotation::find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        // Commercial Audit Guard: Prevent deleting already converted quotation
        if ($quotation->status === 'Ordered') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cannot delete a quotation that has already been converted to an order/sale.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $quotation->items()->delete();
            $quotation->delete();
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation deleted successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Convert the specified quotation into a completed sale, and mark quotation as Ordered.
     *
     * Accounting integrity:
     * 1. Duplicate guard — lockForUpdate() & status === 'Ordered' → 422 (prevents double-conversion race condition)
     * 2. Overpayment & change calculation — paid_amount capped at grand_total, accurate change_amount
     * 3. Cash Register increment — only increments actual paid_amount (drawer not inflated by change)
     * 4. Accounting guards — Walk-in credit policy, Customer credit limit, Below-cost authorization
     * 5. Pre-check stock balance & batch sync aggregates
     * 6. FIFO depletion per item with variant cost_price & purchase_price fallbacks
     * 7. Targeted cache eviction (no full file cache flush)
     */
    public function convertToSale(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // Pessimistic locking prevents race conditions / double conversion fraud
            $quotation = Quotation::with('items')->lockForUpdate()->find($id);
            if (!$quotation) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
            }

            // ── Commercial & Accounting Guards ────────────────────────────────────
            // 1. Prevent duplicate conversion
            if ($quotation->status === 'Ordered') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'This quotation has already been converted to a sale and cannot be converted again.',
                ], 422);
            }

            // 2. Prevent converting declined quotation
            if ($quotation->status === 'Declined') {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot convert a declined quotation to a sale.',
                ], 422);
            }

            // 3. Prevent converting expired quotation (Financial Leakage prevention)
            if ($quotation->status === 'Expired' || ($quotation->expiry_date && \Carbon\Carbon::parse($quotation->expiry_date)->endOfDay()->isPast())) {
                DB::rollBack();
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cannot convert an expired quotation to a sale. Please issue a new quotation with updated pricing.',
                ], 422);
            }

            $salePrefix = Setting::get('sale_prefix', 'SA-');
            if (empty($salePrefix)) {
                $salePrefix = 'SA-';
            }
            if (!str_ends_with($salePrefix, '-') && !str_ends_with($salePrefix, '_')) {
                $salePrefix .= '-';
            }
            $maxNum = (int)Sale::count();
            $recent = Sale::where('reference_no', 'like', $salePrefix . '%')
                ->orWhere('reference_no', 'like', 'SA-%')
                ->orderBy('id', 'desc')->take(100)->get();
            foreach ($recent as $s) {
                if (preg_match('/(?:' . preg_quote($salePrefix, '/') . '|SA-)(\d+)/', (string)$s->reference_no, $m)) {
                    $val = (int)$m[1];
                    if ($val > $maxNum && $val < 99999999) {
                        $maxNum = $val;
                    }
                }
            }
            $nextNum = $maxNum + 1;
            $refNo = $salePrefix . sprintf('%07d', $nextNum);
            while (Sale::where('reference_no', $refNo)->exists()) {
                $nextNum++;
                $refNo = $salePrefix . sprintf('%07d', $nextNum);
            }
            $paymentType = $request->input('payment_type', 'Cash');

            // 🛡️ Financial Integrity: Overpayment handling & Change Amount
            $grandTotal     = (float)$quotation->grand_total;
            $rawPaid        = (float)($request->input('paid_amount', $grandTotal));
            $receivedAmount = (float)($request->input('received_amount', $rawPaid));
            $paidAmount     = min($grandTotal, max(0.0, $rawPaid));
            $changeAmount   = (float)($request->input('change_amount', max(0.0, round($receivedAmount - $grandTotal, 2))));
            $dueAmount      = max(0.0, round($grandTotal - $paidAmount, 2));
            $paymentStatus  = $dueAmount <= 0.001 ? 'Paid' : ($paidAmount > 0.001 ? 'Partial' : 'Unpaid');

            $subtotal = 0;
            foreach ($quotation->items as $item) {
                $subtotal += (float)$item->subtotal;
            }

            $unitService = app(ProductUnitConversionService::class);
            $fifoService = app(\App\Services\FifoInventoryService::class);
            $warehouseId = $unitService->warehouseId($quotation->warehouse_id, $quotation->warehouse_name);

            // User & Cash Register Resolution
            $authUser = \App\Models\User::resolveFromRequest($request) ?? $request->user();
            $currentUserId = $authUser?->id ?? ($request->input('user_id') ?? $quotation->user_id);
            $currentUserName = $request->input('created_by') ?? ($authUser?->name ?? ($quotation->user_name ?? ($quotation->created_by ?? 'Sales Staff')));
            $currentUserRole = $authUser?->role ?? ($quotation->user_role ?? 'Cashier');

            // 🛡️ Guard 1: Walk-in Customer Credit Policy Guard
            $cId = $quotation->customer_id;
            $cName = strtolower(trim((string)($quotation->customer_name ?? '')));
            $isWalkIn = str_contains($cName, 'walk-in') || str_contains($cName, 'walk in');
            if (!$isWalkIn && $cId) {
                $cObj = Customer::find($cId);
                if ($cObj && (str_contains(strtolower($cObj->name), 'walk-in') || str_contains(strtolower($cObj->name), 'walk in'))) {
                    $isWalkIn = true;
                }
            }
            if ($isWalkIn && ($paymentStatus === 'Unpaid' || $paymentStatus === 'Partial' || $dueAmount > 0.01)) {
                DB::rollBack();
                $msg = 'Walk-in Customer များအတွက် အကြွေး (Unpaid / Partial) ဖြင့် ရောင်းချခွင့်မပြုပါ။ ငွေအပြည့်အကျေ ပေးချေရပါမည်။';
                return response()->json([
                    'status' => 'error',
                    'message' => $msg,
                    'errors' => ['customer_id' => [$msg]],
                ], 422);
            }

            // 🛡️ Guard 2: Customer Credit Limit Guard (for non-walk-in credit sales)
            if (!$isWalkIn && $cId && $dueAmount > 0.01) {
                $custObj = Customer::find($cId);
                if ($custObj && $custObj->credit_limit !== null && (float)$custObj->credit_limit > 0) {
                    $existingDueQuery = Sale::where('customer_id', $custObj->id);
                    Customer::applyReceivableScope($existingDueQuery);
                    $currentOutstanding = (float)$existingDueQuery->sum('due_amount');
                    if ($currentOutstanding + $dueAmount > (float)$custObj->credit_limit + 0.001) {
                        $limitStr = number_format((float)$custObj->credit_limit, 2);
                        $currentStr = number_format($currentOutstanding, 2);
                        $newDueStr = number_format($dueAmount, 2);
                        $msg = "Customer '{$custObj->name}' ၏ အကြွေးကန့်သတ်ချက် (Credit Limit: {$limitStr}) ကျော်လွန်နေပါသည်။ လက်ရှိကျန်အကြွေး: {$currentStr}, ယခုအကြွေး: {$newDueStr}.";
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => [
                                'customer_id' => [$msg],
                                'credit_limit' => [$msg],
                            ],
                        ], 422);
                    }
                }
            }

            $cashRegisterId = $request->input('cash_register_id');
            if (!$cashRegisterId && $warehouseId) {
                $regQuery = CashRegister::where('status', 'open')->where('warehouse_id', $warehouseId);
                if ($currentUserId) {
                    $userReg = (clone $regQuery)->where('user_id', $currentUserId)->latest()->first();
                    if ($userReg) {
                        $cashRegisterId = $userReg->id;
                    }
                }
                if (!$cashRegisterId) {
                    $anyReg = $regQuery->latest()->first();
                    $cashRegisterId = $anyReg?->id;
                }
            }

            // Pre-load all products and variants in bulk to eliminate N+1 queries & deadlocks
            $itemProductIds = $quotation->items->pluck('product_id')->filter()->unique()->values()->all();
            sort($itemProductIds);
            $itemProductCodes = $quotation->items->pluck('product_code')->filter()->unique()->values()->all();
            sort($itemProductCodes);
            $productsMap = Product::with(['variants', 'unitLinks.unit'])
                ->where(function ($q) use ($itemProductIds, $itemProductCodes) {
                    if (!empty($itemProductIds)) {
                        $q->whereIn('id', $itemProductIds);
                    }
                    if (!empty($itemProductCodes)) {
                        $q->orWhereIn('code', $itemProductCodes);
                    }
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $productsById = $productsMap->keyBy('id');
            $productsByCode = $productsMap->keyBy('code');

            // 🛡️ Guard 3: Below-Cost Selling Guard
            $managerPin = $request->input('manager_pin');
            $managerPinSetting = (string)\App\Models\Setting::get('manager_pin', '1234');
            $authRole = $authUser
                ? ($authUser->roleModel?->name ?? ($authUser->role ?? $currentUserRole))
                : $currentUserRole;
            $isManagerAuthorized = in_array(strtolower((string)$authRole), ['super admin', 'admin', 'manager'])
                || (!empty($managerPin) && (string)$managerPin === $managerPinSetting);

            if (!$isManagerAuthorized) {
                $totalEstimatedBaseCost = 0.0;
                foreach ($quotation->items as $it) {
                    $prod = (!empty($it->product_id) && isset($productsById[$it->product_id]))
                        ? $productsById[$it->product_id]
                        : ($productsByCode[$it->product_code] ?? null);
                    if (!$prod) continue;
                    $vId = !empty($it->product_variant_id) ? (int)$it->product_variant_id : null;
                    $vObj = ($vId && $prod->relationLoaded('variants')) ? $prod->variants->firstWhere('id', $vId) : null;
                    $costPrice = (float)($vObj?->cost_price ?: ($vObj?->purchase_price ?: ($prod->cost_price ?: ($prod->purchase_price ?: 0))));
                    if ($costPrice <= 0) continue;

                    $cRate = (float)($it->conversion_rate ?? 1.0);
                    if ($cRate <= 0) $cRate = 1.0;
                    $bQty = (float)$it->quantity * $cRate;
                    $totalEstimatedBaseCost += ($bQty * $costPrice);

                    $sub = (float)$it->subtotal;
                    $tx = (float)($it->tax ?? 0);
                    $netRev = max(0.0, $sub - $tx);
                    $effBasePrice = $bQty > 0 ? ($netRev / $bQty) : 0.0;

                    if ($effBasePrice < ($costPrice - 0.01)) {
                        $costStr = number_format($costPrice, 2);
                        $priceStr = number_format($effBasePrice, 2);
                        $pName = $it->product_name;
                        $msg = "ကုန်ပစ္စည်း '{$pName}' ကို အရင်းဈေး ({$costStr}) အောက် လျှော့ချရောင်းချခွင့်မပြုပါ။ (လက်ရှိရောင်းဈေး: {$priceStr}). မန်နေဂျာ PIN ခွင့်ပြုချက် လိုအပ်ပါသည်။";
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => $msg,
                            'errors' => ['items' => [$msg], 'below_cost' => [$msg]],
                        ], 422);
                    }
                }

                // Check Order-Level Discount Below-Cost
                $orderDiscount = (float)($quotation->discount ?? 0);
                $taxExclusiveBaseTotal = max(0.0, (float)$quotation->grand_total - (float)$quotation->tax_amount - (float)$quotation->shipping + $orderDiscount);
                $orderNetRevenue = max(0.0, $taxExclusiveBaseTotal - $orderDiscount);
                if ($totalEstimatedBaseCost > 0 && $orderNetRevenue < ($totalEstimatedBaseCost - 0.01)) {
                    $costStr = number_format($totalEstimatedBaseCost, 2);
                    $revStr = number_format($orderNetRevenue, 2);
                    $msg = "ဘောင်ချာတစ်ခုလုံး၏ အသားတင်ရောင်းရငွေ ({$revStr}) သည် စုစုပေါင်းအရင်းစရိတ် ({$costStr}) အောက် လျော့နည်းနေပါသည်။ မန်နေဂျာ PIN ခွင့်ပြုချက် လိုအပ်ပါသည်။";
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => $msg,
                        'errors' => ['discount' => [$msg], 'below_cost' => [$msg]],
                    ], 422);
                }
            }

            // 🛡️ Stock Pre-Check: Aggregate multi-unit rows for the same product/variant (e.g. 1 Box + 5 Pcs)
            if ($warehouseId) {
                $requiredStockByItem = [];
                foreach ($quotation->items as $it) {
                    $prod = (!empty($it->product_id) && isset($productsById[$it->product_id]))
                        ? $productsById[$it->product_id]
                        : ($productsByCode[$it->product_code] ?? null);
                    if (!$prod) continue;

                    $cRate = (float)($it->conversion_rate ?? 1.0);
                    if ($cRate <= 0) $cRate = 1.0;
                    $bQty = (float)$it->quantity * $cRate;
                    $vId = !empty($it->product_variant_id) ? (int)$it->product_variant_id : null;
                    $aggKey = $prod->id . '_' . ($vId ?? '0');

                    if (!isset($requiredStockByItem[$aggKey])) {
                        $requiredStockByItem[$aggKey] = [
                            'product' => $prod,
                            'variant_id' => $vId,
                            'total_base_qty' => 0.0,
                        ];
                    }
                    $requiredStockByItem[$aggKey]['total_base_qty'] += $bQty;
                }

                $reqProductIds = array_unique(array_map(fn($r) => $r['product']->id, $requiredStockByItem));
                $stockBalances = \App\Models\StockBalance::where('warehouse_id', $warehouseId)
                    ->whereIn('product_id', $reqProductIds)
                    ->orderBy('product_id')
                    ->orderBy('product_variant_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(fn($sb) => $sb->product_id . '_' . ($sb->product_variant_id ?? '0'));

                foreach ($requiredStockByItem as $req) {
                    $prod = $req['product'];
                    $vId = $req['variant_id'];
                    $totalBaseNeeded = $req['total_base_qty'];

                    $balKey = $prod->id . '_' . ($vId ?? '0');
                    $sb = $stockBalances->get($balKey);
                    $available = $sb !== null ? (float)$sb->quantity : (float)$prod->stock_quantity;

                    if ($totalBaseNeeded > $available + 0.0001) {
                        $varName = '';
                        if ($vId && $prod->relationLoaded('variants')) {
                            $vObj = $prod->variants->firstWhere('id', $vId);
                            if ($vObj) $varName = " ({$vObj->name})";
                        }
                        $whName = $quotation->warehouse_name ?? 'Selected Warehouse';
                        DB::rollBack();
                        return response()->json([
                            'status' => 'error',
                            'message' => "Insufficient stock for '{$prod->name}{$varName}' in '{$whName}'. Total requested: {$totalBaseNeeded}, Available: {$available}.",
                        ], 422);
                    }
                }
            }

            // Create Sale record with full traceability & register links
            $sale = Sale::create([
                'reference_no'     => $refNo,
                'quotation_id'     => $quotation->id,
                'customer_id'      => $quotation->customer_id,
                'customer_name'    => $quotation->customer_name,
                'warehouse_id'     => $warehouseId,
                'warehouse_name'   => $quotation->warehouse_name,
                'date'             => date('Y-m-d H:i:s'),
                'status'           => 'Completed',
                'tax_rate'         => (float)$quotation->tax_rate,
                'tax_amount'       => (float)$quotation->tax_amount,
                'discount'         => (float)$quotation->discount,
                'shipping'         => (float)$quotation->shipping,
                'subtotal'         => $subtotal > 0 ? $subtotal : $grandTotal,
                'grand_total'      => $grandTotal,
                'paid_amount'      => $paidAmount,
                'due_amount'       => $dueAmount,
                'change_amount'    => $changeAmount,
                'payment_status'   => $paymentStatus,
                'payment_type'     => $paymentType,
                'notes'            => 'Converted from Quotation: ' . $quotation->reference_no . ($quotation->notes ? ' | ' . $quotation->notes : ''),
                'pos_flag'         => true,
                'cash_register_id' => $cashRegisterId,
                'created_by'       => $currentUserName,
                'user_id'          => $currentUserId,
                'user_name'        => $currentUserName,
                'user_role'        => $currentUserRole,
                'total_cogs'       => 0,
            ]);

            $totalSaleCogs = 0.0;
            $touchedProductIds = [];
            $touchedVariantIds = [];

            foreach ($quotation->items as $item) {
                $product = (!empty($item->product_id) && isset($productsById[$item->product_id]))
                    ? $productsById[$item->product_id]
                    : ($productsByCode[$item->product_code] ?? null);

                $convRate = (float)($item->conversion_rate ?? 1.0);
                if ($convRate <= 0) $convRate = 1.0;
                $qty      = (float)$item->quantity;
                $unitPrice = (float)$item->unit_price;
                $baseQty  = $item->base_quantity !== null && (float)$item->base_quantity > 0
                    ? (float)$item->base_quantity
                    : ($qty * $convRate);
                $basePrice = $item->base_unit_price !== null && (float)$item->base_unit_price > 0
                    ? (float)$item->base_unit_price
                    : ($unitPrice / $convRate);

                $saleItem = SaleItem::create([
                    'sale_id'            => $sale->id,
                    'product_id'         => $product?->id ?? $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'unit_id'            => $item->unit_id,
                    'unit_name'          => $item->unit_name ?? 'pcs',
                    'conversion_rate'    => $convRate,
                    'base_quantity'      => $baseQty,
                    'base_unit_price'    => $basePrice,
                    'product_code'       => $item->product_code,
                    'product_name'       => $item->product_name,
                    'variant_name'       => $item->variant_name,
                    'unit_price'         => $unitPrice,
                    'quantity'           => $qty,
                    'tax'                => (float)$item->tax,
                    'discount'           => (float)$item->discount,
                    'subtotal'           => (float)$item->subtotal,
                    'base_unit_cost'     => 0,
                    'total_cogs'         => 0,
                ]);

                // Reduce inventory stock + FIFO tracking for completed sale
                if ($product) {
                    $unitService->applyStock(
                        product: $product,
                        warehouseId: $warehouseId,
                        variantId: $item->product_variant_id,
                        baseQuantity: $baseQty,
                        direction: 'out',
                        movementType: 'sale',
                        referenceType: 'sale',
                        referenceId: (string)$sale->id,
                        note: 'Converted from Quotation ' . $quotation->reference_no,
                        notifyRestock: true,
                        allowOverdraft: false,
                        syncAggregates: false
                    );
                    $touchedProductIds[] = $product->id;
                    if ($item->product_variant_id) {
                        $touchedVariantIds[] = (int)$item->product_variant_id;
                    }

                    // FIFO Cost Depletion (Calculates COGS for P&L with variant cost_price and purchase_price fallbacks)
                    if ($warehouseId && $baseQty > 0) {
                        $fallbackCost = 0.0;
                        if ($item->product_variant_id && $product->relationLoaded('variants')) {
                            $varObj = $product->variants->firstWhere('id', $item->product_variant_id);
                            $fallbackCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: 0));
                        }
                        if ($fallbackCost <= 0) {
                            $fallbackCost = (float)($product->cost_price ?: ($product->purchase_price ?: 0));
                        }

                        $depletion = $fifoService->depleteFifo(
                            warehouseId:   (int)$warehouseId,
                            productId:     (int)$product->id,
                            variantId:     $item->product_variant_id,
                            neededBaseQty: (float)$baseQty,
                            saleId:        (int)$sale->id,
                            saleItemId:    (int)$saleItem->id,
                            saleDate:      $sale->date,
                            fallbackCost:  $fallbackCost
                        );

                        $saleItem->update([
                            'base_unit_cost' => $depletion['avg_unit_cost'],
                            'total_cogs'     => $depletion['total_cogs'],
                        ]);
                        $totalSaleCogs += (float)$depletion['total_cogs'];
                    }
                }
            }

            // Batch sync aggregates once outside loop (eliminates N+1 queries)
            if (!empty($touchedProductIds) || !empty($touchedVariantIds)) {
                $unitService->syncAggregatesForProducts(array_values(array_unique($touchedProductIds)), array_values(array_unique($touchedVariantIds)));
            }

            // Persist total COGS to the sale header for P&L reporting
            if ($totalSaleCogs > 0) {
                $sale->update(['total_cogs' => round($totalSaleCogs, 2)]);
            }

            // Record Sale Payment transaction
            if ($paidAmount > 0) {
                SalePayment::create([
                    'sale_id'         => $sale->id,
                    'reference_no'    => 'PAY-' . date('Ymd') . '-' . rand(1000, 9999),
                    'payment_date'    => $sale->date,
                    'payment_type'    => $paymentType,
                    'amount'          => $paidAmount,
                    'received_amount' => $receivedAmount,
                    'change_amount'   => $changeAmount,
                    'notes'           => 'Quotation conversion: ' . $quotation->reference_no,
                    'created_by'      => $currentUserName,
                    'user_id'         => $currentUserId,
                    'user_name'       => $currentUserName,
                    'user_role'       => $currentUserRole,
                ]);

                // Increment Cash Register only with actual net cash paid (not inflated by customer change)
                if (strtolower(trim($paymentType)) === 'cash' && $cashRegisterId) {
                    $register = CashRegister::find($cashRegisterId);
                    if ($register && $register->status === 'open') {
                        $register->increment('expected_cash', $paidAmount);
                    }
                }
            }

            // Update customer total spend and visit count
            if (!empty($quotation->customer_id)) {
                $cust = Customer::where('id', $quotation->customer_id)->lockForUpdate()->first();
                if ($cust) {
                    $cust->increment('total_spent', $grandTotal);
                    $cust->increment('total_visits', 1);
                }
            }

            // Mark quotation as Ordered (prevents future re-conversion)
            $quotation->update(['status' => 'Ordered']);

            DB::commit();

            $this->clearQuotationRelatedCaches();

            return response()->json([
                'status'  => 'success',
                'message' => 'Quotation successfully converted to Sale ' . $sale->reference_no,
                'data'    => [
                    'sale'      => $sale->load('items'),
                    'quotation' => $quotation->fresh('items'),
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return $e->getResponse();
        } catch (\Throwable $e) {
            DB::rollBack();
            $statusCode = 500;
            if (str_contains($e->getMessage(), 'Insufficient stock') ||
                str_contains($e->getMessage(), 'Stock') ||
                str_contains($e->getMessage(), 'stock') ||
                str_contains($e->getMessage(), 'Credit Limit') ||
                str_contains($e->getMessage(), 'credit limit') ||
                str_contains($e->getMessage(), 'အရင်းဈေး') ||
                str_contains($e->getMessage(), 'warehouse')) {
                $statusCode = 422;
            }
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $statusCode);
        }
    }

    /**
     * Clear application caches related to quotations, inventory, and sales.
     */
    private function clearQuotationRelatedCaches(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forget('pos_init_data');
            \Illuminate\Support\Facades\Cache::forget('products_cache');
            \Illuminate\Support\Facades\Cache::forget('dashboard_kpi');
            \Illuminate\Support\Facades\Cache::forget('sales_reports');
        } catch (\Throwable $e) {}
    }
}
