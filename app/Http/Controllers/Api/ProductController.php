<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\SubCategory;
use App\Models\ProductUnit;
use App\Models\SaleUnit;
use App\Models\PurchaseUnit;
use App\Models\Variation;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Http\Requests\StoreProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductListResource;
use App\Services\ProductCreateService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $warehouseParam = $request->input('warehouse_id') ?? $request->input('outlet_id');
        $warehouseId = ($warehouseParam && $warehouseParam !== 'All' && $warehouseParam !== 'all')
            ? (int)$warehouseParam
            : null;

        // The list only needs summary fields and the primary image. Full
        // variants/images/unit links are loaded by show() for Details/Edit.
        $query = Product::query()
            ->select([
                'id', 'uuid', 'code', 'sku', 'barcode', 'barcode_symbology',
                'name', 'alternative_name', 'category_id', 'category_name',
                'sub_category_id', 'sub_category_name', 'brand_id', 'brand_name',
                'supplier_id', 'supplier_name', 'base_unit_id', 'unit_name',
                'purchase_unit_id', 'purchase_unit', 'sale_unit_id', 'sale_unit',
                'cost_price', 'selling_price', 'purchase_price', 'retail_price',
                'retail_prices', 'wholesale_price', 'wholesale_prices', 'stock_quantity',
                'alert_quantity', 'stock_alert', 'low_qty', 'has_variants',
                'is_bot_available', 'bot_price',
                'image_url', 'status', 'is_active', 'added_by',
            ])
            // Only load the primaryImage relation when image_url is empty.
            // Products that already have image_url set do not need the extra
            // JOIN on product_images — this eliminates N eager-load queries for
            // catalogs that were migrated with the legacy image_url column.
            ->with([
                'primaryImage:id,product_id,disk,path,is_primary,sort_order',
                'variants:id,product_id,name,sku,barcode,cost_price,selling_price,retail_prices,wholesale_price,wholesale_prices,is_active,sort_order,image_url',
                'variants.values:id,product_variant_id,attribute_name,attribute_value',
                'variants.stockBalances' => function ($q) use ($warehouseId) {
                    $q->select(['id', 'product_variant_id', 'warehouse_id', 'quantity']);
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    }
                },
                'stockBalances' => function ($q) use ($warehouseId) {
                    $q->select(['id', 'product_id', 'warehouse_id', 'product_variant_id', 'quantity']);
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    }
                },
                'unitLinks:id,product_id,product_variant_id,unit_type,unit_id,unit_name,conversion_rate,is_default,is_active',
            ]);

        if ($warehouseId !== null) {
            $query->withSum(['stockBalances as warehouse_stock' => function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            }], 'quantity');
        }

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $isAsciiOnly = (bool)preg_match('/^[\x20-\x7E]+$/', $search);
            $cleanSearch = $search;
            $isNumeric = is_numeric($cleanSearch);
            $intVal = $isNumeric ? (int)$cleanSearch : null;
            $padded6 = $isNumeric ? sprintf('%06d', $intVal) : null;

            $extractedNumber = null;
            $extractedPadded = null;
            if ($isAsciiOnly && preg_match('/^(?:(PRD|SKU)[-_ ]*)?0*([1-9][0-9]*|[0-9]+)$/i', $cleanSearch, $m)) {
                $numStr = $m[2] ?? '';
                if ($numStr !== '') {
                    $extractedNumber = (int)$numStr;
                    $extractedPadded = sprintf('%06d', $extractedNumber);
                }
            }

            // Variant product IDs lookup
            $variantProductIds = [];
            if ($isAsciiOnly && strlen($cleanSearch) >= 2) {
                $variantProductIds = ProductVariant::where('sku', $search)
                    ->orWhere('barcode', $search)
                    ->orWhere('sku', 'like', "{$search}%")
                    ->orWhere('barcode', 'like', "{$search}%")
                    ->limit(50)
                    ->pluck('product_id')
                    ->toArray();
            }

            // Variant names lookup
            $variantNameProductIds = ProductVariant::where('name', 'like', "%{$search}%")
                ->limit(50)
                ->pluck('product_id')
                ->toArray();
            if (!empty($variantNameProductIds)) {
                $variantProductIds = array_values(array_unique(array_merge($variantProductIds, $variantNameProductIds)));
            }

            // Variant attribute values (e.g. 'Chocolat Gray', 'Color')
            $variantAttrProductIds = \DB::table('product_variant_values')
                ->join('product_variants', 'product_variant_values.product_variant_id', '=', 'product_variants.id')
                ->where('product_variant_values.attribute_value', 'like', "%{$search}%")
                ->whereNull('product_variants.deleted_at')
                ->limit(50)
                ->pluck('product_variants.product_id')
                ->toArray();

            if (!empty($variantAttrProductIds)) {
                $variantProductIds = array_values(array_unique(array_merge($variantProductIds, $variantAttrProductIds)));
            }

            $query->where(function ($q) use ($search, $isAsciiOnly, $isNumeric, $intVal, $padded6, $extractedNumber, $extractedPadded, $variantProductIds) {
                // Name and alternative name searches apply to all queries
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('alternative_name', 'like', "%{$search}%");

                if (!$isNumeric) {
                    $q->orWhere('brand_name', 'like', "%{$search}%")
                      ->orWhere('category_name', 'like', "%{$search}%");
                }

                // If user typed alphanumeric / code / barcode / ID, check indexed code columns
                if ($isAsciiOnly) {
                    $q->orWhere('code', $search)
                      ->orWhere('sku', $search)
                      ->orWhere('barcode', $search)
                      ->orWhere('code', 'like', "{$search}%")
                      ->orWhere('sku', 'like', "{$search}%")
                      ->orWhere('barcode', 'like', "{$search}%");

                    if ($extractedNumber !== null) {
                        $q->orWhere('id', $extractedNumber)
                          ->orWhere('code', $extractedPadded)
                          ->orWhere('code', 'PRD-' . $extractedPadded)
                          ->orWhere('sku', $extractedPadded)
                          ->orWhere('sku', 'SKU-' . $extractedPadded);
                    }

                    if ($isNumeric) {
                        $q->orWhere('id', $intVal)
                          ->orWhere('code', $padded6)
                          ->orWhere('code', 'PRD-' . $padded6)
                          ->orWhere('sku', 'SKU-' . $padded6);
                    }
                }

                if (!empty($variantProductIds)) {
                    $q->orWhereIn('id', $variantProductIds);
                }
            });
        }

        if ($request->filled('category') && $request->input('category') !== 'All') {
            $query->where('category_name', $request->input('category'));
        }

        if ($request->filled('category_id') && $request->input('category_id') !== 'All') {
            $query->where('category_id', (int)$request->input('category_id'));
        }

        if ($request->filled('sub_category_id') && $request->input('sub_category_id') !== 'All') {
            $query->where('sub_category_id', (int)$request->input('sub_category_id'));
        }

        if ($request->filled('sub_category') && $request->input('sub_category') !== 'All') {
            $query->where('sub_category_name', $request->input('sub_category'));
        }

        if ($request->filled('brand') && $request->input('brand') !== 'All') {
            $query->where('brand_name', $request->input('brand'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('is_bot_available')) {
            $query->where('is_bot_available', $request->boolean('is_bot_available'));
        }

        if ($request->has('has_variants')) {
            $query->where('has_variants', $request->boolean('has_variants'));
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = [
            'id', 'name', 'code', 'sku', 'category_name', 'brand_name',
            'has_variants', 'retail_price', 'selling_price', 'purchase_price',
            'cost_price', 'stock_quantity', 'alert_quantity', 'low_qty',
            'added_by',
        ];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
        }

        $isPos = $request->boolean('is_pos') || $request->filled('for_pos') || $request->boolean('all_pages');
        $maxAllowedPerPage = $isPos ? 250 : 100;
        $perPage = (int)$request->input('per_page', 50);
        if ($perPage <= 0) $perPage = 50;
        if ($perPage > $maxAllowedPerPage) $perPage = $maxAllowedPerPage;

        $page = (int)$request->input('page', 1);
        if ($page <= 0) $page = 1;

        $search = trim((string)$request->input('search', ''));
        $category = (string)$request->input('category', '');
        $brand = (string)$request->input('brand', '');

        $user = \App\Models\User::resolveFromRequest($request);
        $canViewCost = $user ? $user->hasPermission('products.view_cost') : false;
        $costScope = $canViewCost ? 'with_cost' : 'masked_cost';

        $cacheVersion = \Illuminate\Support\Facades\Cache::get('products_cache_version', 1);
        $hasVariantsParam = $request->has('has_variants') ? ($request->boolean('has_variants') ? '1' : '0') : 'all';
        $cacheKey = 'pos_prod_list_v' . $cacheVersion . '_' . md5("{$page}|{$perPage}|{$sortBy}|{$sortOrder}|" . ($warehouseId ?? 'all') . "|{$search}|{$category}|{$brand}|" . ($isPos ? '1' : '0') . "|{$costScope}|{$hasVariantsParam}");

        if (!$request->boolean('force_refresh') && \Illuminate\Support\Facades\Cache::store('file')->has($cacheKey)) {
            $cached = \Illuminate\Support\Facades\Cache::store('file')->get($cacheKey);
            return response()->json($cached)
                ->header('Cache-Control', 'private, max-age=60')
                ->header('ETag', '"' . md5("{$cacheVersion}|{$page}|{$perPage}|" . ($cached['pagination']['total'] ?? 0) . "|{$sortBy}|{$sortOrder}|" . ($warehouseId ?? 'all') . "|{$costScope}|{$hasVariantsParam}") . '"')
                ->header('X-Cache', 'HIT');
        }

        // Optimized pagination for search: if page 1 search results are <= perPage,
        // we can skip the expensive COUNT(*) scan entirely.
        if ($page === 1 && $search !== '') {
            $cloned = clone $query;
            $fetched = $cloned->forPage(1, $perPage + 1)->get();
            if ($fetched->count() <= $perPage) {
                $total = $fetched->count();
                $lastPage = 1;
                $items = $fetched;
            } else {
                $items = $fetched->take($perPage);
                $total = $query->count();
                $lastPage = (int)ceil($total / $perPage);
            }
        } else {
            // Cache the total count for 60 seconds to eliminate repeated full-table COUNT(*) scans on every progressive page
            $countKey = 'pos_prod_count_v' . $cacheVersion . '_' . md5(($warehouseId ?? 'all') . "|{$search}|{$category}|{$brand}|{$hasVariantsParam}");
            $total = (int)\Illuminate\Support\Facades\Cache::store('file')->remember($countKey, 60, function () use ($query) {
                return (clone $query)->toBase()->getCountForPagination();
            });
            $lastPage = (int)ceil($total / max(1, $perPage));
            $items = $query->forPage($page, $perPage)->get();
        }

        // ── HTTP caching headers ───────────────────────────────────────────
        // ETag: changes whenever total count, page, per_page, warehouse filter or costScope changes.
        // Cache-Control: private (user-specific data), max-age matches the
        //   Flutter client's _pageCacheTtl so downstream caches stay in sync.
        $etag = '"' . md5("{$cacheVersion}|{$page}|{$perPage}|{$total}|{$sortBy}|{$sortOrder}|" . ($warehouseId ?? 'all') . "|{$costScope}|{$hasVariantsParam}") . '"';

        if (!$request->boolean('force_refresh') && $request->header('If-None-Match') === $etag) {
            return response(null, 304)->header('ETag', $etag);
        }

        $responseData = [
            'status'     => 'success',
            'data'       => ProductListResource::collection($items)->resolve($request),
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => $lastPage,
            ],
        ];

        \Illuminate\Support\Facades\Cache::store('file')->put($cacheKey, $responseData, 60);

        return response()->json($responseData)
            ->header('Cache-Control', 'private, max-age=60')
            ->header('ETag', $etag)
            ->header('X-Cache', 'MISS');
    }

    /**
     * High-performance Delta / Incremental Synchronization endpoint for POS & ERP clients.
     * Returns products updated since a given timestamp, along with deleted product IDs.
     */
    public function sync(Request $request)
    {
        $warehouseParam = $request->input('warehouse_id') ?? $request->input('outlet_id');
        $warehouseId = ($warehouseParam && $warehouseParam !== 'All' && $warehouseParam !== 'all')
            ? (int)$warehouseParam
            : null;

        $sinceParam = $request->input('updated_since') ?? $request->input('since');
        $limit = min((int)($request->input('limit', 1000)), 5000);

        $query = Product::query()
            ->select([
                'id', 'uuid', 'code', 'sku', 'barcode', 'barcode_symbology',
                'name', 'alternative_name', 'category_id', 'category_name',
                'sub_category_id', 'sub_category_name', 'brand_id', 'brand_name',
                'supplier_id', 'supplier_name', 'base_unit_id', 'unit_name',
                'purchase_unit_id', 'purchase_unit', 'sale_unit_id', 'sale_unit',
                'cost_price', 'selling_price', 'purchase_price', 'retail_price',
                'retail_prices', 'wholesale_price', 'wholesale_prices', 'stock_quantity',
                'alert_quantity', 'stock_alert', 'low_qty', 'has_variants',
                'is_bot_available', 'bot_price',
                'image_url', 'status', 'is_active', 'added_by', 'updated_at',
            ])
            ->with([
                'primaryImage:id,product_id,disk,path,is_primary,sort_order',
                'variants:id,product_id,name,sku,barcode,cost_price,selling_price,retail_prices,wholesale_price,wholesale_prices,is_active,sort_order,image_url',
                'variants.values:id,product_variant_id,attribute_name,attribute_value',
                'variants.stockBalances' => function ($q) use ($warehouseId) {
                    $q->select(['id', 'product_variant_id', 'warehouse_id', 'quantity']);
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    }
                },
                'stockBalances' => function ($q) use ($warehouseId) {
                    $q->select(['id', 'product_id', 'warehouse_id', 'product_variant_id', 'quantity']);
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    }
                },
                'unitLinks:id,product_id,product_variant_id,unit_type,unit_id,unit_name,conversion_rate,is_default,is_active',
            ]);

        if ($warehouseId !== null) {
            $query->withSum(['stockBalances as warehouse_stock' => function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            }], 'quantity');
        }

        $deletedIds = [];
        if (!empty($sinceParam)) {
            try {
                $since = Carbon::parse($sinceParam);
                $query->where(function ($q) use ($since) {
                    $q->where('updated_at', '>', $since)
                      ->orWhereHas('variants', function ($vq) use ($since) {
                          $vq->where('updated_at', '>', $since);
                      });
                });

                $deletedIds = Product::onlyTrashed()
                    ->where('deleted_at', '>', $since)
                    ->pluck('id')
                    ->toArray();
            } catch (\Exception $e) {
                // If timestamp parsing fails, ignore updated_since filter
            }
        }

        $items = $query->orderBy('updated_at', 'asc')->limit($limit)->get();

        return response()->json([
            'status' => 'success',
            'server_time' => now()->toIso8601String(),
            'count' => $items->count(),
            'products' => ProductListResource::collection($items)->resolve($request),
            'deleted_ids' => $deletedIds,
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function createOptions(Request $request)
    {
        // Product Create only needs lightweight option data. Avoid loading
        // descriptions, images and other unused columns for every form open.
        $categories = ProductCategory::select('id', 'name', 'status')
            ->where('status', 'Active')
            ->orWhereNull('status')
            ->orderBy('name')
            ->get();
        $brands = Brand::select('id', 'name', 'status')
            ->where('status', 'Active')
            ->orWhereNull('status')
            ->orderBy('name')
            ->get();
        $units = ProductUnit::select('id', 'name', 'status')
            ->where('status', 'Active')
            ->orWhereNull('status')
            ->orderBy('name')
            ->get();
        $saleUnits = SaleUnit::select(
                'id', 'name', 'base_unit_id', 'base_unit_name', 'description', 'status'
            )
            ->where('status', 'Active')
            ->orWhereNull('status')
            ->orderBy('name')
            ->get();
        $purchaseUnits = PurchaseUnit::select(
                'id', 'name', 'base_unit_id', 'base_unit_name', 'description', 'status'
            )
            ->where('status', 'Active')
            ->orWhereNull('status')
            ->orderBy('name')
            ->get();
        $suppliers = Supplier::select('id', 'name', 'phone', 'email', 'category', 'status')
            ->where('status', 'Active')
            ->orWhereNull('status')
            ->orderBy('name')
            ->get();
        $variations = Variation::select('id', 'name', 'variation_types', 'status')
            ->where('status', 'Active')
            ->orWhereNull('status')
            ->orderBy('name')
            ->get();
        $warehouses = Outlet::select('id', 'name', 'is_active')
            ->where('is_active', true)
            ->orWhereNull('is_active')
            ->orderBy('id')
            ->get()
            ->unique('name')
            ->values();

        return response()->json([
            'success' => true,
            'status' => 'success',
            'data' => [
                'categories' => $categories,
                'brands' => $brands,
                'units' => $units,
                'sale_units' => $saleUnits,
                'purchase_units' => $purchaseUnits,
                'suppliers' => $suppliers,
                'variation_attributes' => $variations,
                'warehouses' => $warehouses,
                'barcode_symbologies' => [
                    'CODE128',
                    'CODE39',
                    'EAN8',
                    'EAN13',
                    'UPC',
                ],
                'permissions' => [
                    'can_create_product' => true,
                    'can_manage_variants' => true,
                    'can_add_opening_stock' => true,
                    'can_upload_images' => true,
                ],
                'config' => [
                    'max_images' => 5,
                    'max_image_size_mb' => 5,
                    'selling_below_cost_behavior' => 'warning',
                ],
            ],
        ]);
    }

    public function checkIdentifier(Request $request)
    {
        $sku = trim((string)$request->input('sku', ''));
        $barcode = trim((string)$request->input('barcode', ''));
        $ignoreId = $request->input('ignore_id') ?: $request->input('product_id');
        $ignoreVariantId = $request->input('ignore_variant_id') ?: $request->input('variant_id');

        $isAvailable = true;
        $reason = null;

        if ($sku !== '') {
            $productQuery = Product::withTrashed()->where(function ($q) use ($sku) {
                $q->where('code', $sku)->orWhere('sku', $sku);
            });
            if (!empty($ignoreId)) {
                $productQuery->where('id', '!=', $ignoreId);
            }
            $existsInProducts = $productQuery->exists();

            $variantQuery = ProductVariant::withTrashed()->where('sku', $sku);
            if (!empty($ignoreVariantId)) {
                $variantQuery->where('id', '!=', $ignoreVariantId);
            }
            if (!empty($ignoreId)) {
                $variantQuery->where('product_id', '!=', $ignoreId);
            }
            $existsInVariants = $variantQuery->exists();

            if ($existsInProducts || $existsInVariants) {
                $isAvailable = false;
                $reason = 'Product Code / ID is already in use.';
            }
        }

        if ($isAvailable && $barcode !== '') {
            $productQuery = Product::withTrashed()->where('barcode', $barcode);
            if (!empty($ignoreId)) {
                $productQuery->where('id', '!=', $ignoreId);
            }
            $existsInProducts = $productQuery->exists();

            $variantQuery = ProductVariant::withTrashed()->where('barcode', $barcode);
            if (!empty($ignoreVariantId)) {
                $variantQuery->where('id', '!=', $ignoreVariantId);
            }
            if (!empty($ignoreId)) {
                $variantQuery->where('product_id', '!=', $ignoreId);
            }
            $existsInVariants = $variantQuery->exists();

            if ($existsInProducts || $existsInVariants) {
                $isAvailable = false;
                $reason = 'Barcode is already in use.';
            }
        }

        return response()->json([
            'success' => true,
            'status' => 'success',
            'data' => [
                'available' => $isAvailable,
                'reason' => $reason,
            ],
        ]);
    }

    public function generateSku(Request $request, ProductCreateService $service)
    {
        $sku = $service->generateNextSku();
        return response()->json([
            'success' => true,
            'status' => 'success',
            'data' => [
                'sku' => $sku,
                'code' => $sku,
            ],
        ]);
    }

    public function generateBarcode(Request $request, ProductCreateService $service)
    {
        $barcode = $service->generateNextBarcode();
        return response()->json([
            'success' => true,
            'status' => 'success',
            'data' => [
                'barcode' => $barcode,
            ],
        ]);
    }

    public function quickSearch(Request $request)
    {
        $rawQuery = trim((string)$request->input('q', $request->input('search', '')));
        $limit = min(max((int)$request->input('limit', 20), 1), 50);

        $warehouseParam = $request->input('warehouse_id') ?? $request->input('outlet_id');
        $warehouseId = ($warehouseParam && $warehouseParam !== 'All' && $warehouseParam !== 'all')
            ? (int)$warehouseParam
            : null;

        if ($rawQuery === '') {
            $products = Product::where(function ($q) {
                    $q->where('status', 'Active')->orWhere('is_active', true);
                })
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->with([
                    'primaryImage:id,product_id,disk,path,is_primary,sort_order',
                    'variants:id,product_id,name,sku,barcode,cost_price,selling_price,retail_prices,wholesale_price,wholesale_prices,is_active,sort_order,image_url',
                    'variants.values:id,product_variant_id,attribute_name,attribute_value',
                    'variants.stockBalances' => function ($q) use ($warehouseId) {
                        $q->select(['id', 'product_variant_id', 'warehouse_id', 'quantity']);
                        if ($warehouseId !== null) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    },
                    'stockBalances' => function ($q) use ($warehouseId) {
                        $q->select(['id', 'product_id', 'warehouse_id', 'product_variant_id', 'quantity']);
                        if ($warehouseId !== null) {
                            $q->where('warehouse_id', $warehouseId);
                        }
                    },
                    'unitLinks:id,product_id,product_variant_id,unit_type,unit_id,unit_name,conversion_rate,is_default,is_active',
                ])
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => \App\Http\Resources\ProductListResource::collection($products),
            ]);
        }

        $cacheVersion = \Illuminate\Support\Facades\Cache::get('products_cache_version', 1);
        $cacheKey = 'quick_search_v' . $cacheVersion . '_' . md5("{$rawQuery}|" . ($warehouseId ?? 'all') . "|{$limit}");

        $result = \Illuminate\Support\Facades\Cache::store('file')->remember($cacheKey, 120, function () use ($rawQuery, $limit, $warehouseId) {
            $isNumeric = ctype_digit($rawQuery);
            $intVal = $isNumeric ? (int)$rawQuery : null;
            $padded6 = $isNumeric ? sprintf('%06d', $intVal) : null;
            $unpadded = $isNumeric ? (string)$intVal : null;

            $extractedNumber = null;
            $extractedPadded = null;
            if (preg_match('/^(?:(PRD|SKU)[-_ ]*)?0*([1-9][0-9]*|[0-9]+)$/i', $rawQuery, $m)) {
                $numStr = $m[2] ?? '';
                if ($numStr !== '') {
                    $extractedNumber = (int)$numStr;
                    $extractedPadded = sprintf('%06d', $extractedNumber);
                }
            }

            $activeProductScope = function ($query) {
                $query->where(function ($q) {
                    $q->where('status', 'Active')->orWhere('is_active', true);
                });
            };

            // 1. Tier 1: Exact match on code, barcode, sku, or numeric id
            $exactProductIds = Product::tap($activeProductScope)
                ->where(function ($q) use ($rawQuery, $isNumeric, $intVal, $padded6, $unpadded, $extractedNumber, $extractedPadded) {
                    $q->where('code', $rawQuery)
                      ->orWhere('barcode', $rawQuery)
                      ->orWhere('sku', $rawQuery);

                    if ($extractedNumber !== null) {
                        $q->orWhere('id', $extractedNumber)
                          ->orWhere('code', $extractedPadded)
                          ->orWhere('code', 'PRD-' . $extractedPadded)
                          ->orWhere('code', (string)$extractedNumber)
                          ->orWhere('sku', $extractedPadded)
                          ->orWhere('sku', 'SKU-' . $extractedPadded);
                    }

                    if ($isNumeric) {
                        $q->orWhere('id', $intVal);
                        if ($padded6 && $padded6 !== $rawQuery) {
                            $q->orWhere('code', $padded6)
                              ->orWhere('code', 'PRD-' . $padded6)
                              ->orWhere('sku', 'SKU-' . $padded6);
                        }
                        if ($unpadded && $unpadded !== $rawQuery) {
                            $q->orWhere('code', $unpadded);
                        }
                    }
                })->limit(10)->pluck('id')->toArray();

            // 2. Exact match on variant barcode or SKU
            $exactVariantProductIds = ProductVariant::where(function ($q) use ($rawQuery, $isNumeric, $padded6, $extractedPadded) {
                    $q->where('barcode', $rawQuery)
                      ->orWhere('sku', $rawQuery);
                    if ($extractedPadded) {
                        $q->orWhere('sku', 'SKU-' . $extractedPadded)
                          ->orWhere('sku', $extractedPadded);
                    } elseif ($isNumeric && $padded6) {
                        $q->orWhere('sku', 'SKU-' . $padded6)
                          ->orWhere('sku', $padded6);
                    }
                })
                ->whereHas('product', $activeProductScope)
                ->limit(10)
                ->pluck('product_id')
                ->toArray();

            $allIds = array_values(array_unique(array_merge($exactProductIds, $exactVariantProductIds)));

            // EARLY EXIT: If exact match found (e.g., barcode scanner or direct code match), satisfy immediately
            if (!empty($allIds) && count($allIds) >= $limit) {
                return $this->loadProductsForQuickSearch(array_slice($allIds, 0, $limit), $warehouseId);
            }

            // 3. Prefix match on indexed columns (code, barcode, sku) using B-Tree index
            $prefixProductIds = Product::tap($activeProductScope)
                ->where(function ($q) use ($rawQuery, $extractedPadded) {
                    $q->where('code', 'like', "{$rawQuery}%")
                      ->orWhere('sku', 'like', "{$rawQuery}%")
                      ->orWhere('barcode', 'like', "{$rawQuery}%");

                    if ($extractedPadded) {
                        $q->orWhere('code', 'like', "PRD-{$extractedPadded}%")
                          ->orWhere('sku', 'like', "SKU-{$extractedPadded}%");
                    }
                })
                ->limit(15)
                ->pluck('id')
                ->toArray();

            $allIds = array_values(array_unique(array_merge($allIds, $prefixProductIds)));
            if (count($allIds) >= $limit) {
                return $this->loadProductsForQuickSearch(array_slice($allIds, 0, $limit), $warehouseId);
            }

            // 4. FULLTEXT match on MariaDB (name, code, sku, barcode, category_name, brand_name)
            $cleanTerms = trim(preg_replace('/[+\-><()~*"@]+/u', ' ', $rawQuery));
            if (mb_strlen($cleanTerms) >= 2) {
                $words = array_filter(explode(' ', $cleanTerms));
                if (!empty($words)) {
                    $booleanMode = implode(' ', array_map(fn($w) => "+{$w}*", $words));
                    try {
                        $fulltextIds = Product::tap($activeProductScope)
                            ->whereRaw(
                                "MATCH(name, code, sku, barcode, category_name, brand_name) AGAINST(? IN BOOLEAN MODE)",
                                [$booleanMode]
                            )
                            ->limit($limit)
                            ->pluck('id')
                            ->toArray();

                        $allIds = array_values(array_unique(array_merge($allIds, $fulltextIds)));
                        if (count($allIds) >= $limit) {
                            return $this->loadProductsForQuickSearch(array_slice($allIds, 0, $limit), $warehouseId);
                        }
                    } catch (\Throwable $e) {
                        // In case MariaDB throws on fulltext, fallback to prefix/name matching
                    }
                }
            }

            // 5. Variant SKU prefix or name match
            $variantFuzzyIds = ProductVariant::where(function ($q) use ($rawQuery) {
                    $q->where('sku', 'like', "{$rawQuery}%")
                      ->orWhere('barcode', 'like', "{$rawQuery}%")
                      ->orWhere('name', 'like', "{$rawQuery}%");
                })
                ->whereHas('product', $activeProductScope)
                ->limit(10)
                ->pluck('product_id')
                ->toArray();

            $allIds = array_values(array_unique(array_merge($allIds, $variantFuzzyIds)));
            if (count($allIds) >= $limit) {
                return $this->loadProductsForQuickSearch(array_slice($allIds, 0, $limit), $warehouseId);
            }

            // 6. Name / Alternative name fallback (prefix first, then contains)
            $fallbackProductIds = Product::tap($activeProductScope)
                ->where(function ($q) use ($rawQuery) {
                    $q->where('name', 'like', "{$rawQuery}%")
                      ->orWhere('alternative_name', 'like', "{$rawQuery}%")
                      ->orWhere('name', 'like', "%{$rawQuery}%");
                })
                ->limit($limit)
                ->pluck('id')
                ->toArray();

            $allIds = array_values(array_unique(array_merge($allIds, $fallbackProductIds)));
            if (count($allIds) >= $limit) {
                return $this->loadProductsForQuickSearch(array_slice($allIds, 0, $limit), $warehouseId);
            }

            // 7. Variant attribute match only if still under limit
            $variantAttrIds = \DB::table('product_variant_values')
                ->join('product_variants', 'product_variant_values.product_variant_id', '=', 'product_variants.id')
                ->join('products', 'product_variants.product_id', '=', 'products.id')
                ->where(function ($q) {
                    $q->where('products.status', 'Active')->orWhere('products.is_active', true);
                })
                ->whereNull('products.deleted_at')
                ->whereNull('product_variants.deleted_at')
                ->where(function ($q) use ($rawQuery) {
                    $q->where('product_variant_values.attribute_value', 'like', "%{$rawQuery}%")
                      ->orWhere('product_variant_values.attribute_name', 'like', "%{$rawQuery}%");
                })
                ->limit(10)
                ->pluck('product_variants.product_id')
                ->toArray();

            $allIds = array_values(array_unique(array_merge($allIds, $variantAttrIds)));
            $targetIds = array_slice($allIds, 0, $limit);

            if (empty($targetIds)) {
                return [];
            }

            return $this->loadProductsForQuickSearch($targetIds, $warehouseId);
        });

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    protected function loadProductsForQuickSearch(array $targetIds, ?int $warehouseId): array
    {
        if (empty($targetIds)) {
            return [];
        }

        $products = Product::whereIn('id', $targetIds)
            ->with([
                'primaryImage:id,product_id,disk,path,is_primary,sort_order',
                'variants:id,product_id,name,sku,barcode,cost_price,selling_price,retail_prices,wholesale_price,wholesale_prices,is_active,sort_order,image_url',
                'variants.values:id,product_variant_id,attribute_name,attribute_value',
                'variants.stockBalances' => function ($q) use ($warehouseId) {
                    $q->select(['id', 'product_variant_id', 'warehouse_id', 'quantity']);
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    }
                },
                'stockBalances' => function ($q) use ($warehouseId) {
                    $q->select(['id', 'product_id', 'warehouse_id', 'product_variant_id', 'quantity']);
                    if ($warehouseId !== null) {
                        $q->where('warehouse_id', $warehouseId);
                    }
                },
                'unitLinks:id,product_id,product_variant_id,unit_type,unit_id,unit_name,conversion_rate,is_default,is_active',
            ])
            ->get();

        // Preserve order of relevance
        $sorted = $products->sortBy(function ($p) use ($targetIds) {
            $pos = array_search($p->id, $targetIds);
            return $pos === false ? 999 : $pos;
        })->values();

        return \App\Http\Resources\ProductListResource::collection($sorted)->resolve();
    }

    public function findByBarcode(Request $request)
    {
        $code = $request->input('code');
        if (empty($code)) {
            return response()->json(['status' => 'error', 'message' => 'Barcode is required'], 400);
        }

        $isNumeric = ctype_digit($code);
        $intVal = $isNumeric ? (int)$code : null;
        $padded6 = $isNumeric ? sprintf('%06d', $intVal) : null;
        $unpadded = $isNumeric ? (string)$intVal : null;

        $product = Product::with($this->productDetailRelations())
            ->where(function ($q) use ($code, $isNumeric, $intVal, $padded6, $unpadded) {
                $q->where('code', $code)
                  ->orWhere('sku', $code)
                  ->orWhere('barcode', $code);
                if ($isNumeric) {
                    $q->orWhere('id', $intVal);
                    if ($padded6 && $padded6 !== $code) {
                        $q->orWhere('code', $padded6)->orWhere('sku', 'SKU-' . $padded6);
                    }
                    if ($unpadded && $unpadded !== $code) {
                        $q->orWhere('code', $unpadded);
                    }
                }
            })
            ->first();

        if (!$product) {
            $variant = ProductVariant::with('product.unitLinks')->where('barcode', $code)->orWhere('sku', $code)->first();
            if ($variant && $variant->product) {
                return response()->json([
                    'status' => 'success',
                    'data' => new ProductResource($variant->product),
                    'variant' => $variant,
                ]);
            }
            return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => new ProductResource($product),
        ]);
    }

    public function store(StoreProductRequest $request, ProductCreateService $createService)
    {
        $data = $request->validated();

        // Check if request payload is sent as JSON string in multipart
        if ($request->has('payload') && is_string($request->input('payload'))) {
            $decoded = json_decode($request->input('payload'), true);
            if (is_array($decoded)) {
                $data = array_merge($decoded, $data);
            }
        }

        $images = $request->file('images', []);
        if (!is_array($images) && $images) {
            $images = [$images];
        }

        $product = $createService->create($data, $images);

        try {
            \Illuminate\Support\Facades\Cache::store('file')->flush();
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => 'Product created successfully.',
            'data' => new ProductResource($product),
        ], 201);
    }

    /**
     * Upload and store product image on public disk.
     * Optimized for lightweight local storage on $6 DigitalOcean droplet.
     */
    public function uploadImage(Request $request)
    {
        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
            'folder' => ['nullable', 'string', 'in:products,brands,categories,company,users,avatars,settings,payment_methods,payments'],
        ]);

        $folder = $validated['folder'] ?? 'products';
        $file = $request->file('image');

        // Store file in storage/app/public/{folder}
        $path = $file->store($folder, 'public');

        // Generate full reachable URL
        $baseHost = $request->getSchemeAndHttpHost() ?: config('app.url');
        $url = rtrim($baseHost, '/') . '/storage/' . ltrim($path, '/');

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => 'Image uploaded successfully.',
            'data' => [
                'path' => $path,
                'url' => $url,
                'file_name' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(),
                'size_kb' => round($file->getSize() / 1024, 1),
            ],
        ], 200);
    }

    public function show($id)
    {
        $product = Product::with($this->productDetailRelations())->find($id);

        if (!$product) {
            return response()->json(['status' => 'error', 'message' => 'Product not found'], 404);
        }

        return response()->json([
            'success' => true,
            'status' => 'success',
            'data' => new ProductResource($product),
        ]);
    }

    public function update(Request $request, $id, ProductCreateService $updateService)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['success' => false, 'status' => 'error', 'message' => 'Product not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:150'],
            'code' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('products', 'code')->ignore($id)],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'barcode_symbology' => ['nullable', 'string', 'max:50'],
            'alternative_name' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'sub_category_id' => ['nullable', 'integer'],
            'sub_category_name' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'base_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'unit_name' => ['nullable', 'string', 'max:100'],
            'purchase_unit_id' => ['nullable', 'integer'],
            'purchase_unit' => ['nullable', 'string', 'max:100'],
            'sale_unit_id' => ['nullable', 'integer'],
            'sale_unit' => ['nullable', 'string', 'max:100'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'retail_prices' => ['nullable', 'array'],
            'retail_prices.*' => ['numeric', 'min:0'],
            'selling_prices' => ['nullable', 'array'],
            'selling_prices.*' => ['numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_prices' => ['nullable', 'array'],
            'wholesale_prices.*' => ['numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'numeric', 'min:0'],
            'alert_quantity' => ['nullable', 'numeric', 'min:0'],
            'stock_alert' => ['nullable', 'numeric', 'min:0'],
            'has_variants' => ['nullable', 'boolean'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.retail_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.retail_prices' => ['nullable', 'array'],
            'variants.*.retail_prices.*' => ['numeric', 'min:0'],
            'variants.*.selling_prices' => ['nullable', 'array'],
            'variants.*.selling_prices.*' => ['numeric', 'min:0'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.wholesale_prices' => ['nullable', 'array'],
            'variants.*.wholesale_prices.*' => ['numeric', 'min:0'],
            'variants.*.values' => ['nullable', 'array'],
            'variants.*.attribute_values' => ['nullable', 'array'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.image_url' => ['nullable', 'string'],
            'unit_links' => ['nullable', 'array'],
            'unit_links.*.product_variant_id' => ['nullable', 'integer'],
            'unit_links.*.variant_sku' => ['nullable', 'string', 'max:100'],
            'unit_links.*.unit_type' => ['required_with:unit_links', 'string', 'in:purchase,sale'],
            'unit_links.*.unit_id' => ['nullable', 'integer', 'min:1'],
            'unit_links.*.unit_name' => ['required_with:unit_links', 'string', 'max:100'],
            'unit_links.*.conversion_rate' => ['required_with:unit_links', 'numeric', 'gt:0'],
            'unit_links.*.is_default' => ['nullable', 'boolean'],
            'unit_links.*.is_active' => ['nullable', 'boolean'],
            'opening_stocks' => ['nullable', 'array'],
            'opening_stocks.*.warehouse_id' => ['nullable', 'integer'],
            'opening_stocks.*.variant_sku' => ['nullable', 'string', 'max:100'],
            'opening_stocks.*.quantity' => ['required_with:opening_stocks', 'numeric', 'min:0'],
            'opening_stocks.*.unit_type' => ['nullable', 'string'],
            'opening_stocks.*.unit_id' => ['nullable', 'integer'],
            'opening_stocks.*.unit_name' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', 'string'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['nullable'],
            'description' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', 'in:Active,Inactive'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $updateService->update($product, $validated);

        try {
            \Illuminate\Support\Facades\Cache::store('file')->flush();
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => 'Product updated successfully',
            'data' => new ProductResource($updated),
        ]);
    }

    public function toggleStatus(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        $newStatus = ($product->status === 'Active' || $product->is_active) ? 'Inactive' : 'Active';
        $product->status = $newStatus;
        $product->is_active = ($newStatus === 'Active');
        $product->save();

        try {
            \Illuminate\Support\Facades\Cache::store('file')->flush();
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => "Product marked as {$newStatus}",
            'data' => [
                'id' => $product->id,
                'status' => $newStatus,
                'is_active' => $product->is_active,
            ],
        ]);
    }

    public function toggleBotStatus(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Product not found',
            ], 404);
        }

        $product->is_bot_available = !$product->is_bot_available;
        $product->save();

        try {
            \Illuminate\Support\Facades\Cache::store('file')->flush();
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => $product->is_bot_available ? 'Product enabled for Telegram Bot' : 'Product disabled for Telegram Bot',
            'data' => [
                'id' => $product->id,
                'is_bot_available' => $product->is_bot_available,
            ],
        ]);
    }

    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['success' => false, 'status' => 'error', 'message' => 'Product not found'], 404);
        }

        // 🔒 Senior Accountant Safeguard: Protect inventory capital and audit trails
        $stockQty = (float)($product->stock_quantity ?? 0);
        $totalDependencies = (int)$product->total_dependencies;

        if ($stockQty > 0 || $totalDependencies > 0) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => 'Product cannot be deleted because it has active inventory on hand or is linked to historical sales/purchases/adjustments. Internal accounting controls require preserving inventory ledger history. Please deactivate the product instead.',
                'data' => [
                    'stock_quantity' => $stockQty,
                    'total_dependencies' => $totalDependencies,
                ],
            ], 422);
        }

        $product->delete();

        try {
            \Illuminate\Support\Facades\Cache::store('file')->flush();
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'status' => 'success',
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * Keep Details/Edit complete while selecting only columns consumed by the
     * resources. The list endpoint intentionally does not use this graph.
     */
    private function productDetailRelations(): array
    {
        return [
            'variants:id,product_id,name,sku,barcode,cost_price,selling_price,retail_prices,wholesale_price,wholesale_prices,is_active,sort_order,image_url',
            'variants.values:id,product_variant_id,variation_attribute_id,variation_value_id,attribute_name,attribute_value',
            'variants.stockBalances.warehouse',
            'images:id,product_id,disk,path,original_name,is_primary,sort_order',
            'unitLinks:id,product_id,product_variant_id,unit_type,unit_id,unit_name,conversion_rate,is_default,is_active',
            'stockBalances.warehouse',
        ];
    }

    public function downloadTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="product_import_template_utf8.csv"',
        ];

        $columns = [
            'Product Type', 'Product Code', 'Product Name', 'Alternative Name (မြန်မာ)',
            'Category', 'Brand', 'Base Unit', 'Purchase Unit', 'Sale Unit', 'Purchase Unit Links', 'Sale Unit Links',
            'Product Cost', 'Retail Price', 'Wholesale Price', 'Stock Alert',
            'Variation Name', 'Variation Type', 'Variation Code', 'Stock Qty', 'Status'
        ];

        $sampleRows = [
            ['Single', '10001', 'Classic Cheeseburger Deluxe', 'ချိစ်ဘာဂါ (Classic)', 'Main Dishes', 'Burger Club', 'Plate', 'Plate', 'Plate', '', 'Box:10, Plate:1', '4500', '8500, 8000, 7500', '7500, 7000', '10', '', '', '10001', '50', 'Active'],
            ['Single', '10002', 'Spicy Seafood Fried Rice', 'ပင်လယ်စာ ထမင်းကြော်စပ်စပ်', 'Rice Dishes', 'Chef Special', 'Plate', 'Plate', 'Plate', '', 'Plate:1', '3500', '7000, 6500', '6000, 5500', '15', '', '', '10002', '40', 'Active'],
            ['Variation', '10428', 'Cyan Sports Jersey', 'အားကစားအင်္ကျီ (Cyan)', 'Apparel', 'Adidas', 'Piece', 'Box', 'Piece', 'Box:20, Piece:1', 'Box:20, Pack:5, Piece:1', '5000', '12000, 11000, 10000', '10000, 9500', '10', 'cyan', 'cyan - 44', '1042801', '20', 'Active'],
            ['Variation', '10428', 'Cyan Sports Jersey', 'အားကစားအင်္ကျီ (Cyan)', 'Apparel', 'Adidas', 'Piece', 'Box', 'Piece', 'Box:20, Piece:1', 'Box:20, Pack:5, Piece:1', '5000', '12000, 11000, 10000', '10000, 9500', '10', 'cyan', 'cyan - 33', '1042802', '25', 'Active'],
            ['Variation', '10408', 'Premium Cotton Polo T-Shirt', 'ပိုလိုတီရှပ်', 'Apparel', 'Polo Classic', 'Piece', 'Box', 'Piece', 'Box:24, Piece:1', 'Box:24, Pack:6, Piece:1', '5000', '12000, 11500', '10000, 9500', '10', 'Color', 'Red', '1040801', '20', 'Active'],
            ['Variation', '10408', 'Premium Cotton Polo T-Shirt', 'ပိုလိုတီရှပ်', 'Apparel', 'Polo Classic', 'Piece', 'Box', 'Piece', 'Box:24, Piece:1', 'Box:24, Pack:6, Piece:1', '5000', '12000, 11500', '10000, 9500', '10', 'Color', 'Blue', '1040802', '25', 'Active'],
            ['Variation', '10408', 'Premium Cotton Polo T-Shirt', 'ပိုလိုတီရှပ်', 'Apparel', 'Polo Classic', 'Piece', 'Box', 'Piece', 'Box:24, Piece:1', 'Box:24, Pack:6, Piece:1', '5000', '12000, 11500', '10000, 9500', '10', 'Color', 'Black', '1040803', '30', 'Active'],
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

    public function export(Request $request)
    {
        $query = Product::query()
            ->with([
                'variants.values',
                'unitLinks' => function ($q) {
                    $q->where('is_active', true);
                },
            ]);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('alternative_name', 'like', "%{$search}%")
                  ->orWhere('brand_name', 'like', "%{$search}%")
                  ->orWhere('category_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category') && $request->input('category') !== 'All') {
            $query->where('category_name', $request->input('category'));
        }

        if ($request->filled('brand') && $request->input('brand') !== 'All') {
            $query->where('brand_name', $request->input('brand'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        $columns = [
            'Product Type', 'Product Code', 'Product Name', 'Alternative Name (မြန်မာ)',
            'Category', 'Brand', 'Base Unit', 'Purchase Unit', 'Sale Unit', 'Purchase Unit Links', 'Sale Unit Links',
            'Product Cost', 'Retail Price', 'Wholesale Price', 'Stock Alert',
            'Variation Name', 'Variation Type', 'Variation Code', 'Stock Qty', 'Status'
        ];

        $formatNum = function ($num) {
            $floatVal = (float)$num;
            return ($floatVal == (int)$floatVal) ? (string)(int)$floatVal : (string)$floatVal;
        };

        $formatPriceList = function ($prices, $defaultVal = 0) use ($formatNum) {
            if (is_array($prices) && !empty($prices)) {
                $formatted = [];
                foreach ($prices as $p) {
                    $val = (float)$p;
                    if ($val > 0) {
                        $formatted[] = $formatNum($val);
                    }
                }
                if (!empty($formatted)) {
                    return implode(', ', $formatted);
                }
            }
            $single = (float)$defaultVal;
            return $single > 0 ? $formatNum($single) : '0';
        };

        $formatBarcode = function ($code) {
            $code = trim((string)$code);
            if ($code === '') return '';
            if (preg_match('/^\d{10,}$/', $code) || (strlen($code) > 1 && $code[0] === '0' && ctype_digit($code))) {
                return '="' . $code . '"';
            }
            return $code;
        };

        @set_time_limit(300);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products_export_' . date('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
            'X-Accel-Buffering' => 'no',
        ];

        $callback = function () use ($query, $columns, $formatNum, $formatPriceList, $formatBarcode) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Microsoft Excel on Windows
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns);

            $rowCount = 0;
            $query->orderBy('id', 'asc')->chunk(500, function ($products) use (
                &$rowCount, $file, $formatBarcode, $formatNum, $formatPriceList
            ) {
                foreach ($products as $product) {
                $baseUnit = $product->unit_name ?: 'Piece';
                $purchUnit = $product->purchase_unit ?: $baseUnit;
                $saleUnit = $product->sale_unit ?: $baseUnit;

                // Purchase unit links
                $purchLinks = $product->unitLinks
                    ->where('unit_type', 'purchase')
                    ->map(function ($link) use ($formatNum) {
                        return $link->unit_name . ':' . $formatNum($link->conversion_rate);
                    })->implode(', ');

                // Sale unit links
                $saleLinks = $product->unitLinks
                    ->where('unit_type', 'sale')
                    ->map(function ($link) use ($formatNum) {
                        return $link->unit_name . ':' . $formatNum($link->conversion_rate);
                    })->implode(', ');

                $alertVal = (float)($product->stock_alert ?: ($product->alert_quantity ?: 10));
                $alertStr = $formatNum($alertVal);
                $category = $product->category_name ?: 'General';
                $brand = $product->brand_name ?: 'Generic';
                $status = $product->status ?: ($product->is_active ? 'Active' : 'Inactive');
                $pCodeFormatted = $formatBarcode($product->code);

                if ($product->has_variants && $product->variants->isNotEmpty()) {
                    foreach ($product->variants as $variant) {
                        $vCost = (float)($variant->cost_price ?: $product->cost_price);
                        $vCostStr = $formatNum($vCost);

                        $vRetailStr = $formatPriceList(
                            $variant->retail_prices,
                            $variant->selling_price ?: $product->retail_price
                        );

                        $vWholesaleStr = $formatPriceList(
                            $variant->wholesale_prices,
                            $variant->wholesale_price ?: $product->wholesale_price
                        );

                        $varName = 'Variation';
                        $varType = $variant->name ?: 'Standard';

                        if ($variant->values && $variant->values->isNotEmpty()) {
                            $attrNames = $variant->values->pluck('attribute_name')->filter()->unique()->values();
                            if ($attrNames->isNotEmpty()) {
                                $varName = $attrNames->implode(' / ');
                            }
                            $attrVals = $variant->values->pluck('attribute_value')->filter()->values();
                            if ($attrVals->isNotEmpty()) {
                                $varType = $attrVals->implode(' - ');
                            }
                        }

                        $vCode = $variant->sku ?: ($variant->barcode ?: $product->code);
                        $vStockStr = $formatNum($variant->stock_quantity);
                        $vCodeFormatted = $formatBarcode($vCode);

                        fputcsv($file, [
                            'Variation',
                            $pCodeFormatted,
                            $product->name,
                            $product->alternative_name ?? '',
                            $category,
                            $brand,
                            $baseUnit,
                            $purchUnit,
                            $saleUnit,
                            $purchLinks,
                            $saleLinks,
                            $vCostStr,
                            $vRetailStr,
                            $vWholesaleStr,
                            $alertStr,
                            $varName,
                            $varType,
                            $vCodeFormatted,
                            $vStockStr,
                            $variant->is_active ? $status : 'Inactive',
                        ]);
                    }
                } else {
                    $pCostStr = $formatNum($product->cost_price);
                    $pRetailStr = $formatPriceList($product->retail_prices, $product->retail_price);
                    $pWholesaleStr = $formatPriceList($product->wholesale_prices, $product->wholesale_price);
                    $pStockStr = $formatNum($product->stock_quantity);

                    fputcsv($file, [
                        'Single',
                        $pCodeFormatted,
                        $product->name,
                        $product->alternative_name ?? '',
                        $category,
                        $brand,
                        $baseUnit,
                        $purchUnit,
                        $saleUnit,
                        $purchLinks,
                        $saleLinks,
                        $pCostStr,
                        $pRetailStr,
                        $pWholesaleStr,
                        $alertStr,
                        '',
                        '',
                        $pCodeFormatted,
                        $pStockStr,
                        $status,
                    ]);
                }

                $rowCount++;
                }
                flush();
            });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function bulkImport(Request $request, ProductCreateService $createService)
    {
        $items    = $request->input('products', []);
        $strategy = $request->input('duplicate_strategy', 'update');

        if (empty($items) || !is_array($items)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No products provided for import',
            ], 400);
        }

        $cleanBarcode = function ($val) {
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
        $updated  = 0;
        $skipped  = 0;

        foreach ($items as $item) {
            $code = $cleanBarcode($item['code'] ?? '');
            $name = trim($item['name'] ?? '');
            if (empty($code) || empty($name)) {
                $skipped++;
                continue;
            }

            $item['code'] = $code;
            if (!empty($item['sku'])) {
                $item['sku'] = $cleanBarcode($item['sku']);
            }
            if (!empty($item['barcode'])) {
                $item['barcode'] = $cleanBarcode($item['barcode']);
            }
            if (!empty($item['variants']) && is_array($item['variants'])) {
                foreach ($item['variants'] as &$v) {
                    if (!empty($v['sku'])) $v['sku'] = $cleanBarcode($v['sku']);
                    if (!empty($v['barcode'])) $v['barcode'] = $cleanBarcode($v['barcode']);
                }
                unset($v);
            }

            $existing = Product::where('code', $code)->orWhere('sku', $code)->first();

            if ($existing) {
                if ($strategy === 'skip') {
                    $skipped++;
                    continue;
                }
                if ($strategy === 'auto_code') {
                    $item['code'] = $createService->generateNextSku();
                    $item['sku'] = $item['code'];
                    $createService->create($item);
                    $imported++;
                    continue;
                }
                // Update existing product
                $createService->update($existing, $item);
                $updated++;
            } else {
                $createService->create($item);
                $imported++;
            }
        }

        return response()->json([
            'status'  => 'success',
            'message' => "Bulk import completed. Imported: {$imported}, Updated: {$updated}, Skipped: {$skipped}",
            'summary' => [
                'imported' => $imported,
                'updated'  => $updated,
                'skipped'  => $skipped,
            ],
        ]);
    }
}
