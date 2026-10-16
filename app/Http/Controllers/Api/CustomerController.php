<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request)
    {
        $query = Customer::withCount(['sales'])
            ->withSum(['sales as receivable_due_sum' => function ($q) {
                Customer::applyReceivableScope($q);
            }], 'due_amount');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('customer_id', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('tier', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tier') && $request->input('tier') !== 'All') {
            $query->where('tier', $request->input('tier'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'customer_id', 'name', 'phone', 'email', 'tier', 'reward_points', 'total_spent', 'total_visits', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $perPage = (int)$request->input('per_page', 25);
        if ($perPage <= 0) $perPage = 25;
        if ($perPage > 100) $perPage = 100;

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->transform(function ($customer) {
            $customer->total_due = $customer->total_due;
            return $customer;
        });

        $summaryData = Customer::selectRaw("
            COUNT(*) as total_customers,
            SUM(CASE WHEN tier IN ('VIP Platinum', 'Gold VIP') THEN 1 ELSE 0 END) as vip_members,
            0 as total_points,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_customers
        ")->first();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_customers' => (int)($summaryData->total_customers ?? 0),
                'vip_members' => (int)($summaryData->vip_members ?? 0),
                'total_points' => (float)($summaryData->total_points ?? 0),
                'active_customers' => (int)($summaryData->active_customers ?? 0),
            ],
            'data' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Generate the next sequential unique Customer ID (e.g. CUST-052).
     */
    public function generateCustomerId(Request $request)
    {
        $existing = Customer::whereNotNull('customer_id')->pluck('customer_id');
        $maxNum = (int)(Customer::max('id') ?? 0);

        foreach ($existing as $cid) {
            if (preg_match('/(?:CUST-)?([0-9]+)$/i', (string)$cid, $matches)) {
                $n = (int)$matches[1];
                if ($n > $maxNum) {
                    $maxNum = $n;
                }
            }
        }

        $nextNum = $maxNum + 1;
        $candidateId = 'CUST-' . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);
        while (Customer::where('customer_id', $candidateId)->exists()) {
            $nextNum++;
            $candidateId = 'CUST-' . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'customer_id' => $candidateId,
            ],
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|string|max:50|unique:customers,customer_id',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'tier' => 'nullable|string|max:100',
            'reward_points' => 'nullable|integer',
            'total_spent' => 'nullable|numeric',
            'total_visits' => 'nullable|integer',
            'avatar' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
            'notes' => 'nullable|string',
        ]);

        $customerId = !empty($validated['customer_id']) ? trim($validated['customer_id']) : null;
        if (empty($customerId)) {
            $existing = Customer::whereNotNull('customer_id')->pluck('customer_id');
            $maxNum = (int)(Customer::max('id') ?? 0);
            foreach ($existing as $cid) {
                if (preg_match('/(?:CUST-)?([0-9]+)$/i', (string)$cid, $matches)) {
                    $n = (int)$matches[1];
                    if ($n > $maxNum) $maxNum = $n;
                }
            }
            $nextNum = $maxNum + 1;
            $customerId = 'CUST-' . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);
            while (Customer::where('customer_id', $customerId)->exists()) {
                $nextNum++;
                $customerId = 'CUST-' . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);
            }
        }

        $customer = Customer::create([
            'customer_id' => $customerId,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'tier' => $validated['tier'] ?? 'Silver Regular',
            'reward_points' => $validated['reward_points'] ?? 0,
            'total_spent' => $validated['total_spent'] ?? 0.00,
            'total_visits' => $validated['total_visits'] ?? 1,
            'avatar' => $validated['avatar'] ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
            'status' => $validated['status'] ?? 'Active',
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Customer created successfully',
            'data' => $customer,
        ], 201);
    }

    /**
     * Display the specified customer.
     */
    public function show($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        $customer->total_due = $customer->total_due;

        return response()->json([
            'status' => 'success',
            'data' => $customer,
        ]);
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        $validated = $request->validate([
            'customer_id' => 'sometimes|nullable|string|max:50|unique:customers,customer_id,' . $id,
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'tier' => 'nullable|string|max:100',
            'reward_points' => 'nullable|integer',
            'total_spent' => 'nullable|numeric',
            'total_visits' => 'nullable|integer',
            'avatar' => 'nullable|string',
            'status' => 'nullable|string|in:Active,Inactive',
            'notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Customer updated successfully',
            'data' => $customer,
        ]);
    }

    /**
     * Toggle active/inactive status of the customer.
     */
    public function toggleStatus($id)
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        $customer->status = ($customer->status === 'Active') ? 'Inactive' : 'Active';
        $customer->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer status updated to ' . $customer->status,
            'data' => $customer,
        ]);
    }

    /**
     * Remove the specified customer with Senior Accountant safeguards.
     */
    public function destroy($id)
    {
        $customer = Customer::withCount(['sales', 'saleReturns', 'quotations'])->find($id);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        $salesCount = (int)($customer->sales_count ?? 0);
        $returnsCount = (int)($customer->sale_returns_count ?? 0);
        $quotationsCount = (int)($customer->quotations_count ?? 0);
        $totalDue = (float)($customer->sales()->sum('due_amount') ?? 0.0);

        if ($salesCount > 0 || $returnsCount > 0 || $quotationsCount > 0 || $totalDue > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete customer because they have ' . $salesCount . ' active sales invoice(s), ' . $returnsCount . ' return(s), ' . $quotationsCount . ' quotation(s), or outstanding Accounts Receivable due debt ($' . number_format($totalDue, 2) . '). This is protected by accounting safeguards to prevent ledger corruption. Please deactivate the customer instead.',
                'sales_count' => $salesCount,
                'sale_returns_count' => $returnsCount,
                'quotations_count' => $quotationsCount,
                'total_due' => $totalDue,
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer deleted successfully',
        ]);
    }

    /**
     * Download sample Excel / CSV template for Customer Import with UTF-8 BOM.
     */
    public function downloadTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customer_import_template_utf8.csv"',
        ];

        $columns = [
            'Customer Name',
            'Phone',
            'Email',
            'Customer Group',
            'Reward Points',
            'Total Spent',
            'Total Visits',
            'Status',
            'Notes',
        ];

        $sampleRows = [
            [
                'Mr. David Aung',
                '+95 9 1234 5678',
                'david.aung@gmail.com',
                'VIP Platinum',
                '950',
                '3420.00',
                '48',
                'Active',
                'Corporate regular client',
            ],
            [
                'Ms. Emily Watson',
                '+95 9 9876 5432',
                'emily.w@restaurant.com',
                'Gold VIP',
                '680',
                '1890.00',
                '26',
                'Active',
                'Executive partner account',
            ],
            [
                'Ko Aung Kyaw',
                '+95 9 5555 4444',
                'aungkyaw@myanmar.net',
                'Silver Regular',
                '320',
                '840.00',
                '14',
                'Active',
                'Frequent buyer',
            ],
            [
                'Daw Khin Khin',
                '+95 9 7788 9900',
                'khin.khin@yangon.biz',
                'Gold VIP',
                '540',
                '1450.00',
                '19',
                'Active',
                'Retail boutique owner',
            ],
            [
                'Mr. Michael Chen',
                '+95 9 3322 1100',
                'm.chen@venture.sg',
                'Bronze Club',
                '120',
                '310.00',
                '5',
                'Active',
                'New club member',
            ],
        ];

        $callback = function () use ($columns, $sampleRows) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns);
            foreach ($sampleRows as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export customers to CSV with UTF-8 BOM and filtered criteria.
     */
    public function export(Request $request)
    {
        $query = Customer::withCount(['sales']);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('tier', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tier') && $request->input('tier') !== 'All') {
            $query->where('tier', $request->input('tier'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['id', 'name', 'phone', 'email', 'tier', 'reward_points', 'total_spent', 'total_visits', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'asc');
        }

        $customers = $query->get();

        $columns = [
            'Customer ID',
            'Customer Name',
            'Phone',
            'Email',
            'Customer Group',
            'Reward Points',
            'Total Spent',
            'Total Due',
            'Sales Count',
            'Total Visits',
            'Status',
            'Notes',
        ];

        $formatPhone = function ($phone) {
            $phone = trim((string)$phone);
            if ($phone === '') return '';
            if (str_starts_with($phone, '0') || str_starts_with($phone, '+') || str_contains($phone, ' ')) {
                return '="' . $phone . '"';
            }
            return $phone;
        };

        $formatNum = function ($num) {
            $floatVal = (float)$num;
            return ($floatVal == (int)$floatVal) ? (string)(int)$floatVal : (string)number_format($floatVal, 2, '.', '');
        };

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customers_export_' . date('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($columns, $customers, $formatPhone, $formatNum) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns);

            foreach ($customers as $customer) {
                $totalDue = (float)($customer->sales()->sum('due_amount') ?? 0.0);
                fputcsv($file, [
                    $customer->id,
                    $customer->name,
                    $formatPhone($customer->phone),
                    $customer->email ?? '',
                    $customer->tier ?? 'Silver Regular',
                    $customer->reward_points ?? 0,
                    $formatNum($customer->total_spent ?? 0),
                    $formatNum($totalDue),
                    $customer->sales_count ?? 0,
                    $customer->total_visits ?? 0,
                    $customer->status ?? 'Active',
                    $customer->notes ?? '',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Bulk import customers from JSON payload parsed from CSV / Excel.
     */
    public function bulkImport(Request $request)
    {
        $items = $request->input('customers', []);
        $strategy = $request->input('duplicate_strategy', 'update'); // 'update' | 'skip' | 'create_new'

        if (empty($items) || !is_array($items)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No customers provided for import',
            ], 400);
        }

        $cleanPhone = function ($val) {
            $val = trim((string)$val);
            if (str_starts_with($val, '="') && str_ends_with($val, '"')) {
                return substr($val, 2, -1);
            }
            if (str_starts_with($val, '=')) {
                return trim(ltrim($val, '='), '"\'');
            }
            return $val;
        };

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $name = trim($item['name'] ?? '');
            $phone = $cleanPhone($item['phone'] ?? '');

            if (empty($name) || empty($phone)) {
                $skipped++;
                continue;
            }

            $email = !empty($item['email']) ? trim($item['email']) : null;
            $tier = !empty($item['tier']) ? trim($item['tier']) : (!empty($item['group']) ? trim($item['group']) : 'Silver Regular');
            $rewardPoints = isset($item['reward_points']) ? (int)$item['reward_points'] : 0;
            $totalSpent = isset($item['total_spent']) ? (float)$item['total_spent'] : 0.00;
            $totalVisits = isset($item['total_visits']) ? (int)$item['total_visits'] : 1;
            $status = !empty($item['status']) && in_array(ucfirst(strtolower(trim($item['status']))), ['Active', 'Inactive'])
                ? ucfirst(strtolower(trim($item['status'])))
                : 'Active';
            $notes = !empty($item['notes']) ? trim($item['notes']) : null;

            // Auto-register customer group if not exists
            if (!empty($tier)) {
                CustomerGroup::firstOrCreate(
                    ['name' => $tier],
                    ['percentage' => '0', 'status' => 'Active']
                );
            }

            // Match existing by phone or name
            $existing = null;
            if ($strategy !== 'create_new') {
                $existing = Customer::where('phone', $phone)
                    ->orWhere('name', $name)
                    ->first();
            }

            if ($existing) {
                if ($strategy === 'skip') {
                    $skipped++;
                    continue;
                }

                // Update existing customer
                $existing->update([
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email ?? $existing->email,
                    'tier' => $tier ?: $existing->tier,
                    'reward_points' => isset($item['reward_points']) ? $rewardPoints : $existing->reward_points,
                    'total_spent' => isset($item['total_spent']) ? $totalSpent : $existing->total_spent,
                    'total_visits' => isset($item['total_visits']) ? $totalVisits : $existing->total_visits,
                    'status' => $status,
                    'notes' => $notes ?? $existing->notes,
                ]);
                $updated++;
            } else {
                Customer::create([
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'tier' => $tier,
                    'reward_points' => $rewardPoints,
                    'total_spent' => $totalSpent,
                    'total_visits' => $totalVisits,
                    'status' => $status,
                    'notes' => $notes,
                    'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
                ]);
                $imported++;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => "Bulk import completed. Imported: {$imported}, Updated: {$updated}, Skipped: {$skipped}",
            'summary' => [
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
            ],
        ]);
    }
}
