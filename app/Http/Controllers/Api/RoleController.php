<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    /**
     * Display a listing of roles with user counts.
     */
    public function index(Request $request)
    {
        $query = Role::withCount('users');

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

        $roles = $query->orderBy('id', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total_roles' => $roles->count(),
                'active_roles' => $roles->where('status', 'Active')->count(),
            ],
            'data' => $roles,
        ]);
    }

    /**
     * Get the master permission catalog structured module-by-module.
     */
    public function getAvailablePermissions(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => self::getPermissionCatalog(),
        ]);
    }

    /**
     * Master permission catalog structured module-by-module.
     */
    public static function getPermissionCatalog(): array
    {
        return [
            // 1. Dashboard
            [
                'id' => 'dashboard',
                'name' => 'Manage Dashboard',
                'module' => 'Dashboard',
                'actions' => ['view'],
                'special_actions' => [],
            ],

            // 2. Products
            [
                'id' => 'products',
                'name' => 'Manage Products',
                'module' => 'Products',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'view_cost', 'label' => 'View Cost Price', 'description' => 'Access confidential procurement cost & margin data'],
                    ['key' => 'export', 'label' => 'Export Catalog', 'description' => 'Download master catalog to Excel/CSV'],
                    ['key' => 'import', 'label' => 'Import Products', 'description' => 'Bulk import product catalog via Excel'],
                ],
            ],
            [
                'id' => 'barcode',
                'name' => 'Manage Print Barcode',
                'module' => 'Products',
                'actions' => ['view', 'create'],
                'special_actions' => [],
            ],
            [
                'id' => 'product_categories',
                'name' => 'Manage Product Categories',
                'module' => 'Products',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'sub_categories',
                'name' => 'Manage Sub Categories',
                'module' => 'Products',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'variations',
                'name' => 'Manage Variations',
                'module' => 'Products',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'brands',
                'name' => 'Manage Brands',
                'module' => 'Products',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'units',
                'name' => 'Manage Units',
                'module' => 'Products',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],

            // 3. Adjustments
            [
                'id' => 'adjustments',
                'name' => 'Manage Adjustments',
                'module' => 'Adjustments',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'approve', 'label' => 'Approve Adjustment', 'description' => 'Authorize stock variance write-offs'],
                    ['key' => 'export', 'label' => 'Export Adjustments', 'description' => 'Export stock adjustments log'],
                ],
            ],

            // 4. Quotations
            [
                'id' => 'quotations',
                'name' => 'Manage Quotations',
                'module' => 'Quotations',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'convert_sale', 'label' => 'Convert to Sale', 'description' => 'Convert accepted quotation directly to sale order'],
                    ['key' => 'export', 'label' => 'Export Quotations', 'description' => 'Export price quotes database'],
                ],
            ],

            // 5. Purchases
            [
                'id' => 'purchases',
                'name' => 'Manage Purchases',
                'module' => 'Purchases',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'pay', 'label' => 'Record AP Payment', 'description' => 'Post supplier invoice accounts payable payments'],
                    ['key' => 'export', 'label' => 'Export Purchases', 'description' => 'Export supplier purchase orders and invoices'],
                ],
            ],
            [
                'id' => 'purchase_returns',
                'name' => 'Manage Purchase Returns',
                'module' => 'Purchases',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'pay', 'label' => 'Record Refund / Payment', 'description' => 'Record refund received from supplier'],
                    ['key' => 'export', 'label' => 'Export Returns', 'description' => 'Export purchase returns log'],
                ],
            ],

            // 6. Sales & POS
            [
                'id' => 'sales',
                'name' => 'Manage Sales',
                'module' => 'Sales & POS',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'void', 'label' => 'Void Sale', 'description' => 'Void completed receipts and reverse drawer totals'],
                    ['key' => 'refund', 'label' => 'Approve Refund', 'description' => 'Process customer sales return payments'],
                    ['key' => 'pay', 'label' => 'Record Payment', 'description' => 'Record customer order payment settlement'],
                    ['key' => 'export', 'label' => 'Export Sales', 'description' => 'Export sales transactions database'],
                ],
            ],
            [
                'id' => 'pos',
                'name' => 'Manage Pos Screen',
                'module' => 'Sales & POS',
                'actions' => ['view', 'create'],
                'special_actions' => [
                    ['key' => 'checkout', 'label' => 'POS Checkout', 'description' => 'Process point of sale checkout & submit transactions'],
                ],
            ],
            [
                'id' => 'cash_register',
                'name' => 'Manage Pos Screen & Cash Registers',
                'module' => 'Sales & POS',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'open_close', 'label' => 'Open & Close Shift', 'description' => 'Start shift & submit till closing counts'],
                    ['key' => 'safe_drop', 'label' => 'Safe Drop / Cash Drop', 'description' => 'Drop excess cash into store safe during shift'],
                    ['key' => 'view_expected', 'label' => 'View Expected Cash', 'description' => 'Show expected cash amount at shift close'],
                ],
            ],
            [
                'id' => 'sale_returns',
                'name' => 'Manage Sales Returns',
                'module' => 'Sales & POS',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'pay', 'label' => 'Record Refund / Payment', 'description' => 'Record customer refund settlement'],
                    ['key' => 'export', 'label' => 'Export Sales Returns', 'description' => 'Export customer sales returns log'],
                ],
            ],
            [
                'id' => 'bot_orders',
                'name' => 'Manage Bot Orders',
                'module' => 'Sales & POS',
                'actions' => ['view', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'approve', 'label' => 'Approve/Reject Orders', 'description' => 'Approve or reject customer orders from Telegram Bot'],
                    ['key' => 'dispatch', 'label' => 'Dispatch Order', 'description' => 'Dispatch orders for fulfillment/delivery'],
                ],
            ],
            [
                'id' => 'bot_chat',
                'name' => 'Manage Customer Live Chat',
                'module' => 'Sales & POS',
                'actions' => ['view', 'create'],
                'special_actions' => [],
            ],

            // 7. Transfers
            [
                'id' => 'transfers',
                'name' => 'Manage Transfers',
                'module' => 'Transfers',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'receive', 'label' => 'Receive Inbound', 'description' => 'Acknowledge and restock inter-branch shipments'],
                    ['key' => 'export', 'label' => 'Export Transfers', 'description' => 'Export inter-branch transfer logs'],
                ],
            ],

            // 8. Expenses
            [
                'id' => 'expenses',
                'name' => 'Manage Expenses',
                'module' => 'Expenses',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'pay', 'label' => 'Record Payment', 'description' => 'Post expense payments'],
                    ['key' => 'export', 'label' => 'Export Expenses', 'description' => 'Export operational expenses log'],
                ],
            ],
            [
                'id' => 'expense_categories',
                'name' => 'Manage Expense Categories',
                'module' => 'Expenses',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],

            // 9. Processing
            [
                'id' => 'processings',
                'name' => 'Manage Processing',
                'module' => 'Processing',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Processing', 'description' => 'Export stock conversion and repackaging logs'],
                ],
            ],

            // 10. Peoples
            [
                'id' => 'customers',
                'name' => 'Manage Customers',
                'module' => 'Peoples',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Customers', 'description' => 'Download CRM customer database'],
                    ['key' => 'import', 'label' => 'Import Customers', 'description' => 'Bulk import customer accounts from Excel/CSV'],
                ],
            ],
            [
                'id' => 'customer_groups',
                'name' => 'Manage Customer Groups',
                'module' => 'Peoples',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'suppliers',
                'name' => 'Manage Suppliers',
                'module' => 'Peoples',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Suppliers', 'description' => 'Download supplier contact directory'],
                    ['key' => 'import', 'label' => 'Import Suppliers', 'description' => 'Bulk import supplier contacts from Excel/CSV'],
                ],
            ],
            [
                'id' => 'supplier_categories',
                'name' => 'Manage Supplier Categories',
                'module' => 'Peoples',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'users',
                'name' => 'Manage Users',
                'module' => 'Peoples',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'roles',
                'name' => 'Manage Roles/Permissions',
                'module' => 'Peoples',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],

            // 11. Reports
            [
                'id' => 'reports',
                'name' => 'Manage Reports',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'profit_loss', 'label' => 'P&L Statement', 'description' => 'Confidential Gross & Net Profit Margin Report'],
                    ['key' => 'commercial_tax', 'label' => 'Commercial Tax (VAT)', 'description' => 'Commercial tax output/input liability ledger'],
                    ['key' => 'export', 'label' => 'Export Reports', 'description' => 'Export executive accounting statements to Excel/PDF'],
                ],
            ],
            [
                'id' => 'report_sales',
                'name' => 'Manage Sales Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export sales report data'],
                ],
            ],
            [
                'id' => 'report_purchases',
                'name' => 'Manage Purchase Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export purchase report data'],
                ],
            ],
            [
                'id' => 'report_stock',
                'name' => 'Manage Stock Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export inventory stock data'],
                ],
            ],
            [
                'id' => 'report_stock_alert',
                'name' => 'Manage Low Stock Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export stock alert data'],
                ],
            ],
            [
                'id' => 'report_stock_ledger',
                'name' => 'Manage Stock Movement Ledger',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export inventory movement history'],
                ],
            ],
            [
                'id' => 'report_profit_loss',
                'name' => 'Manage Profit & Loss (P&L)',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export profit and loss statement'],
                ],
            ],
            [
                'id' => 'report_cashier_audit',
                'name' => 'Manage Cashier Audit',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export cashier audit reconciliations'],
                ],
            ],
            [
                'id' => 'report_expense',
                'name' => 'Manage Expense Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export expense summary breakdown'],
                ],
            ],
            [
                'id' => 'report_top_selling',
                'name' => 'Manage Top Selling Products',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export best selling items analytics'],
                ],
            ],
            [
                'id' => 'report_dead_stock',
                'name' => 'Manage Dead & Slow Stock',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export non-moving stock diagnostics'],
                ],
            ],
            [
                'id' => 'report_cash_flow',
                'name' => 'Manage Cash Flow Statement',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export cash flow liquidity report'],
                ],
            ],
            [
                'id' => 'report_register',
                'name' => 'Manage Register Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export till session summaries'],
                ],
            ],
            [
                'id' => 'report_z_report',
                'name' => 'Manage Z-Report (End of Day)',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export end-of-day store closing audit'],
                ],
            ],
            [
                'id' => 'report_ar_aging',
                'name' => 'Manage AR Aging (Customer)',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export accounts receivable aging schedule'],
                ],
            ],
            [
                'id' => 'report_ap_aging',
                'name' => 'Manage AP Aging (Supplier)',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export accounts payable aging schedule'],
                ],
            ],
            [
                'id' => 'report_product_margins',
                'name' => 'Manage Product Margins',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export item profitability margins'],
                ],
            ],
            [
                'id' => 'report_sales_return',
                'name' => 'Manage Sales Return Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export sales return summaries'],
                ],
            ],
            [
                'id' => 'report_purchase_return',
                'name' => 'Manage Purchase Return Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export purchase return summaries'],
                ],
            ],
            [
                'id' => 'report_commercial_tax',
                'name' => 'Manage Commercial Tax (VAT)',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export commercial tax VAT liabilities'],
                ],
            ],
            [
                'id' => 'report_customer',
                'name' => 'Manage Customer Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export customer transactions report'],
                ],
            ],
            [
                'id' => 'report_supplier',
                'name' => 'Manage Supplier Report',
                'module' => 'Reports',
                'actions' => ['view'],
                'special_actions' => [
                    ['key' => 'export', 'label' => 'Export Report', 'description' => 'Export supplier transactions report'],
                ],
            ],

            // 12. Settings / System
            [
                'id' => 'outlets',
                'name' => 'Manage Warehouses & Outlets',
                'module' => 'Settings',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'currencies',
                'name' => 'Manage Currency',
                'module' => 'Settings',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'delivery_zones',
                'name' => 'Manage Delivery Zones',
                'module' => 'Settings',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'payment_methods',
                'name' => 'Manage Payment Methods',
                'module' => 'Settings',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
            [
                'id' => 'prefix_settings',
                'name' => 'Manage Prefix Settings',
                'module' => 'Settings',
                'actions' => ['view', 'update'],
                'special_actions' => [],
            ],
            [
                'id' => 'voucher_settings',
                'name' => 'Manage Voucher Settings',
                'module' => 'Settings',
                'actions' => ['view', 'update'],
                'special_actions' => [],
            ],
            [
                'id' => 'general_settings',
                'name' => 'Manage General Settings',
                'module' => 'Settings',
                'actions' => ['view', 'update'],
                'special_actions' => [],
            ],
            [
                'id' => 'telegram_bot',
                'name' => 'Manage Telegram Bot',
                'module' => 'Settings',
                'actions' => ['view', 'create', 'update', 'delete'],
                'special_actions' => [],
            ],
        ];
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'permissions' => 'nullable|array',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $caller = User::resolveFromRequest($request);
        if ($caller && !$caller->isSuperAdmin() && !$caller->hasPermission('roles.create')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not have permission to create roles.',
            ], 403);
        }

        if (strtolower(trim($validated['name'])) === 'super admin' && (!$caller || !$caller->isSuperAdmin())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Cannot create role with name Super Admin.',
            ], 403);
        }

        if (Role::where('name', trim($validated['name']))->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => "A role with the name '{$validated['name']}' already exists.",
            ], 422);
        }

        if (isset($validated['permissions']) && is_array($validated['permissions'])) {
            if (in_array('*', $validated['permissions'], true)) {
                if (!$caller || !$caller->isSuperAdmin()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized. Only Super Admin can create roles with wildcard permissions.',
                    ], 403);
                }
            }

            // Privilege escalation prevention: non-super-admin cannot grant permissions they do not have
            if ($caller && !$caller->isSuperAdmin()) {
                foreach ($validated['permissions'] as $perm) {
                    if (!$caller->hasPermission($perm)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => "Unauthorized. You cannot grant permission '{$perm}' which you do not possess.",
                        ], 403);
                    }
                }
            }
        }

        $slug = Str::slug($validated['name'], '_');
        $originalSlug = $slug;
        $counter = 1;
        while (Role::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}_{$counter}";
            $counter++;
        }

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#2563EB',
            'permissions' => $validated['permissions'] ?? [],
            'is_default' => false,
            'status' => $validated['status'] ?? 'Active',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Role created successfully',
            'data' => $role->loadCount('users'),
        ], 201);
    }

    /**
     * Display the specified role.
     */
    public function show($id)
    {
        $role = Role::with('users')->withCount('users')->find($id);

        if (!$role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Role not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $role,
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Role not found',
            ], 404);
        }

        $caller = User::resolveFromRequest($request);
        if ($caller && !$caller->isSuperAdmin() && !$caller->hasPermission('roles.update')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not have permission to update roles.',
            ], 403);
        }

        // SEC-03: Prevent non-super-admins from editing Super Admin role
        if ($role->slug === 'super_admin' || strtolower($role->name) === 'super admin') {
            if (!$caller || !$caller->isSuperAdmin()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Super Admin role cannot be modified by non-super-admin users.',
                ], 403);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'permissions' => 'nullable|array',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        if (isset($validated['name']) && Role::where('name', trim($validated['name']))->where('id', '!=', $id)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => "A role with the name '{$validated['name']}' already exists.",
            ], 422);
        }

        if (isset($validated['name']) && strtolower(trim($validated['name'])) === 'super admin' && (!$caller || !$caller->isSuperAdmin())) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Cannot rename a role to Super Admin.',
            ], 403);
        }

        if (isset($validated['permissions']) && is_array($validated['permissions'])) {
            if (in_array('*', $validated['permissions'], true)) {
                if (!$caller || !$caller->isSuperAdmin()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized. Only Super Admin can assign wildcard permissions.',
                    ], 403);
                }
            }

            // Privilege escalation prevention: non-super-admin cannot grant permissions they do not have
            if ($caller && !$caller->isSuperAdmin()) {
                foreach ($validated['permissions'] as $perm) {
                    if (!$caller->hasPermission($perm)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => "Unauthorized. You cannot grant permission '{$perm}' which you do not possess.",
                        ], 403);
                    }
                }
            }
        }

        $role->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Role updated successfully',
            'data' => $role->loadCount('users'),
        ]);
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Request $request, $id)
    {
        $role = Role::find($id);

        if (!$role) {
            return response()->json([
                'status' => 'error',
                'message' => 'Role not found',
            ], 404);
        }

        $caller = User::resolveFromRequest($request);
        if ($caller && !$caller->isSuperAdmin() && !$caller->hasPermission('roles.delete')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not have permission to delete roles.',
            ], 403);
        }

        if ($role->slug === 'super_admin' || strtolower($role->name) === 'super admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Super Admin role cannot be deleted.',
            ], 403);
        }

        if ($role->is_default) {
            return response()->json([
                'status' => 'error',
                'message' => 'System default role cannot be deleted',
            ], 422);
        }

        if ($role->users()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete role '{$role->name}' because {$role->users()->count()} user(s) are currently assigned to it. Please reassign them first.",
            ], 422);
        }

        $role->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Role deleted successfully',
        ]);
    }
}
