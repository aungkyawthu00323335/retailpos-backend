<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierCategory;
use App\Services\PurchaseAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    protected function accounting(): PurchaseAccountingService
    {
        return app(PurchaseAccountingService::class);
    }

    /**
     * Display a listing of suppliers.
     */
    public function index(Request $request)
    {
        $noStoreHeader = 'no-store, no-cache, must-revalidate, private';

        if ($request->boolean('compact')) {
            $compactQuery = Supplier::query()
                ->select(['id', 'name', 'phone', 'category', 'status']);

            if ($request->filled('search')) {
                $search = trim((string) $request->input('search'));
                $compactQuery->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            if ($request->filled('status') && $request->input('status') !== 'All') {
                $compactQuery->where('status', $request->input('status'));
            }

            $limit = (int) $request->input('per_page', 1000);
            if ($limit <= 0 || $limit > 5000) {
                $limit = 1000;
            }
            $rows = $compactQuery->orderBy('id', 'desc')->limit($limit)->get();
            $count = $rows->count();

            return response()->json([
                'status' => 'success',
                'data' => $rows,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => max(1, $count),
                    'total' => $count,
                    'last_page' => 1,
                ],
            ])->header('Cache-Control', $noStoreHeader);
        }

        $accounting = $this->accounting();
        $query = Supplier::withCount([
            'purchases' => fn($q) => $accounting->excludeOpeningStockPurchases($q),
        ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category') && $request->input('category') !== 'All') {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'name', 'contact_person', 'phone', 'email', 'category', 'balance_due', 'payment_terms', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $perPage = (int)$request->input('per_page', 25);
        if ($perPage <= 0) $perPage = 25;
        if ($perPage > 100) $perPage = 100;

        $canViewCost = $accounting->canViewCost($request);
        $paginated = $query->paginate($perPage);

        $rows = collect($paginated->items())->map(function ($sup) use ($canViewCost) {
            if (!$canViewCost) {
                $sup->setAttribute('balance_due', null);
                $sup->setAttribute('cost_masked', true);
            }
            return $sup;
        })->all();

        $summaryData = Supplier::selectRaw("
            COUNT(*) as total_suppliers,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_suppliers,
            COALESCE(SUM(balance_due), 0) as total_balance_due,
            COUNT(DISTINCT category) as total_categories
        ")->first();

        return response()->json([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'summary' => [
                'total_suppliers' => (int)($summaryData->total_suppliers ?? 0),
                'active_suppliers' => (int)($summaryData->active_suppliers ?? 0),
                'total_balance_due' => $canViewCost ? (float)($summaryData->total_balance_due ?? 0) : 0.0,
                'total_categories' => (int)($summaryData->total_categories ?? 0),
                'cost_masked' => !$canViewCost,
            ],
            'data' => $rows,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ])->header('Cache-Control', $noStoreHeader);
    }

    /**
     * Store a newly created supplier.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|email|max:255',
            'category' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'balance_due' => 'nullable|numeric',
            'payment_terms' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:Active,Inactive',
            'notes' => 'nullable|string',
        ]);

        $supplier = Supplier::create([
            'name' => $validated['name'],
            'contact_person' => $validated['contact_person'] ?? null,
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'category' => $validated['category'] ?? 'Retail Electronics',
            'country' => $validated['country'] ?? null,
            'city' => $validated['city'] ?? null,
            'address' => $validated['address'] ?? null,
            'balance_due' => $validated['balance_due'] ?? 0.00,
            'payment_terms' => $validated['payment_terms'] ?? 'Net 30 Days',
            'status' => $validated['status'] ?? 'Active',
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier created successfully',
            'data' => $supplier,
        ], 201);
    }

    /**
     * Display the specified supplier.
     */
    public function show(Request $request, $id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier not found',
            ], 404);
        }

        $canViewCost = $this->accounting()->canViewCost($request);
        if (!$canViewCost) {
            $supplier->setAttribute('balance_due', null);
            $supplier->setAttribute('cost_masked', true);
        }

        return response()->json([
            'status' => 'success',
            'cost_masked' => !$canViewCost,
            'data' => $supplier,
        ]);
    }

    /**
     * Update the specified supplier.
     */
    public function update(Request $request, $id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'sometimes|required|string|max:50',
            'email' => 'nullable|email|max:255',
            'category' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'balance_due' => 'nullable|numeric',
            'payment_terms' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:Active,Inactive',
            'notes' => 'nullable|string',
        ]);

        if (array_key_exists('balance_due', $validated)) {
            $requestedBalance = round((float)($validated['balance_due'] ?? 0.0), 2);
            $currentBalance = round((float)($supplier->balance_due ?? 0.0), 2);
            if (abs($requestedBalance - $currentBalance) > 0.01) {
                $purchCheckQuery = $supplier->purchases();
                $this->accounting()->excludeOpeningStockPurchases($purchCheckQuery);
                $hasLedgerActivity = $purchCheckQuery->exists()
                    || $supplier->purchaseReturns()->exists();
                if ($hasLedgerActivity) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot modify balance_due directly when purchase or return ledger transactions exist. Use payments or credit notes to settle supplier balances.',
                    ], 422);
                }
            } else {
                unset($validated['balance_due']);
            }
        }

        $supplier->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier updated successfully',
            'data' => $supplier,
        ]);
    }

    /**
     * Toggle active/inactive status of the supplier.
     */
    public function toggleStatus($id)
    {
        $supplier = Supplier::find($id);

        if (!$supplier) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier not found',
            ], 404);
        }

        $supplier->status = ($supplier->status === 'Active') ? 'Inactive' : 'Active';
        $supplier->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier status updated to ' . $supplier->status,
            'data' => $supplier,
        ]);
    }

    /**
     * Remove the specified supplier with Senior Accountant safeguards.
     */
    public function destroy($id)
    {
        $accounting = $this->accounting();
        $supplier = Supplier::withCount([
            'purchases' => fn($q) => $accounting->excludeOpeningStockPurchases($q),
            'purchaseReturns',
        ])->find($id);

        if (!$supplier) {
            return response()->json([
                'status' => 'error',
                'message' => 'Supplier not found',
            ], 404);
        }

        $purchasesCount = (int)($supplier->purchases_count ?? 0);
        $returnsCount = (int)($supplier->purchase_returns_count ?? 0);
        $balanceDue = (float)($supplier->balance_due ?? 0.0);

        if ($purchasesCount > 0 || $returnsCount > 0 || abs($balanceDue) > 0.01) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete supplier because they have ' . $purchasesCount . ' active purchase invoice(s), ' . $returnsCount . ' return(s), or outstanding Accounts Payable balance ($' . number_format($balanceDue, 2) . '). This is protected by accounting safeguards to prevent ledger corruption. Please deactivate the supplier instead.',
                'purchases_count' => $purchasesCount,
                'purchase_returns_count' => $returnsCount,
                'balance_due' => $balanceDue,
            ], 422);
        }

        DB::transaction(function () use ($supplier) {
            Purchase::where('supplier_id', $supplier->id)
                ->where('payment_type', 'Opening Stock')
                ->update(['supplier_id' => null]);
            $supplier->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Supplier deleted successfully',
        ]);
    }

    /**
     * Download sample Excel / CSV template for Supplier Import with UTF-8 BOM.
     */
    public function downloadTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="supplier_import_template_utf8.csv"',
        ];

        $columns = [
            'Supplier Name',
            'Contact Person',
            'Phone',
            'Email',
            'Category',
            'Country',
            'City',
            'Address',
            'Balance Due',
            'Payment Terms',
            'Status',
            'Notes',
        ];

        $sampleRows = [
            [
                'Metro Prime Meats Ltd.',
                'U Kyaw Min',
                '+95 9 791 222 333',
                'orders@metromeats.com',
                'Meat & Poultry',
                'Myanmar',
                'Yangon',
                'No. 45, Industrial Zone 1, Hlaing Tharyar, Yangon',
                '1450',
                'Net 15 Days',
                'Active',
                'Premium halal & fresh beef supplier',
            ],
            [
                'Golden Valley Dairy Co.',
                'Daw Hla Hla',
                '+95 9 450 111 222',
                'sales@gvdairy.com',
                'Dairy & Cheese',
                'Myanmar',
                'Mandalay',
                'Pyin Oo Lwin Highland Dairy Farm Depot, Mandalay',
                '620.5',
                'Net 30 Days',
                'Active',
                'Fresh dairy milk and artisan cheese',
            ],
            [
                'Green Farm Fresh Produce',
                'Ko Tun Tun',
                '+95 9 250 888 999',
                'contact@greenfarm.com',
                'Vegetables & Fruits',
                'Myanmar',
                'Yangon',
                'Thiri Mingalar Wholesale Vegetable Market, Yangon',
                '0',
                'Cash on Delivery',
                'Active',
                'Direct farm organic vegetables',
            ],
            [
                'Yangon Beverage & Syrups Distribution',
                'Mr. Lin Naing',
                '+95 9 970 333 444',
                'logistics@ygbev.net',
                'Beverages & Syrups',
                'Myanmar',
                'Yangon',
                'Bayint Naung Wholesale Warehouse 8, Mayangone, Yangon',
                '890',
                'Net 30 Days',
                'Active',
                'Imported syrups and bar concentrates',
            ],
            [
                'EcoPack Wholesale Myanmar',
                'Daw Thida Win',
                '+95 9 311 555 666',
                'sales@ecopack.mm',
                'Packaging & Utensils',
                'Myanmar',
                'Yangon',
                'Shwe Lin Ban Industrial Park, Insein, Yangon',
                '0',
                'Immediate',
                'Active',
                'Biodegradable food packaging & takeaway cups',
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
     * Export suppliers to CSV with UTF-8 BOM and filtered criteria.
     */
    public function export(Request $request)
    {
        $query = Supplier::query();

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('country', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category') && $request->input('category') !== 'All') {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['id', 'name', 'contact_person', 'phone', 'email', 'category', 'balance_due', 'payment_terms', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'asc');
        }

        $columns = [
            'Supplier ID',
            'Supplier Name',
            'Contact Person',
            'Phone',
            'Email',
            'Category',
            'Country',
            'City',
            'Address',
            'Balance Due',
            'Payment Terms',
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

        @set_time_limit(300);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="suppliers_export_' . date('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
            'X-Accel-Buffering' => 'no',
        ];

        $canViewCost = $this->accounting()->canViewCost($request);
        $callback = function () use ($query, $columns, $formatPhone, $formatNum, $canViewCost) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns);

            $rowCount = 0;
            foreach ($query->toBase()->cursor() as $supplier) {
                fputcsv($file, [
                    $supplier->id,
                    $supplier->name,
                    $supplier->contact_person ?? '',
                    $formatPhone($supplier->phone),
                    $supplier->email ?? '',
                    $supplier->category ?? 'Retail Electronics',
                    $supplier->country ?? '',
                    $supplier->city ?? '',
                    $supplier->address ?? '',
                    $canViewCost ? $formatNum($supplier->balance_due ?? 0) : 'Protected',
                    $supplier->payment_terms ?? 'Net 30 Days',
                    $supplier->status ?? 'Active',
                    $supplier->notes ?? '',
                ]);

                $rowCount++;
                if ($rowCount % 200 === 0) {
                    flush();
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Bulk import suppliers from JSON payload parsed from CSV / Excel.
     */
    public function bulkImport(Request $request)
    {
        $items = $request->input('suppliers', []);
        $strategy = $request->input('duplicate_strategy', 'update'); // 'update' | 'skip' | 'create_new'

        if (empty($items) || !is_array($items)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No suppliers provided for import',
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
            $category = !empty($item['category']) ? trim($item['category']) : 'Retail Electronics';
            $contactPerson = !empty($item['contact_person']) ? trim($item['contact_person']) : null;
            $country = !empty($item['country']) ? trim($item['country']) : null;
            $city = !empty($item['city']) ? trim($item['city']) : null;
            $address = !empty($item['address']) ? trim($item['address']) : null;
            $balanceDue = isset($item['balance_due']) ? (float)$item['balance_due'] : 0.00;
            $paymentTerms = !empty($item['payment_terms']) ? trim($item['payment_terms']) : 'Net 30 Days';
            $status = !empty($item['status']) && in_array(ucfirst(strtolower(trim($item['status']))), ['Active', 'Inactive'])
                ? ucfirst(strtolower(trim($item['status'])))
                : 'Active';
            $notes = !empty($item['notes']) ? trim($item['notes']) : null;

            // Auto-register category if not exists
            if (!empty($category)) {
                SupplierCategory::firstOrCreate(
                    ['name' => $category],
                    ['color' => '#2563EB', 'status' => 'Active']
                );
            }

            // Match existing by phone or name
            $existing = null;
            if ($strategy !== 'create_new') {
                $existing = Supplier::where('phone', $phone)
                    ->orWhere('name', $name)
                    ->first();
            }

            if ($existing) {
                if ($strategy === 'skip') {
                    $skipped++;
                    continue;
                }

                // Preserve live AP balance when supplier already has ledger history or CSV omitted balance_due
                $hasImpLedger = false;
                if (array_key_exists('balance_due', $item)) {
                    $impPurchQuery = $existing->purchases();
                    $this->accounting()->excludeOpeningStockPurchases($impPurchQuery);
                    $hasImpLedger = $impPurchQuery->exists() || $existing->purchaseReturns()->exists();
                }
                $preserveExistingBalance = !array_key_exists('balance_due', $item) || $hasImpLedger;

                // Update existing supplier
                $existing->update([
                    'name' => $name,
                    'contact_person' => $contactPerson ?? $existing->contact_person,
                    'phone' => $phone,
                    'email' => $email ?? $existing->email,
                    'category' => $category ?: $existing->category,
                    'country' => $country ?? $existing->country,
                    'city' => $city ?? $existing->city,
                    'address' => $address ?? $existing->address,
                    'balance_due' => $preserveExistingBalance ? $existing->balance_due : $balanceDue,
                    'payment_terms' => $paymentTerms ?: $existing->payment_terms,
                    'status' => $status,
                    'notes' => $notes ?? $existing->notes,
                ]);
                $updated++;
            } else {
                Supplier::create([
                    'name' => $name,
                    'contact_person' => $contactPerson,
                    'phone' => $phone,
                    'email' => $email,
                    'category' => $category,
                    'country' => $country,
                    'city' => $city,
                    'address' => $address,
                    'balance_due' => $balanceDue,
                    'payment_terms' => $paymentTerms,
                    'status' => $status,
                    'notes' => $notes,
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
