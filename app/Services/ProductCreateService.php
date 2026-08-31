<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantValue;
use App\Models\ProductImage;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Outlet;
use App\Services\ProductUnitConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductCreateService
{
    /**
     * Create product with atomic transaction.
     */
    public function create(array $data, array $uploadedFiles = []): Product
    {
        $savedFilePaths = [];

        try {
            return DB::transaction(function () use ($data, $uploadedFiles, &$savedFilePaths) {
                // 1. Resolve & Auto-generate SKU & Barcode
                $sku = $data['sku'] ?? $data['code'] ?? null;
                if (empty($sku) || !empty($data['auto_generate_sku'])) {
                    $sku = $this->generateNextSku();
                }

                $barcode = $data['barcode'] ?? null;
                if (empty($barcode) && !empty($data['auto_generate_barcode'])) {
                    $barcode = $this->generateNextBarcode();
                }

                $costPrice = isset($data['cost_price']) ? (float)$data['cost_price'] : (isset($data['purchase_price']) ? (float)$data['purchase_price'] : 0.0);
                $sellingPrice = isset($data['selling_price']) ? (float)$data['selling_price'] : (isset($data['retail_price']) ? (float)$data['retail_price'] : 0.0);
                $rawRetailPrices = $data['retail_prices'] ?? $data['selling_prices'] ?? null;
                $retailPrices = is_array($rawRetailPrices)
                    ? array_values(array_filter(array_map('floatval', $rawRetailPrices), fn($p) => $p > 0))
                    : [];
                if ($sellingPrice <= 0 && !empty($retailPrices)) {
                    $sellingPrice = (float)$retailPrices[0];
                }
                if (empty($retailPrices) && $sellingPrice > 0) {
                    $retailPrices = [$sellingPrice];
                }

                $wholesalePrice = isset($data['wholesale_price']) ? (float)$data['wholesale_price'] : 0.0;
                $wholesalePrices = isset($data['wholesale_prices']) && is_array($data['wholesale_prices'])
                    ? array_values(array_filter(array_map('floatval', $data['wholesale_prices']), fn($p) => $p > 0))
                    : [];
                if ($wholesalePrice <= 0 && !empty($wholesalePrices)) {
                    $wholesalePrice = (float)$wholesalePrices[0];
                }
                if (empty($wholesalePrices) && $wholesalePrice > 0) {
                    $wholesalePrices = [$wholesalePrice];
                }

                $stockAlert = isset($data['stock_alert']) ? (float)$data['stock_alert'] : (isset($data['alert_quantity']) ? (float)$data['alert_quantity'] : 10.0);
                $hasVariants = !empty($data['has_variants']);
                $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : (isset($data['status']) ? $data['status'] === 'Active' : true);

                // 2. Create Base Product
                $product = Product::create([
                    'uuid' => (string) Str::uuid(),
                    'code' => $sku,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'barcode_symbology' => $data['barcode_symbology'] ?? 'CODE128',
                    'name' => $data['name'],
                    'alternative_name' => $data['alternative_name'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'category_name' => $data['category_name'] ?? 'General',
                    'sub_category_id' => $data['sub_category_id'] ?? $data['subcategory_id'] ?? null,
                    'sub_category_name' => $data['sub_category_name'] ?? $data['subcategory_name'] ?? null,
                    'brand_id' => $data['brand_id'] ?? null,
                    'brand_name' => $data['brand_name'] ?? 'Generic',
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'supplier_name' => $data['supplier_name'] ?? null,
                    'base_unit_id' => $data['base_unit_id'] ?? null,
                    'unit_name' => $data['unit_name'] ?? 'Piece',
                    'sale_unit_id' => $data['sale_unit_id'] ?? null,
                    'sale_unit' => $data['sale_unit'] ?? ($data['unit_name'] ?? 'Piece'),
                    'purchase_unit_id' => $data['purchase_unit_id'] ?? null,
                    'purchase_unit' => $data['purchase_unit'] ?? ($data['unit_name'] ?? 'Piece'),
                    'cost_price' => $costPrice,
                    'purchase_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'retail_price' => $sellingPrice,
                    'retail_prices' => $retailPrices,
                    'wholesale_price' => $wholesalePrice,
                    'wholesale_prices' => $wholesalePrices,
                    'dine_in_price' => $sellingPrice,
                    'take_away_price' => $sellingPrice,
                    'delivery_price' => $sellingPrice,
                    'stock_quantity' => 0.0,
                    'alert_quantity' => $stockAlert,
                    'stock_alert' => $stockAlert,
                    'low_qty' => $stockAlert,
                    'has_variants' => $hasVariants,
                    'image_url' => $data['image_url'] ?? '',
                    'description' => $data['note'] ?? $data['description'] ?? '',
                    'note' => $data['note'] ?? $data['description'] ?? '',
                    'status' => $isActive ? 'Active' : 'Inactive',
                    'is_active' => $isActive,
                    'added_by' => $data['added_by'] ?? auth()->user()?->name ?? 'System',
                    'created_by' => auth()->id(),
                ]);

                // 3. Process Product Variants if Variable Product
                $createdVariants = [];
                if ($hasVariants && !empty($data['variants']) && is_array($data['variants'])) {
                    $sortOrder = 0;
                    foreach ($data['variants'] as $vData) {
                        $vSku = $vData['sku'] ?? ($sku . '-' . Str::slug($vData['name']));
                        if (empty($vSku)) {
                            $vSku = $this->generateNextSku();
                        }
                        $vBarcode = $vData['barcode'] ?? null;
                        if (empty($vBarcode) && !empty($vData['auto_generate_barcode'])) {
                            $vBarcode = $this->generateNextBarcode();
                        }
                        $vCost = isset($vData['cost_price']) ? (float)$vData['cost_price'] : $costPrice;
                        $vPrice = isset($vData['selling_price']) ? (float)$vData['selling_price'] : (isset($vData['retail_price']) ? (float)$vData['retail_price'] : $sellingPrice);
                        $vRawRetailPrices = $vData['retail_prices'] ?? $vData['selling_prices'] ?? null;
                        $vRetailPrices = is_array($vRawRetailPrices)
                            ? array_values(array_filter(array_map('floatval', $vRawRetailPrices), fn($p) => $p > 0))
                            : [];
                        if ($vPrice <= 0 && !empty($vRetailPrices)) {
                            $vPrice = (float)$vRetailPrices[0];
                        }
                        if (empty($vRetailPrices) && $vPrice > 0) {
                            $vRetailPrices = [$vPrice];
                        }

                        $vWholesale = isset($vData['wholesale_price']) ? (float)$vData['wholesale_price'] : $wholesalePrice;
                        $vWholesalePrices = isset($vData['wholesale_prices']) && is_array($vData['wholesale_prices'])
                            ? array_values(array_filter(array_map('floatval', $vData['wholesale_prices']), fn($p) => $p > 0))
                            : [];
                        if ($vWholesale <= 0 && !empty($vWholesalePrices)) {
                            $vWholesale = (float)$vWholesalePrices[0];
                        }
                        if (empty($vWholesalePrices) && $vWholesale > 0) {
                            $vWholesalePrices = [$vWholesale];
                        }
                        $vActive = isset($vData['is_active']) ? (bool)$vData['is_active'] : true;

                        $variant = ProductVariant::create([
                            'product_id' => $product->id,
                            'name' => $vData['name'],
                            'sku' => $vSku,
                            'barcode' => $vBarcode,
                            'cost_price' => $vCost,
                            'selling_price' => $vPrice,
                            'retail_prices' => $vRetailPrices,
                            'wholesale_price' => $vWholesale,
                            'wholesale_prices' => $vWholesalePrices,
                            'is_active' => $vActive,
                            'sort_order' => $sortOrder++,
                        ]);

                        // Link attribute values
                        if (!empty($vData['attribute_values']) && is_array($vData['attribute_values'])) {
                            foreach ($vData['attribute_values'] as $attrVal) {
                                ProductVariantValue::create([
                                    'product_variant_id' => $variant->id,
                                    'attribute_name' => is_array($attrVal) ? ($attrVal['attribute_name'] ?? 'Variation') : 'Variation',
                                    'attribute_value' => is_array($attrVal) ? ($attrVal['attribute_value'] ?? '') : (string)$attrVal,
                                ]);
                            }
                        }

                        $createdVariants[$vSku] = $variant;
                    }
                }

                $unitLinks = $data['unit_links'] ?? [];
                if (!is_array($unitLinks) || empty($unitLinks)) {
                    $unitLinks = [
                        [
                            'unit_type' => 'purchase',
                            'unit_id' => $data['purchase_unit_id'] ?? null,
                            'unit_name' => $data['purchase_unit'] ?? ($data['unit_name'] ?? 'Piece'),
                            'conversion_rate' => 1,
                            'is_default' => true,
                        ],
                        [
                            'unit_type' => 'sale',
                            'unit_id' => $data['sale_unit_id'] ?? null,
                            'unit_name' => $data['sale_unit'] ?? ($data['unit_name'] ?? 'Piece'),
                            'conversion_rate' => 1,
                            'is_default' => true,
                        ],
                    ];
                }
                app(ProductUnitConversionService::class)->syncProductLinks($product, $unitLinks, $createdVariants);

                // 4. Process Images
                $primaryIdx = (int) ($data['primary_image_index'] ?? 0);
                $imgSort = 0;

                // Handle file uploads if any
                if (!empty($uploadedFiles)) {
                    foreach ($uploadedFiles as $idx => $file) {
                        if ($file && $file->isValid()) {
                            $path = $file->store('products', 'public');
                            $savedFilePaths[] = $path;

                            ProductImage::create([
                                'product_id' => $product->id,
                                'disk' => 'public',
                                'path' => $path,
                                'original_name' => $file->getClientOriginalName(),
                                'is_primary' => ($idx === $primaryIdx),
                                'sort_order' => $imgSort++,
                            ]);
                        }
                    }
                } elseif (!empty($data['images']) && is_array($data['images'])) {
                    foreach (array_values($data['images']) as $idx => $image) {
                        $url = is_array($image) ? ($image['url'] ?? '') : (string)$image;
                        $url = trim((string)$url);
                        if ($url === '') continue;
                        ProductImage::create([
                            'product_id' => $product->id,
                            'disk' => 'public',
                            'path' => $url,
                            'original_name' => 'remote_image',
                            'is_primary' => $idx === (int)($data['primary_image_index'] ?? 0),
                            'sort_order' => $idx,
                        ]);
                    }
                } elseif (!empty($data['image_url'])) {
                    ProductImage::create([
                        'product_id' => $product->id,
                        'disk' => 'public',
                        'path' => $data['image_url'],
                        'original_name' => 'remote_image',
                        'is_primary' => true,
                        'sort_order' => 0,
                    ]);
                }

                // 5. Process Opening Stocks & Stock Movements
                $totalStock = 0.0;
                $defaultWarehouse = Outlet::orderBy('id')->first();
                $defaultWarehouseId = $defaultWarehouse?->id;

                if (!empty($data['opening_stocks']) && is_array($data['opening_stocks'])) {
                    foreach ($data['opening_stocks'] as $stockEntry) {
                        $qty = (float) ($stockEntry['quantity'] ?? 0);
                        if ($qty <= 0) continue;

                        $wId = !empty($stockEntry['warehouse_id'])
                            ? (int)$stockEntry['warehouse_id']
                            : $defaultWarehouseId;
                        if (!$wId) {
                            throw new \RuntimeException('A warehouse is required for opening stock.');
                        }
                        $variantSku = $stockEntry['variant_sku'] ?? null;
                        $vId = null;

                        if ($variantSku && isset($createdVariants[$variantSku])) {
                            $vId = $createdVariants[$variantSku]->id;
                        }

                        $unitService = app(ProductUnitConversionService::class);
                        $resolvedUnit = $unitService->resolve(
                            $product,
                            $stockEntry['unit_type'] ?? 'base',
                            isset($stockEntry['unit_id']) ? (int)$stockEntry['unit_id'] : null,
                            $stockEntry['unit_name'] ?? null,
                            $vId
                        );
                        $baseQty = $unitService->baseQuantity($qty, (float)$resolvedUnit['conversion_rate']);

                        // Create/Update StockBalance in the canonical base unit.
                        $balance = StockBalance::where('warehouse_id', $wId)
                            ->where('product_id', $product->id)
                            ->where('product_variant_id', $vId)
                            ->first();

                        if ($balance) {
                            $balance->quantity = (float)$balance->quantity + $baseQty;
                            $balance->save();
                        } else {
                            StockBalance::create([
                                'warehouse_id' => $wId,
                                'product_id' => $product->id,
                                'product_variant_id' => $vId,
                                'quantity' => $baseQty,
                            ]);
                        }

                        // Create StockMovement audit record
                        StockMovement::create([
                            'warehouse_id' => $wId,
                            'product_id' => $product->id,
                            'product_variant_id' => $vId,
                            'movement_type' => 'opening_stock',
                            'reference_type' => 'product_create',
                            'reference_id' => (string) $product->id,
                            'quantity_in' => $baseQty,
                            'quantity_out' => 0.0,
                            'balance_after' => $qty,
                            'created_by' => auth()->id(),
                            'note' => 'Initial Opening Stock from Product Create',
                            'created_at' => now(),
                        ]);

                        $totalStock += $baseQty;
                    }
                } elseif (!empty($data['stock_quantity'])) {
                    $qty = (float) $data['stock_quantity'];
                    if ($qty > 0) {
                        if (!$defaultWarehouseId) {
                            throw new \RuntimeException('A warehouse is required for opening stock.');
                        }
                        StockBalance::create([
                            'warehouse_id' => $defaultWarehouseId,
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'quantity' => $qty,
                        ]);

                        StockMovement::create([
                            'warehouse_id' => $defaultWarehouseId,
                            'product_id' => $product->id,
                            'product_variant_id' => null,
                            'movement_type' => 'opening_stock',
                            'reference_type' => 'product_create',
                            'reference_id' => (string) $product->id,
                            'quantity_in' => $qty,
                            'quantity_out' => 0.0,
                            'balance_after' => $qty,
                            'created_by' => auth()->id(),
                            'note' => 'Initial Opening Stock from Product Create',
                            'created_at' => now(),
                        ]);

                        $totalStock = $qty;
                    }
                }

                $product->stock_quantity = $totalStock;
                $product->save();

                return $product->load(['variants.values', 'images', 'unitLinks']);
            });
        } catch (\Throwable $e) {
            // Clean up any uploaded files on transaction rollback
            foreach ($savedFilePaths as $path) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (\Throwable $ignored) {}
            }
            throw $e;
        }
    }

    /**
     * Update a product and its complete product graph atomically.
     *
     * The Flutter edit form sends the current variants, prices, values and
     * unit links together. Keeping this operation in the same service as
     * create prevents partial updates when one child record fails.
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            $previousImageUrl = (string)$product->image_url;
            $fillable = [
                'code', 'sku', 'barcode', 'barcode_symbology', 'name',
                'alternative_name', 'category_id', 'category_name',
                'sub_category_id', 'sub_category_name', 'brand_id', 'brand_name',
                'supplier_id', 'supplier_name', 'base_unit_id', 'unit_name',
                'purchase_unit_id', 'purchase_unit', 'sale_unit_id', 'sale_unit',
                'cost_price', 'selling_price', 'purchase_price', 'retail_price',
                'retail_prices', 'wholesale_price', 'wholesale_prices', 'alert_quantity',
                'stock_alert', 'low_qty', 'has_variants', 'description', 'note',
                'image_url', 'status', 'is_active', 'added_by',
            ];
            $updates = array_intersect_key($data, array_flip($fillable));

            if (array_key_exists('cost_price', $data)) {
                $updates['cost_price'] = (float)$data['cost_price'];
                $updates['purchase_price'] = (float)($data['purchase_price'] ?? $data['cost_price']);
            } elseif (array_key_exists('purchase_price', $data)) {
                $updates['purchase_price'] = (float)$data['purchase_price'];
                $updates['cost_price'] = (float)$data['purchase_price'];
            }
            if (array_key_exists('selling_price', $data)) {
                $updates['selling_price'] = (float)$data['selling_price'];
                $updates['retail_price'] = (float)($data['retail_price'] ?? $data['selling_price']);
            } elseif (array_key_exists('retail_price', $data)) {
                $updates['retail_price'] = (float)$data['retail_price'];
                $updates['selling_price'] = (float)$data['retail_price'];
            }
            if (array_key_exists('retail_prices', $data) && is_array($data['retail_prices'])) {
                $rPrices = array_values(array_filter(
                    array_map('floatval', $data['retail_prices']),
                    fn ($price) => $price > 0,
                ));
                $updates['retail_prices'] = $rPrices;
                if (!empty($rPrices)) {
                    $updates['selling_price'] = (float)$rPrices[0];
                    $updates['retail_price'] = (float)$rPrices[0];
                }
            } elseif (array_key_exists('selling_prices', $data) && is_array($data['selling_prices'])) {
                $rPrices = array_values(array_filter(
                    array_map('floatval', $data['selling_prices']),
                    fn ($price) => $price > 0,
                ));
                $updates['retail_prices'] = $rPrices;
                if (!empty($rPrices)) {
                    $updates['selling_price'] = (float)$rPrices[0];
                    $updates['retail_price'] = (float)$rPrices[0];
                }
            }
            if (array_key_exists('wholesale_prices', $data) && is_array($data['wholesale_prices'])) {
                $prices = array_values(array_filter(
                    array_map('floatval', $data['wholesale_prices']),
                    fn ($price) => $price > 0,
                ));
                $updates['wholesale_prices'] = $prices;
                $updates['wholesale_price'] = (float)($prices[0] ?? $data['wholesale_price'] ?? 0);
            }
            if (array_key_exists('stock_alert', $data)) {
                $alert = (float)$data['stock_alert'];
                $updates['stock_alert'] = $alert;
                $updates['alert_quantity'] = (float)($data['alert_quantity'] ?? $alert);
                $updates['low_qty'] = $alert;
            } elseif (array_key_exists('alert_quantity', $data)) {
                $alert = (float)$data['alert_quantity'];
                $updates['stock_alert'] = $alert;
                $updates['alert_quantity'] = $alert;
                $updates['low_qty'] = $alert;
            }

            // Stock is maintained by StockBalance/StockMovement. Product edit
            // must never replace it with a stale form snapshot.
            unset($updates['stock_quantity']);
            $updates['updated_by'] = auth()->id() ?? $product->updated_by;
            $product->fill($updates);
            $product->save();

            $createdVariants = [];
            if (array_key_exists('variants', $data)) {
                $rawVariants = is_array($data['variants']) ? $data['variants'] : [];
                $keptVariantIds = [];
                $hasVariants = (bool)($data['has_variants'] ?? $product->has_variants);

                foreach ($rawVariants as $sortOrder => $variantData) {
                    if (!is_array($variantData) || trim((string)($variantData['name'] ?? '')) === '') {
                        continue;
                    }

                    $variant = null;
                    $variantId = (int)($variantData['id'] ?? 0);
                    if ($variantId > 0) {
                        $variant = $product->variants()->whereKey($variantId)->first();
                    }
                    $variantSku = trim((string)($variantData['sku'] ?? ''));
                    if (!$variant && $variantSku !== '') {
                        $variant = $product->variants()->where('sku', $variantSku)->first();
                    }

                    $variant ??= new ProductVariant(['product_id' => $product->id]);
                    $variantCost = (float)($variantData['cost_price'] ?? $variantData['purchase_price'] ?? 0);
                    $variantSelling = (float)($variantData['selling_price'] ?? $variantData['retail_price'] ?? 0);
                    $variantRetailPrices = is_array($variantData['retail_prices'] ?? $variantData['selling_prices'] ?? null)
                        ? array_values(array_filter(
                            array_map('floatval', $variantData['retail_prices'] ?? $variantData['selling_prices']),
                            fn ($price) => $price > 0,
                        ))
                        : [];
                    if (!$variantSelling && $variantRetailPrices) {
                        $variantSelling = (float)$variantRetailPrices[0];
                    }
                    if (empty($variantRetailPrices) && $variantSelling > 0) {
                        $variantRetailPrices = [$variantSelling];
                    }

                    $variantWholesalePrices = is_array($variantData['wholesale_prices'] ?? null)
                        ? array_values(array_filter(
                            array_map('floatval', $variantData['wholesale_prices']),
                            fn ($price) => $price > 0,
                        ))
                        : [];
                    $variantWholesale = (float)($variantData['wholesale_price'] ?? ($variantWholesalePrices[0] ?? 0));
                    if (!$variantWholesale && $variantWholesalePrices) {
                        $variantWholesale = (float)$variantWholesalePrices[0];
                    }

                    $variant->fill([
                        'product_id' => $product->id,
                        'name' => trim((string)$variantData['name']),
                        'sku' => $variantSku !== '' ? $variantSku : $product->sku . '-' . Str::slug((string)$variantData['name']),
                        'barcode' => $variantData['barcode'] ?? null,
                        'cost_price' => $variantCost,
                        'selling_price' => $variantSelling,
                        'retail_prices' => $variantRetailPrices,
                        'wholesale_price' => $variantWholesale,
                        'wholesale_prices' => $variantWholesalePrices,
                        'is_active' => array_key_exists('is_active', $variantData) ? (bool)$variantData['is_active'] : true,
                        'sort_order' => (int)$sortOrder,
                    ]);
                    $variant->save();
                    $keptVariantIds[] = $variant->id;
                    $createdVariants[$variant->sku] = $variant;

                    $rawValues = $variantData['attribute_values'] ?? $variantData['values'] ?? [];
                    if (is_array($rawValues)) {
                        $variant->values()->delete();
                        foreach ($rawValues as $value) {
                            if (!is_array($value)) continue;
                            ProductVariantValue::create([
                                'product_variant_id' => $variant->id,
                                'attribute_name' => $value['attribute_name'] ?? 'Variation',
                                'attribute_value' => $value['attribute_value'] ?? '',
                            ]);
                        }
                    }
                }

                $staleVariants = $product->variants();
                if ($hasVariants) {
                    if ($keptVariantIds) {
                        $staleVariants->whereNotIn('id', $keptVariantIds)->delete();
                    } else {
                        $staleVariants->delete();
                    }
                } else {
                    $staleVariants->delete();
                }
            }

            if (array_key_exists('unit_links', $data)) {
                $links = is_array($data['unit_links']) ? $data['unit_links'] : [];
                app(ProductUnitConversionService::class)->syncProductLinks(
                    $product,
                    $links,
                    $createdVariants,
                );
            }

            // The edit form uses remote image URLs. Keep existing images when
            // no new URL is supplied, and update the primary image in place.
            $imageUrl = trim((string)($data['image_url'] ?? ''));
            if ($imageUrl !== '' && $imageUrl !== $previousImageUrl) {
                $product->update(['image_url' => $imageUrl]);
                $primary = $product->images()->where('is_primary', true)->first();
                if ($primary) {
                    $primary->update(['path' => $imageUrl, 'original_name' => 'remote_image']);
                } else {
                    $product->images()->create([
                        'disk' => 'public',
                        'path' => $imageUrl,
                        'original_name' => 'remote_image',
                        'is_primary' => true,
                        'sort_order' => 0,
                    ]);
                }
            }

            return $product->fresh(['variants.values', 'images', 'unitLinks']);
        });
    }

    public function generateNextSku(): string
    {
        $last = Product::withTrashed()->orderBy('id', 'desc')->first();
        $nextId = ($last ? $last->id : 0) + 1;
        $candidate = 10000 + $nextId;
        $sku = (string)$candidate;

        while (Product::withTrashed()->where('code', $sku)->orWhere('sku', $sku)->exists() ||
               ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
            $candidate++;
            $sku = (string)$candidate;
        }

        return $sku;
    }

    public function generateNextBarcode(): string
    {
        $barcode = '893' . str_pad((string) mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

        while (Product::where('code', $barcode)->orWhere('barcode', $barcode)->exists() ||
               ProductVariant::where('barcode', $barcode)->exists()) {
            $barcode = '893' . str_pad((string) mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
        }

        return $barcode;
    }
}
