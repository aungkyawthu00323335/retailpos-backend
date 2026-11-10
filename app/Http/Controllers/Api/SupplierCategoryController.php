<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierCategory;
use Illuminate\Http\Request;

class SupplierCategoryController extends Controller
{
    /**
     * Display a listing of supplier categories with suppliers count.
     */
    public function index(Request $request)
    {
        $query = SupplierCategory::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $categories = $query->orderBy('id', 'asc')->get();

        // Calculate mapped supplier counts and total AP balance due per category in 1 single fast query
        $supplierStats = Supplier::selectRaw('category, count(*) as count, coalesce(sum(balance_due), 0) as total_due')
            ->whereNotNull('category')
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        $mapped = $categories->map(function ($cat) use ($supplierStats) {
            $stat = $supplierStats->get($cat->name);
            $cat->suppliers_count = $stat ? (int)$stat->count : 0;
            $cat->total_due = $stat ? (float)$stat->total_due : 0.00;
            return $cat;
        });

        $summaryData = SupplierCategory::selectRaw("
            COUNT(*) as total_categories,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_categories
        ")->first();

        $totalApDue = (float) Supplier::sum('balance_due');

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_categories' => (int)($summaryData->total_categories ?? 0),
                'active_categories' => (int)($summaryData->active_categories ?? 0),
                'total_suppliers' => Supplier::count(),
                'total_ap_due' => $totalApDue,
            ],
            'data' => $mapped,
        ]);
    }

    /**
     * Store a newly created supplier category.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:supplier_categories,name',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ], [
            'name.unique' => 'ဤကုန်သွင်းသူအုပ်စုအမည် (Supplier Group Name) ရှိပြီးဖြစ်ပါသည်။ အခြားအမည်တစ်ခု သတ်မှတ်ပေးပါ။',
        ]);

        $category = SupplierCategory::create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#2563EB',
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'Active',
        ]);

        $category->suppliers_count = 0;
        $category->total_due = 0.00;

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier category created successfully',
            'data' => $category,
        ], 201);
    }

    /**
     * Display the specified supplier category.
     */
    public function show($id)
    {
        $category = SupplierCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier category not found',
            ], 404);
        }

        $supplierStat = Supplier::where('category', $category->name)
            ->selectRaw('count(*) as count, coalesce(sum(balance_due), 0) as total_due')
            ->first();
        $category->suppliers_count = (int)($supplierStat->count ?? 0);
        $category->total_due = (float)($supplierStat->total_due ?? 0.00);

        return response()->json([
            'status' => 'success',
            'data' => $category,
        ]);
    }

    /**
     * Update the specified supplier category.
     */
    public function update(Request $request, $id)
    {
        $category = SupplierCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier category not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:supplier_categories,name,' . $id,
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
        ], [
            'name.unique' => 'ဤကုန်သွင်းသူအုပ်စုအမည် (Supplier Group Name) ရှိပြီးဖြစ်ပါသည်။ အခြားအမည်တစ်ခု သတ်မှတ်ပေးပါ။',
        ]);

        $oldName = $category->name;
        $category->update($validated);

        // If category name was renamed, update linked suppliers
        if (isset($validated['name']) && $validated['name'] !== $oldName) {
            Supplier::where('category', $oldName)->update(['category' => $validated['name']]);
        }

        $supplierStat = Supplier::where('category', $category->name)
            ->selectRaw('count(*) as count, coalesce(sum(balance_due), 0) as total_due')
            ->first();
        $category->suppliers_count = (int)($supplierStat->count ?? 0);
        $category->total_due = (float)($supplierStat->total_due ?? 0.00);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier category updated successfully',
            'data' => $category,
        ]);
    }

    /**
     * Toggle the active status of a supplier category.
     */
    public function toggleStatus($id)
    {
        $category = SupplierCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier category not found',
            ], 404);
        }

        $newStatus = ($category->status === 'Active') ? 'Inactive' : 'Active';
        $category->status = $newStatus;
        $category->save();

        $supplierStat = Supplier::where('category', $category->name)
            ->selectRaw('count(*) as count, coalesce(sum(balance_due), 0) as total_due')
            ->first();
        $category->suppliers_count = (int)($supplierStat->count ?? 0);
        $category->total_due = (float)($supplierStat->total_due ?? 0.00);

        return response()->json([
            'status' => 'success',
            'message' => "Supplier category marked as {$newStatus}",
            'data' => $category,
        ]);
    }

    /**
     * Remove the specified supplier category.
     * Senior Accountant Safeguard: Protects against orphaned vendor classifications and AP ledger corruption.
     */
    public function destroy($id)
    {
        $category = SupplierCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier category not found',
            ], 404);
        }

        $supplierCount = Supplier::where('category', $category->name)->count();
        $totalDue = (float) Supplier::where('category', $category->name)->sum('balance_due');

        if ($supplierCount > 0 || $totalDue > 0) {
            $dueStr = number_format($totalDue, 2);
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete supplier category '{$category->name}'. It is currently assigned to {$supplierCount} supplier(s) with an outstanding Accounts Payable liability of \${$dueStr}. Please reassign suppliers or settle outstanding liabilities before deletion.",
            ], 422);
        }

        $category->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier category deleted successfully',
        ]);
    }
}
