<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaleReturn;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;

class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleReturn::query();

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $query->where('warehouse_name', $request->input('warehouse'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'like', "%{$search}%")
                  ->orWhere('sale_reference_no', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('warehouse_name', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $returns = $query->orderBy('id', 'desc')->paginate((int)$request->input('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $returns->items(),
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'per_page' => $returns->perPage(),
                'total' => $returns->total(),
                'last_page' => $returns->lastPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $return = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Sale return record not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $return,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:100',
            'sale_reference_no' => 'nullable|string|max:100',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'required|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'grand_total' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'due_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string|in:Paid,Partial,Unpaid',
            'status' => 'nullable|string|in:Completed,Received,Pending',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
        ]);

        $prefix = Setting::get('sale_return_prefix', 'SR_');
        $refNo = !empty($validated['reference_no'])
            ? $validated['reference_no']
            : ($prefix . date('Ymd') . '-' . rand(1000, 9999));

        $grandTotal = (float)$validated['grand_total'];
        $paidAmount = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : $grandTotal;
        $dueAmount = isset($validated['due_amount']) ? (float)$validated['due_amount'] : max(0, $grandTotal - $paidAmount);
        $paymentStatus = $validated['payment_status'] ?? ($dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $txDate = trim($validated['date']);
        if (!str_contains($txDate, ':')) {
            $txDate .= ' ' . now()->format('H:i:s');
        }

        $return = SaleReturn::create([
            'reference_no' => $refNo,
            'sale_reference_no' => $validated['sale_reference_no'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
            'customer_name' => $validated['customer_name'],
            'warehouse_name' => $validated['warehouse_name'],
            'date' => $txDate,
            'status' => $validated['status'] ?? 'Completed',
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $validated['created_by'] ?? 'Cashier',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Sale return recorded successfully',
            'data' => $return,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $return = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:100',
            'sale_reference_no' => 'nullable|string|max:100',
            'customer_id' => 'nullable|integer',
            'customer_name' => 'nullable|string|max:255',
            'warehouse_name' => 'nullable|string|max:255',
            'date' => 'nullable|string',
            'grand_total' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'due_amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|string|in:Paid,Partial,Unpaid',
            'status' => 'nullable|string|in:Completed,Received,Pending',
            'notes' => 'nullable|string',
        ]);

        if (isset($validated['grand_total'])) {
            $grandTotal = (float)$validated['grand_total'];
            $paidAmount = isset($validated['paid_amount']) ? (float)$validated['paid_amount'] : (float)$return->paid_amount;
            $dueAmount = max(0, $grandTotal - $paidAmount);
            $validated['due_amount'] = $dueAmount;
            if (!isset($validated['payment_status'])) {
                $validated['payment_status'] = $dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid');
            }
        }

        $return->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Sale return updated successfully',
            'data' => $return,
        ]);
    }

    public function destroy($id)
    {
        $return = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }
        $return->delete();
        return response()->json(['status' => 'success', 'message' => 'Sale return deleted']);
    }
}
