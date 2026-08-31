<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseReturn::query();

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
                  ->orWhere('purchase_reference_no', 'like', "%{$search}%")
                  ->orWhere('supplier_name', 'like', "%{$search}%")
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
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
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
            'purchase_reference_no' => 'nullable|string|max:100',
            'supplier_id' => 'nullable|integer',
            'supplier_name' => 'required|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'grand_total' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:Completed,Pending,Received',
            'payment_status' => 'nullable|string',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
        ]);

        $prefix = Setting::get('purchase_return_prefix', 'PR_');
        $refNo = !empty($validated['reference_no'])
            ? $validated['reference_no']
            : ($prefix . date('Ymd') . '-' . rand(1000, 9999));
        $grandTotal = (float)$validated['grand_total'];
        $paidAmount = (float)($validated['paid_amount'] ?? $grandTotal);
        $dueAmount = max(0, $grandTotal - $paidAmount);

        $paymentStatus = $validated['payment_status'] ?? ($dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $return = PurchaseReturn::create([
            'reference_no' => $refNo,
            'purchase_reference_no' => $validated['purchase_reference_no'] ?? null,
            'supplier_id' => $validated['supplier_id'] ?? null,
            'supplier_name' => $validated['supplier_name'],
            'warehouse_name' => $validated['warehouse_name'],
            'date' => $validated['date'],
            'status' => $validated['status'] ?? 'Completed',
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $validated['created_by'] ?? 'Admin User',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Purchase return recorded successfully',
            'data' => $return,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        $validated = $request->validate([
            'reference_no' => 'nullable|string|max:100',
            'purchase_reference_no' => 'nullable|string|max:100',
            'supplier_id' => 'nullable|integer',
            'supplier_name' => 'required|string|max:255',
            'warehouse_name' => 'required|string|max:255',
            'date' => 'required|string',
            'grand_total' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:Completed,Pending,Received',
            'payment_status' => 'nullable|string',
            'notes' => 'nullable|string',
            'created_by' => 'nullable|string|max:255',
        ]);

        $grandTotal = (float)$validated['grand_total'];
        $paidAmount = (float)($validated['paid_amount'] ?? $return->paid_amount);
        $dueAmount = max(0, $grandTotal - $paidAmount);
        $paymentStatus = $validated['payment_status'] ?? ($dueAmount == 0 ? 'Paid' : ($paidAmount > 0 ? 'Partial' : 'Unpaid'));

        $return->update([
            'reference_no' => $validated['reference_no'] ?? $return->reference_no,
            'purchase_reference_no' => $validated['purchase_reference_no'] ?? $return->purchase_reference_no,
            'supplier_id' => $validated['supplier_id'] ?? $return->supplier_id,
            'supplier_name' => $validated['supplier_name'],
            'warehouse_name' => $validated['warehouse_name'],
            'date' => $validated['date'],
            'status' => $validated['status'] ?? $return->status,
            'grand_total' => $grandTotal,
            'paid_amount' => $paidAmount,
            'due_amount' => $dueAmount,
            'payment_status' => $paymentStatus,
            'notes' => $validated['notes'] ?? $return->notes,
            'created_by' => $validated['created_by'] ?? $return->created_by,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Purchase return updated successfully',
            'data' => $return,
        ]);
    }

    public function destroy($id)
    {
        $return = PurchaseReturn::find($id);
        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }
        $return->delete();
        return response()->json(['status' => 'success', 'message' => 'Purchase return deleted']);
    }
}

