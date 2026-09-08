<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuotationController extends Controller
{
    /**
     * Display a paginated listing of quotations with optional filters and sorting.
     */
    public function index(Request $request)
    {
        $query = Quotation::with('items');

        // Filter: Warehouse
        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        // Filter: Status
        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        // Filter: Search (Reference # or Customer)
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

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
        ];

        $sortByInput = strtolower(trim((string)$request->input('sort_by', 'id')));
        $sortBy = $allowedSortCols[$sortByInput] ?? 'id';
        $sortOrder = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage = (int)$request->input('per_page', 25);
        $page = (int)$request->input('page', 1);

        $quotations = $query->orderBy($sortBy, $sortOrder)->paginate($perPage, ['*'], 'page', $page);

        // HTTP ETag & Cache-Control for fast conditional responses
        $total = $quotations->total();
        $etag = md5("quo|{$page}|{$perPage}|{$total}|{$sortBy}|{$sortOrder}");

        return response()->json([
            'status' => 'success',
            'data' => $quotations->items(),
            'pagination' => [
                'current_page' => $quotations->currentPage(),
                'per_page' => $quotations->perPage(),
                'total' => $quotations->total(),
                'last_page' => $quotations->lastPage(),
            ],
        ])
        ->header('Cache-Control', 'private, max-age=60')
        ->header('ETag', '"' . $etag . '"');
    }

    /**
     * Store a newly created quotation with its line items.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|integer',
            'customer_name' => 'required|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'tax_rate' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'grand_total' => 'required|numeric|min:0',
            'status' => 'nullable|string|in:Sent,Pending,Ordered,Expired',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.tax' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $refNo = $request->input('reference_no');
            if (empty($refNo)) {
                $prefix = Setting::get('quotation_prefix', 'QUO_');
                $refNo = $prefix . date('Ymd') . '-' . rand(1000, 9999);
            }

            $quotation = Quotation::create([
                'reference_no' => $refNo,
                'customer_id' => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $validated['date'],
                'tax_rate' => (float)($validated['tax_rate'] ?? 0),
                'tax_amount' => (float)($validated['tax_amount'] ?? 0),
                'discount' => (float)($validated['discount'] ?? 0),
                'shipping' => (float)($validated['shipping'] ?? 0),
                'grand_total' => (float)$validated['grand_total'],
                'status' => $validated['status'] ?? 'Sent',
                'notes' => $validated['notes'] ?? null,
                'created_by' => $validated['created_by'] ?? 'Sales Staff',
            ]);

            $itemsData = [];
            $now = now();
            foreach ($validated['items'] as $item) {
                $itemsData[] = [
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'unit_price' => (float)$item['unit_price'],
                    'quantity' => (float)$item['quantity'],
                    'tax' => (float)($item['tax'] ?? 0),
                    'discount' => (float)($item['discount'] ?? 0),
                    'subtotal' => (float)$item['subtotal'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            QuotationItem::insert($itemsData);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation created successfully',
                'data' => $quotation->load('items'),
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
        $quotation = Quotation::with('items')->find($id);
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

        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:255',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'required|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'tax_rate' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'grand_total' => 'required|numeric|min:0',
            'status' => 'nullable|string|in:Sent,Pending,Ordered,Expired',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_code' => 'required|string',
            'items.*.product_name' => 'required|string',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.tax' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.subtotal' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $updateData = [
                'customer_id' => $validated['customer_id'] ?? null,
                'customer_name' => $validated['customer_name'],
                'warehouse_name' => $validated['warehouse_name'],
                'date' => $validated['date'],
                'tax_rate' => (float)($validated['tax_rate'] ?? 0),
                'tax_amount' => (float)($validated['tax_amount'] ?? 0),
                'discount' => (float)($validated['discount'] ?? 0),
                'shipping' => (float)($validated['shipping'] ?? 0),
                'grand_total' => (float)$validated['grand_total'],
                'status' => $validated['status'] ?? $quotation->status,
                'notes' => $validated['notes'] ?? null,
            ];

            if (!empty($validated['reference_no'])) {
                $updateData['reference_no'] = $validated['reference_no'];
            }

            if (!empty($validated['created_by'])) {
                $updateData['created_by'] = $validated['created_by'];
            }

            $quotation->update($updateData);

            // Replace items in batch
            $quotation->items()->delete();

            $itemsData = [];
            $now = now();
            foreach ($validated['items'] as $item) {
                $itemsData[] = [
                    'quotation_id' => $quotation->id,
                    'product_id' => $item['product_id'] ?? null,
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'unit_price' => (float)$item['unit_price'],
                    'quantity' => (float)$item['quantity'],
                    'tax' => (float)($item['tax'] ?? 0),
                    'discount' => (float)($item['discount'] ?? 0),
                    'subtotal' => (float)$item['subtotal'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            QuotationItem::insert($itemsData);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation updated successfully',
                'data' => $quotation->fresh('items'),
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
     */
    public function convertToSale(Request $request, $id)
    {
        $quotation = Quotation::with('items')->find($id);
        if (!$quotation) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found'], 404);
        }

        DB::beginTransaction();
        try {
            $prefix = Setting::get('sale_prefix', 'SA_');
            $refNo = $prefix . rand(1000000, 9999999);

            $grandTotal = (float)$quotation->grand_total;
            $paidAmount = (float)($request->input('paid_amount', $grandTotal));
            $dueAmount = max(0, $grandTotal - $paidAmount);
            $paymentStatus = $paidAmount >= $grandTotal ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid');

            $subtotal = 0;
            foreach ($quotation->items as $item) {
                $subtotal += (float)$item->subtotal;
            }

            $sale = Sale::create([
                'reference_no' => $refNo,
                'customer_id' => $quotation->customer_id,
                'customer_name' => $quotation->customer_name,
                'warehouse_name' => $quotation->warehouse_name,
                'date' => date('Y-m-d H:i:s'),
                'status' => 'Completed',
                'tax_rate' => (float)$quotation->tax_rate,
                'tax_amount' => (float)$quotation->tax_amount,
                'discount' => (float)$quotation->discount,
                'shipping' => (float)$quotation->shipping,
                'subtotal' => $subtotal > 0 ? $subtotal : $grandTotal,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'change_amount' => 0,
                'payment_status' => $paymentStatus,
                'payment_type' => $request->input('payment_type', 'Cash'),
                'notes' => 'Converted from Quotation: ' . $quotation->reference_no . ($quotation->notes ? ' | ' . $quotation->notes : ''),
                'created_by' => $request->input('created_by', $quotation->created_by ?? 'Sales Staff'),
            ]);

            $saleItemsData = [];
            $now = now();
            foreach ($quotation->items as $item) {
                $saleItemsData[] = [
                    'sale_id' => $sale->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => null,
                    'unit_id' => null,
                    'unit_name' => 'pcs',
                    'conversion_rate' => 1,
                    'base_quantity' => (float)$item->quantity,
                    'base_unit_price' => (float)$item->unit_price,
                    'product_code' => $item->product_code,
                    'product_name' => $item->product_name,
                    'unit_price' => (float)$item->unit_price,
                    'quantity' => (float)$item->quantity,
                    'tax' => (float)$item->tax,
                    'discount' => (float)$item->discount,
                    'subtotal' => (float)$item->subtotal,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($saleItemsData)) {
                SaleItem::insert($saleItemsData);
            }

            // Mark quotation status as Ordered
            $quotation->update(['status' => 'Ordered']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Quotation successfully converted to Sale ' . $sale->reference_no,
                'data' => [
                    'sale' => $sale->load('items'),
                    'quotation' => $quotation->fresh('items'),
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
