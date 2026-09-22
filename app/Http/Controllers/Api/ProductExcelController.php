<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\Brand;
use App\Models\Outlet;
use App\Services\ProductCreateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductExcelController extends Controller
{
    /**
     * Import new products from Excel/CSV with strict atomicity (All-or-Nothing)
     * and duplicate code protection.
     */
    public function importNewProducts(Request $request, ProductCreateService $createService)
    {
        $request->validate([
            'file' => 'required|file|max:20480', // max 20MB
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid file format. Please upload an Excel (.xlsx, .xls) or CSV file.',
            ], 422);
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to parse Excel file: ' . $e->getMessage(),
            ], 422);
        }

        if (count($rows) < 2) {
            return response()->json([
                'status' => 'error',
                'message' => 'The uploaded file does not contain any product data rows.',
            ], 422);
        }

        // 1. Map Header Columns
        $rawHeaders = array_shift($rows);
        $headerMap = $this->mapImportHeaders($rawHeaders);

        if (!isset($headerMap['code']) || !isset($headerMap['name'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing essential header columns in Excel. Must include at least "Product Code" and "Product Name".',
            ], 422);
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

        // 2. Comprehensive Pre-Validation Phase (Zero DB Writes)
        $errors = [];
        $seenCodesInFile = []; // code => row_number
        $parsedItems = [];
        $allCodes = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // 1-indexed Excel row (row 1 is header)

            // Check if entire row is empty
            $nonEmpty = array_filter($row, fn($c) => trim((string)$c) !== '');
            if (empty($nonEmpty)) {
                continue;
            }

            $code = $cleanBarcode($row[$headerMap['code']] ?? '');
            $name = trim((string)($row[$headerMap['name']] ?? ''));
            $type = isset($headerMap['type']) ? trim((string)($row[$headerMap['type']] ?? 'Single')) : 'Single';
            $altName = isset($headerMap['alt_name']) ? trim((string)($row[$headerMap['alt_name']] ?? '')) : null;
            $category = isset($headerMap['category']) ? trim((string)($row[$headerMap['category']] ?? 'General')) : 'General';
            $brand = isset($headerMap['brand']) ? trim((string)($row[$headerMap['brand']] ?? 'Generic')) : 'Generic';
            $baseUnit = isset($headerMap['unit']) ? trim((string)($row[$headerMap['unit']] ?? 'Piece')) : 'Piece';
            $purchUnit = isset($headerMap['purch_unit']) ? trim((string)($row[$headerMap['purch_unit']] ?? $baseUnit)) : $baseUnit;
            $saleUnit = isset($headerMap['sale_unit']) ? trim((string)($row[$headerMap['sale_unit']] ?? $baseUnit)) : $baseUnit;
            $purchLinks = isset($headerMap['purch_links']) ? trim((string)($row[$headerMap['purch_links']] ?? '')) : '';
            $saleLinks = isset($headerMap['sale_links']) ? trim((string)($row[$headerMap['sale_links']] ?? '')) : '';

            $rawCost = isset($headerMap['cost']) ? trim((string)($row[$headerMap['cost']] ?? '0')) : '0';
            $rawPrice = isset($headerMap['price']) ? trim((string)($row[$headerMap['price']] ?? '0')) : '0';
            $rawWholesale = isset($headerMap['wholesale']) ? trim((string)($row[$headerMap['wholesale']] ?? '0')) : '0';
            $rawAlert = isset($headerMap['alert']) ? trim((string)($row[$headerMap['alert']] ?? '10')) : '10';
            $rawStock = isset($headerMap['stock']) ? trim((string)($row[$headerMap['stock']] ?? '0')) : '0';
            $status = isset($headerMap['status']) ? trim((string)($row[$headerMap['status']] ?? 'Active')) : 'Active';

            // Variation attributes
            $varName = isset($headerMap['var_name']) ? trim((string)($row[$headerMap['var_name']] ?? '')) : '';
            $varType = isset($headerMap['var_type']) ? trim((string)($row[$headerMap['var_type']] ?? '')) : '';
            $varCode = isset($headerMap['var_code']) ? $cleanBarcode($row[$headerMap['var_code']] ?? '') : '';

            // Validation Rule 1: Product Code required
            if (empty($code)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $code ?: '(Blank)',
                    'field' => 'Product Code',
                    'message' => "Row {$rowNumber}: Product Code is required and cannot be blank.",
                ];
            } else {
                // Validation Rule 2: In-File Duplicate Code Check
                if (isset($seenCodesInFile[$code])) {
                    $firstRow = $seenCodesInFile[$code];
                    $errors[] = [
                        'row' => $rowNumber,
                        'code' => $code,
                        'field' => 'Product Code',
                        'message' => "Row {$rowNumber}: Duplicate Product Code \"{$code}\" found in Excel (already declared at Row {$firstRow}).",
                    ];
                } else {
                    $seenCodesInFile[$code] = $rowNumber;
                    $allCodes[] = $code;
                }
            }

            // Validation Rule 3: Product Name required
            if (empty($name)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'field' => 'Product Name',
                    'message' => "Row {$rowNumber}: Product Name is required.",
                ];
            }

            // Validation Rule 4: Category required
            if (empty($category)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'field' => 'Category',
                    'message' => "Row {$rowNumber}: Category is required.",
                ];
            }

            // Validation Rule 5: Base Unit required
            if (empty($baseUnit)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'field' => 'Base Unit',
                    'message' => "Row {$rowNumber}: Base Unit is required.",
                ];
            }

            // Validation Rule 6: Cost Price numeric >= 0
            $costVal = str_replace(',', '', $rawCost);
            if (!is_numeric($costVal) || (float)$costVal < 0) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'field' => 'Product Cost',
                    'message' => "Row {$rowNumber}: Product Cost must be a valid non-negative number.",
                ];
            }

            // Validation Rule 7: Retail Price numeric >= 0
            $priceVal = str_replace(',', '', $rawPrice);
            if (!is_numeric($priceVal) || (float)$priceVal < 0) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'field' => 'Retail Price',
                    'message' => "Row {$rowNumber}: Retail Price must be a valid non-negative number.",
                ];
            }

            // Validation Rule 8: Wholesale Price numeric >= 0
            $wholesaleVal = str_replace(',', '', $rawWholesale);
            if ($rawWholesale !== '' && (!is_numeric($wholesaleVal) || (float)$wholesaleVal < 0)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'field' => 'Wholesale Price',
                    'message' => "Row {$rowNumber}: Wholesale Price must be a valid non-negative number.",
                ];
            }

            $parsedItems[] = [
                'row' => $rowNumber,
                'type' => $type,
                'code' => $code,
                'sku' => $code,
                'barcode' => $code,
                'name' => $name,
                'alternative_name' => $altName,
                'category_name' => $category,
                'brand_name' => $brand,
                'unit_name' => $baseUnit,
                'purchase_unit' => $purchUnit,
                'sale_unit' => $saleUnit,
                'purchase_unit_links' => $purchLinks,
                'sale_unit_links' => $saleLinks,
                'cost_price' => (float)$costVal,
                'purchase_price' => (float)$costVal,
                'retail_price' => (float)$priceVal,
                'selling_price' => (float)$priceVal,
                'wholesale_price' => (float)$wholesaleVal,
                'alert_quantity' => (float)($rawAlert !== '' && is_numeric($rawAlert) ? $rawAlert : 10),
                'stock_alert' => (float)($rawAlert !== '' && is_numeric($rawAlert) ? $rawAlert : 10),
                'stock_quantity' => (float)($rawStock !== '' && is_numeric($rawStock) ? $rawStock : 0),
                'status' => strcasecmp($status, 'Inactive') === 0 ? 'Inactive' : 'Active',
                'is_active' => strcasecmp($status, 'Inactive') !== 0,
                'var_name' => $varName,
                'var_type' => $varType,
                'var_code' => $varCode,
            ];
        }

        // Validation Rule 9: Database Duplicate Code Check
        if (!empty($allCodes)) {
            $existingDbCodes = Product::whereIn('code', $allCodes)
                ->orWhereIn('sku', $allCodes)
                ->pluck('code')
                ->map(fn($c) => trim(strtolower($c)))
                ->flip()
                ->toArray();

            $existingVariantSkus = ProductVariant::whereIn('sku', $allCodes)
                ->orWhereIn('barcode', $allCodes)
                ->pluck('sku')
                ->map(fn($c) => trim(strtolower($c)))
                ->flip()
                ->toArray();

            foreach ($parsedItems as $item) {
                $cLower = strtolower($item['code']);
                if (isset($existingDbCodes[$cLower]) || isset($existingVariantSkus[$cLower])) {
                    $errors[] = [
                        'row' => $item['row'],
                        'code' => $item['code'],
                        'field' => 'Product Code',
                        'message' => "Row {$item['row']}: Product Code \"{$item['code']}\" already exists in the database.",
                    ];
                }
            }
        }

        // STRICT ATOMICITY CHECK: If any error found, DO NOT import anything!
        if (count($errors) > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Excel import blocked: ' . count($errors) . ' validation error(s) found. Zero records were imported.',
                'error_count' => count($errors),
                'total_rows' => count($parsedItems),
                'errors' => $errors,
            ], 422);
        }

        // 3. Execution Phase in Atomic Transaction
        $importedCount = 0;
        $warehouseId = $request->input('warehouse_id');
        if (!$warehouseId) {
            $defaultOutlet = Outlet::where('is_active', true)->orderBy('id')->first()
                ?? Outlet::firstOrCreate(['name' => 'Main Warehouse'], ['is_active' => true]);
            $warehouseId = $defaultOutlet->id;
        }

        try {
            DB::transaction(function () use ($parsedItems, $createService, $warehouseId, &$importedCount) {
                // Pre-cache or auto-create categories and units
                $categoriesCache = ProductCategory::pluck('id', 'name')->mapWithKeys(function ($id, $name) {
                    return [strtolower(trim($name)) => $id];
                })->toArray();

                $unitsCache = ProductUnit::pluck('id', 'name')->mapWithKeys(function ($id, $name) {
                    return [strtolower(trim($name)) => $id];
                })->toArray();

                $brandsCache = Brand::pluck('id', 'name')->mapWithKeys(function ($id, $name) {
                    return [strtolower(trim($name)) => $id];
                })->toArray();

                foreach ($parsedItems as $item) {
                    $item['warehouse_id'] = $warehouseId;
                    // Resolve category
                    $catLower = strtolower($item['category_name']);
                    if (!isset($categoriesCache[$catLower])) {
                        $newCat = ProductCategory::create(['name' => $item['category_name'], 'is_active' => true]);
                        $categoriesCache[$catLower] = $newCat->id;
                    }
                    $item['category_id'] = $categoriesCache[$catLower];

                    // Resolve base unit
                    $unitLower = strtolower($item['unit_name']);
                    if (!isset($unitsCache[$unitLower])) {
                        $newUnit = ProductUnit::create(['name' => $item['unit_name'], 'is_active' => true]);
                        $unitsCache[$unitLower] = $newUnit->id;
                    }
                    $item['base_unit_id'] = $unitsCache[$unitLower];

                    // Resolve brand
                    $brandLower = strtolower($item['brand_name']);
                    if (!isset($brandsCache[$brandLower])) {
                        $newBrand = Brand::create(['name' => $item['brand_name'], 'is_active' => true]);
                        $brandsCache[$brandLower] = $newBrand->id;
                    }
                    $item['brand_id'] = $brandsCache[$brandLower];

                    // Parse unit links
                    $item['unit_links'] = $this->parseUnitLinks(
                        $item['purchase_unit_links'],
                        $item['sale_unit_links']
                    );

                    $createService->create($item);
                    $importedCount++;
                }
            });
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction failed during product insertion: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Successfully imported {$importedCount} new products via Excel.",
            'imported_count' => $importedCount,
        ]);
    }

    /**
     * Bulk Change Product Prices and Costs via Excel with strict validation.
     */
    public function importPriceChanges(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:20480',
        ]);

        $file = $request->file('file');
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to parse Excel file: ' . $e->getMessage(),
            ], 422);
        }

        if (count($rows) < 2) {
            return response()->json([
                'status' => 'error',
                'message' => 'The uploaded file does not contain any product price change rows.',
            ], 422);
        }

        $rawHeaders = array_shift($rows);
        $headerMap = $this->mapPriceChangeHeaders($rawHeaders);

        if (!isset($headerMap['code'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing "Product Code" header in price change Excel file.',
            ], 422);
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

        $errors = [];
        $updates = [];
        $allCodes = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            $nonEmpty = array_filter($row, fn($c) => trim((string)$c) !== '');
            if (empty($nonEmpty)) continue;

            $code = $cleanBarcode($row[$headerMap['code']] ?? '');
            if (empty($code)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => '(Blank)',
                    'field' => 'Product Code',
                    'message' => "Row {$rowNumber}: Product Code is required.",
                ];
                continue;
            }

            $rawNewCost = isset($headerMap['new_cost']) ? trim((string)($row[$headerMap['new_cost']] ?? '')) : '';
            $rawNewRetail = isset($headerMap['new_retail']) ? trim((string)($row[$headerMap['new_retail']] ?? '')) : '';
            $rawNewWholesale = isset($headerMap['new_wholesale']) ? trim((string)($row[$headerMap['new_wholesale']] ?? '')) : '';

            $hasCostUpdate = $rawNewCost !== '';
            $hasRetailUpdate = $rawNewRetail !== '';
            $hasWholesaleUpdate = $rawNewWholesale !== '';

            if (!$hasCostUpdate && !$hasRetailUpdate && !$hasWholesaleUpdate) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => $code,
                    'field' => 'New Price / Cost',
                    'message' => "Row {$rowNumber}: At least one price or cost value must be provided for update.",
                ];
                continue;
            }

            $newCostVal = null;
            if ($hasCostUpdate) {
                $cleaned = str_replace(',', '', $rawNewCost);
                if (!is_numeric($cleaned) || (float)$cleaned < 0) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'code' => $code,
                        'field' => 'New Cost Price',
                        'message' => "Row {$rowNumber}: New Cost Price must be a valid non-negative number.",
                    ];
                } else {
                    $newCostVal = (float)$cleaned;
                }
            }

            $newRetailVal = null;
            if ($hasRetailUpdate) {
                $cleaned = str_replace(',', '', $rawNewRetail);
                if (!is_numeric($cleaned) || (float)$cleaned < 0) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'code' => $code,
                        'field' => 'New Retail Price',
                        'message' => "Row {$rowNumber}: New Retail Price must be a valid non-negative number.",
                    ];
                } else {
                    $newRetailVal = (float)$cleaned;
                }
            }

            $newWholesaleVal = null;
            if ($hasWholesaleUpdate) {
                $cleaned = str_replace(',', '', $rawNewWholesale);
                if (!is_numeric($cleaned) || (float)$cleaned < 0) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'code' => $code,
                        'field' => 'New Wholesale Price',
                        'message' => "Row {$rowNumber}: New Wholesale Price must be a valid non-negative number.",
                    ];
                } else {
                    $newWholesaleVal = (float)$cleaned;
                }
            }

            $allCodes[] = $code;
            $updates[] = [
                'row' => $rowNumber,
                'code' => $code,
                'new_cost' => $newCostVal,
                'new_retail' => $newRetailVal,
                'new_wholesale' => $newWholesaleVal,
            ];
        }

        // Verify that all product codes exist in DB
        if (!empty($allCodes)) {
            $existingProducts = Product::whereIn('code', $allCodes)
                ->orWhereIn('sku', $allCodes)
                ->get()
                ->keyBy(function ($p) {
                    return strtolower(trim($p->code));
                });

            $existingVariants = ProductVariant::whereIn('sku', $allCodes)
                ->orWhereIn('barcode', $allCodes)
                ->get()
                ->keyBy(function ($v) {
                    return strtolower(trim($v->sku ?: $v->barcode));
                });

            foreach ($updates as $u) {
                $cLower = strtolower($u['code']);
                if (!isset($existingProducts[$cLower]) && !isset($existingVariants[$cLower])) {
                    $errors[] = [
                        'row' => $u['row'],
                        'code' => $u['code'],
                        'field' => 'Product Code',
                        'message' => "Row {$u['row']}: Product Code \"{$u['code']}\" does not exist in the database.",
                    ];
                }
            }
        }

        if (count($errors) > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Price change blocked: ' . count($errors) . ' error(s) found. Zero prices were updated.',
                'error_count' => count($errors),
                'total_rows' => count($updates),
                'errors' => $errors,
            ], 422);
        }

        $updatedCount = 0;

        DB::transaction(function () use ($updates, &$updatedCount) {
            foreach ($updates as $u) {
                $code = $u['code'];
                $product = Product::where('code', $code)->orWhere('sku', $code)->first();

                if ($product) {
                    if ($u['new_cost'] !== null) {
                        $product->cost_price = $u['new_cost'];
                        $product->purchase_price = $u['new_cost'];
                    }
                    if ($u['new_retail'] !== null) {
                        $product->retail_price = $u['new_retail'];
                        $product->selling_price = $u['new_retail'];
                        $product->retail_prices = [$u['new_retail']];
                    }
                    if ($u['new_wholesale'] !== null) {
                        $product->wholesale_price = $u['new_wholesale'];
                        $product->wholesale_prices = [$u['new_wholesale']];
                    }
                    $product->save();

                    // If has single variant with matching SKU, update variant too
                    if ($product->has_variants) {
                        ProductVariant::where('product_id', $product->id)->each(function ($v) use ($u) {
                            if ($u['new_cost'] !== null) $v->cost_price = $u['new_cost'];
                            if ($u['new_retail'] !== null) {
                                $v->selling_price = $u['new_retail'];
                                $v->retail_prices = [$u['new_retail']];
                            }
                            if ($u['new_wholesale'] !== null) {
                                $v->wholesale_price = $u['new_wholesale'];
                                $v->wholesale_prices = [$u['new_wholesale']];
                            }
                            $v->save();
                        });
                    }
                    $updatedCount++;
                } else {
                    // Try finding variant by SKU/barcode
                    $variant = ProductVariant::where('sku', $code)->orWhere('barcode', $code)->first();
                    if ($variant) {
                        if ($u['new_cost'] !== null) $variant->cost_price = $u['new_cost'];
                        if ($u['new_retail'] !== null) {
                            $variant->selling_price = $u['new_retail'];
                            $variant->retail_prices = [$u['new_retail']];
                        }
                        if ($u['new_wholesale'] !== null) {
                            $variant->wholesale_price = $u['new_wholesale'];
                            $variant->wholesale_prices = [$u['new_wholesale']];
                        }
                        $variant->save();
                        $updatedCount++;
                    }
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "Successfully updated prices and costs for {$updatedCount} products.",
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Export all products to Microsoft Excel (.xlsx) with styled headers and auto-fit columns.
     */
    public function exportProducts(Request $request)
    {
        $warehouseId = $request->input('warehouse_id');
        $query = Product::with(['variants.values', 'unitLinks']);

        if ($warehouseId && $warehouseId !== 'all' && $warehouseId !== 'All') {
            $query->whereHas('stockBalances', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', (int)$warehouseId);
            });
        }

        $products = $query->orderBy('id', 'desc')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products Catalog');

        $headers = [
            'Product Type',
            'Product Code',
            'Product Name',
            'Alternative Name (မြန်မာ)',
            'Category',
            'Brand',
            'Base Unit',
            'Purchase Unit',
            'Sale Unit',
            'Purchase Unit Links',
            'Sale Unit Links',
            'Product Cost',
            'Retail Price',
            'Wholesale Price',
            'Stock Alert',
            'Variation Name',
            'Variation Type',
            'Variation Code',
            'Stock Qty',
            'Status',
        ];

        // Header Row Setup
        $sheet->fromArray($headers, null, 'A1');
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '2563EB'], // Royal Blue
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
        $sheet->getStyle('A1:T1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->freezePane('A2'); // Freeze top row

        $currentRow = 2;

        foreach ($products as $p) {
            $baseUnit = $p->unit_name ?: 'Piece';
            $purchUnit = $p->purchase_unit ?: $baseUnit;
            $saleUnit = $p->sale_unit ?: $baseUnit;

            $purchLinks = $p->unitLinks->where('unit_type', 'purchase')->map(function ($l) {
                return $l->unit_name . ':' . (float)$l->conversion_rate;
            })->implode(', ');

            $saleLinks = $p->unitLinks->where('unit_type', 'sale')->map(function ($l) {
                return $l->unit_name . ':' . (float)$l->conversion_rate;
            })->implode(', ');

            $category = $p->category_name ?: 'General';
            $brand = $p->brand_name ?: 'Generic';
            $status = $p->status ?: ($p->is_active ? 'Active' : 'Inactive');

            if ($p->has_variants && $p->variants->isNotEmpty()) {
                foreach ($p->variants as $v) {
                    $vCost = (float)($v->cost_price ?: $p->cost_price);
                    $vRetail = (float)($v->selling_price ?: $p->retail_price);
                    $vWholesale = (float)($v->wholesale_price ?: $p->wholesale_price);

                    $varName = 'Variation';
                    $varType = $v->name ?: 'Standard';

                    if ($v->values && $v->values->isNotEmpty()) {
                        $attrNames = $v->values->pluck('attribute_name')->filter()->unique()->values();
                        if ($attrNames->isNotEmpty()) $varName = $attrNames->implode(' / ');
                        $attrVals = $v->values->pluck('attribute_value')->filter()->values();
                        if ($attrVals->isNotEmpty()) $varType = $attrVals->implode(' - ');
                    }

                    $vCode = $v->sku ?: ($v->barcode ?: $p->code);

                    $sheet->setCellValue("A{$currentRow}", 'Variation');
                    $sheet->setCellValueExplicit("B{$currentRow}", (string)$p->code, DataType::TYPE_STRING);
                    $sheet->setCellValue("C{$currentRow}", $p->name);
                    $sheet->setCellValue("D{$currentRow}", $p->alternative_name ?? '');
                    $sheet->setCellValue("E{$currentRow}", $category);
                    $sheet->setCellValue("F{$currentRow}", $brand);
                    $sheet->setCellValue("G{$currentRow}", $baseUnit);
                    $sheet->setCellValue("H{$currentRow}", $purchUnit);
                    $sheet->setCellValue("I{$currentRow}", $saleUnit);
                    $sheet->setCellValue("J{$currentRow}", $purchLinks);
                    $sheet->setCellValue("K{$currentRow}", $saleLinks);
                    $sheet->setCellValue("L{$currentRow}", $vCost);
                    $sheet->setCellValue("M{$currentRow}", $vRetail);
                    $sheet->setCellValue("N{$currentRow}", $vWholesale);
                    $sheet->setCellValue("O{$currentRow}", (float)($p->stock_alert ?: 10));
                    $sheet->setCellValue("P{$currentRow}", $varName);
                    $sheet->setCellValue("Q{$currentRow}", $varType);
                    $sheet->setCellValueExplicit("R{$currentRow}", (string)$vCode, DataType::TYPE_STRING);
                    $sheet->setCellValue("S{$currentRow}", (float)$v->stock_quantity);
                    $sheet->setCellValue("T{$currentRow}", $v->is_active ? $status : 'Inactive');

                    $currentRow++;
                }
            } else {
                $sheet->setCellValue("A{$currentRow}", 'Single');
                $sheet->setCellValueExplicit("B{$currentRow}", (string)$p->code, DataType::TYPE_STRING);
                $sheet->setCellValue("C{$currentRow}", $p->name);
                $sheet->setCellValue("D{$currentRow}", $p->alternative_name ?? '');
                $sheet->setCellValue("E{$currentRow}", $category);
                $sheet->setCellValue("F{$currentRow}", $brand);
                $sheet->setCellValue("G{$currentRow}", $baseUnit);
                $sheet->setCellValue("H{$currentRow}", $purchUnit);
                $sheet->setCellValue("I{$currentRow}", $saleUnit);
                $sheet->setCellValue("J{$currentRow}", $purchLinks);
                $sheet->setCellValue("K{$currentRow}", $saleLinks);
                $sheet->setCellValue("L{$currentRow}", (float)$p->cost_price);
                $sheet->setCellValue("M{$currentRow}", (float)$p->retail_price);
                $sheet->setCellValue("N{$currentRow}", (float)$p->wholesale_price);
                $sheet->setCellValue("O{$currentRow}", (float)($p->stock_alert ?: 10));
                $sheet->setCellValue("P{$currentRow}", '');
                $sheet->setCellValue("Q{$currentRow}", '');
                $sheet->setCellValueExplicit("R{$currentRow}", (string)$p->code, DataType::TYPE_STRING);
                $sheet->setCellValue("S{$currentRow}", (float)$p->stock_quantity);
                $sheet->setCellValue("T{$currentRow}", $status);

                $currentRow++;
            }
        }

        // Auto-fit all columns A to T
        foreach (range('A', 'T') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'products_export_' . date('Y-m-d_His') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Download Excel templates (.xlsx) for New Product Import or Bulk Price Changes.
     */
    public function downloadTemplate(Request $request)
    {
        $type = $request->input('type', 'import');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        if ($type === 'price_change') {
            $sheet->setTitle('Product Price Change');
            $headers = [
                'Product Code',
                'Product Name',
                'Current Cost',
                'New Cost Price',
                'Current Retail Price',
                'New Retail Price',
                'Current Wholesale Price',
                'New Wholesale Price',
            ];
            $sheet->fromArray($headers, null, 'A1');

            $sheet->getStyle('A1:H1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '10B981']], // Green
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension(1)->setRowHeight(26);
            $sheet->freezePane('A2');

            // Option to prefill with current products catalog
            $prefill = $request->boolean('prefill', true);
            $currentRow = 2;

            if ($prefill) {
                $products = Product::orderBy('name')->take(1000)->get();
                foreach ($products as $p) {
                    $sheet->setCellValueExplicit("A{$currentRow}", (string)$p->code, DataType::TYPE_STRING);
                    $sheet->setCellValue("B{$currentRow}", $p->name);
                    $sheet->setCellValue("C{$currentRow}", (float)$p->cost_price);
                    $sheet->setCellValue("D{$currentRow}", (float)$p->cost_price); // Pre-fill with current cost as starting point
                    $sheet->setCellValue("E{$currentRow}", (float)$p->retail_price);
                    $sheet->setCellValue("F{$currentRow}", (float)$p->retail_price); // Pre-fill with current retail price
                    $sheet->setCellValue("G{$currentRow}", (float)$p->wholesale_price);
                    $sheet->setCellValue("H{$currentRow}", (float)$p->wholesale_price);
                    $currentRow++;
                }
            } else {
                // Sample guide row
                $sheet->setCellValueExplicit('A2', '10001', DataType::TYPE_STRING);
                $sheet->setCellValue('B2', 'Classic Cheeseburger Deluxe');
                $sheet->setCellValue('C2', 4500.0);
                $sheet->setCellValue('D2', 4800.0);
                $sheet->setCellValue('E2', 8500.0);
                $sheet->setCellValue('F2', 9000.0);
                $sheet->setCellValue('G2', 7500.0);
                $sheet->setCellValue('H2', 8000.0);
            }

            foreach (range('A', 'H') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $fileName = 'product_price_change_template.xlsx';
        } else {
            // New Product Import Template
            $sheet->setTitle('Product Import Template');
            $headers = [
                'Product Type',
                'Product Code',
                'Product Name',
                'Alternative Name (မြန်မာ)',
                'Category',
                'Brand',
                'Base Unit',
                'Purchase Unit',
                'Sale Unit',
                'Purchase Unit Links',
                'Sale Unit Links',
                'Product Cost',
                'Retail Price',
                'Wholesale Price',
                'Stock Alert',
                'Variation Name',
                'Variation Type',
                'Variation Code',
                'Stock Qty',
                'Status',
            ];
            $sheet->fromArray($headers, null, 'A1');

            $sheet->getStyle('A1:T1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']], // Blue
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension(1)->setRowHeight(26);
            $sheet->freezePane('A2');

            // Sample Rows
            $sampleRows = [
                [
                    'Single', '10001', 'Classic Cheeseburger Deluxe', 'ချိစ်ဘာဂါ (Classic)',
                    'Main Dishes', 'Burger Club', 'Plate', 'Plate', 'Plate',
                    '', 'Box:10, Plate:1', 4500, 8500, 7500, 10, '', '', '10001', 50, 'Active',
                ],
                [
                    'Single', '10002', 'Spicy Seafood Fried Rice', 'ပင်လယ်စာ ထမင်းကြော်စပ်စပ်',
                    'Rice Dishes', 'Chef Special', 'Plate', 'Plate', 'Plate',
                    '', 'Plate:1', 3500, 7000, 6000, 15, '', '', '10002', 40, 'Active',
                ],
                [
                    'Variation', '10408', 'Premium Cotton Polo T-Shirt', 'ပိုလိုတီရှပ်',
                    'Apparel', 'Polo Classic', 'Piece', 'Box', 'Piece',
                    'Box:24, Piece:1', 'Box:24, Pack:6, Piece:1', 5000, 12000, 10000, 10,
                    'Color', 'Red', '1040801', 20, 'Active',
                ],
                [
                    'Variation', '10408', 'Premium Cotton Polo T-Shirt', 'ပိုလိုတီရှပ်',
                    'Apparel', 'Polo Classic', 'Piece', 'Box', 'Piece',
                    'Box:24, Piece:1', 'Box:24, Pack:6, Piece:1', 5000, 12000, 10000, 10,
                    'Color', 'Blue', '1040802', 25, 'Active',
                ],
            ];

            $r = 2;
            foreach ($sampleRows as $sRow) {
                $sheet->setCellValue("A{$r}", $sRow[0]);
                $sheet->setCellValueExplicit("B{$r}", (string)$sRow[1], DataType::TYPE_STRING);
                $sheet->setCellValue("C{$r}", $sRow[2]);
                $sheet->setCellValue("D{$r}", $sRow[3]);
                $sheet->setCellValue("E{$r}", $sRow[4]);
                $sheet->setCellValue("F{$r}", $sRow[5]);
                $sheet->setCellValue("G{$r}", $sRow[6]);
                $sheet->setCellValue("H{$r}", $sRow[7]);
                $sheet->setCellValue("I{$r}", $sRow[8]);
                $sheet->setCellValue("J{$r}", $sRow[9]);
                $sheet->setCellValue("K{$r}", $sRow[10]);
                $sheet->setCellValue("L{$r}", $sRow[11]);
                $sheet->setCellValue("M{$r}", $sRow[12]);
                $sheet->setCellValue("N{$r}", $sRow[13]);
                $sheet->setCellValue("O{$r}", $sRow[14]);
                $sheet->setCellValue("P{$r}", $sRow[15]);
                $sheet->setCellValue("Q{$r}", $sRow[16]);
                $sheet->setCellValueExplicit("R{$r}", (string)$sRow[17], DataType::TYPE_STRING);
                $sheet->setCellValue("S{$r}", $sRow[18]);
                $sheet->setCellValue("T{$r}", $sRow[19]);
                $r++;
            }

            foreach (range('A', 'T') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            $fileName = 'product_import_sample_template.xlsx';
        }

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Map flexible column header names for product import.
     */
    private function mapImportHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $raw) {
            $clean = strtolower(trim((string)$raw));
            if (in_array($clean, ['product code', 'code', 'sku', 'barcode', 'item code'])) {
                $map['code'] = $index;
            } elseif (in_array($clean, ['product name', 'name', 'item name'])) {
                $map['name'] = $index;
            } elseif (in_array($clean, ['product type', 'type'])) {
                $map['type'] = $index;
            } elseif (in_array($clean, ['alternative name (မြန်မာ)', 'alternative name', 'alt_name', 'burmese name'])) {
                $map['alt_name'] = $index;
            } elseif (in_array($clean, ['category', 'category name'])) {
                $map['category'] = $index;
            } elseif (in_array($clean, ['brand', 'brand name'])) {
                $map['brand'] = $index;
            } elseif (in_array($clean, ['base unit', 'unit', 'unit name'])) {
                $map['unit'] = $index;
            } elseif (in_array($clean, ['purchase unit', 'purch unit'])) {
                $map['purch_unit'] = $index;
            } elseif (in_array($clean, ['sale unit'])) {
                $map['sale_unit'] = $index;
            } elseif (in_array($clean, ['purchase unit links', 'purch unit links'])) {
                $map['purch_links'] = $index;
            } elseif (in_array($clean, ['sale unit links'])) {
                $map['sale_links'] = $index;
            } elseif (in_array($clean, ['product cost', 'cost price', 'cost', 'purchase price'])) {
                $map['cost'] = $index;
            } elseif (in_array($clean, ['retail price', 'selling price', 'price', 'product price'])) {
                $map['price'] = $index;
            } elseif (in_array($clean, ['wholesale price', 'wholesale'])) {
                $map['wholesale'] = $index;
            } elseif (in_array($clean, ['stock alert', 'alert quantity', 'low stock alert'])) {
                $map['alert'] = $index;
            } elseif (in_array($clean, ['variation name'])) {
                $map['var_name'] = $index;
            } elseif (in_array($clean, ['variation type'])) {
                $map['var_type'] = $index;
            } elseif (in_array($clean, ['variation code', 'variant code', 'variant sku'])) {
                $map['var_code'] = $index;
            } elseif (in_array($clean, ['stock qty', 'opening stock', 'stock quantity', 'stock'])) {
                $map['stock'] = $index;
            } elseif (in_array($clean, ['status'])) {
                $map['status'] = $index;
            }
        }
        return $map;
    }

    /**
     * Map flexible column header names for price change.
     */
    private function mapPriceChangeHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $raw) {
            $clean = strtolower(trim((string)$raw));
            if (in_array($clean, ['product code', 'code', 'sku', 'barcode', 'item code'])) {
                $map['code'] = $index;
            } elseif (in_array($clean, ['new cost price', 'new cost', 'product cost', 'cost price', 'cost'])) {
                $map['new_cost'] = $index;
            } elseif (in_array($clean, ['new retail price', 'new price', 'retail price', 'selling price', 'price'])) {
                $map['new_retail'] = $index;
            } elseif (in_array($clean, ['new wholesale price', 'new wholesale', 'wholesale price', 'wholesale'])) {
                $map['new_wholesale'] = $index;
            }
        }
        return $map;
    }

    /**
     * Parse unit link string (e.g. "Box:20, Pack:5, Piece:1").
     */
    private function parseUnitLinks(string $purchLinks, string $saleLinks): array
    {
        $links = [];
        $parseGroup = function (string $str, string $type) use (&$links) {
            if (empty($str)) return;
            $parts = explode(',', $str);
            foreach ($parts as $p) {
                $p = trim($p);
                if (empty($p)) continue;
                $sub = explode(':', $p);
                $uName = trim($sub[0] ?? '');
                $rate = (float)($sub[1] ?? 1.0);
                if (!empty($uName) && $rate > 0) {
                    $links[] = [
                        'unit_name' => $uName,
                        'unit_type' => $type,
                        'conversion_rate' => $rate,
                    ];
                }
            }
        };

        $parseGroup($purchLinks, 'purchase');
        $parseGroup($saleLinks, 'sale');
        return $links;
    }
}
