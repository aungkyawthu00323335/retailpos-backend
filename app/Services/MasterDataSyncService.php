<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Outlet;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\PurchaseUnit;
use App\Models\SaleUnit;
use App\Models\SubCategory;
use App\Models\Supplier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MasterDataSyncService
{
    /**
     * Ingest batch of offline-created master data in strict dependency order within an ACID transaction.
     */
    public function syncBatch(array $payload, ?string $branchCode = null): array
    {
        return DB::transaction(function () use ($payload) {
            $idMap = [
                'categories' => [],
                'sub_categories' => [],
                'brands' => [],
                'units' => [],
                'sale_units' => [],
                'purchase_units' => [],
                'suppliers' => [],
                'outlets' => [],
            ];
            $flatIdMap = [];

            // 1. Base Units (ProductUnit)
            $rawUnits = $payload['units'] ?? $payload['product_units'] ?? [];
            foreach ($rawUnits as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();
                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $unit = ProductUnit::where('uuid', $uuid)->first();
                if (! $unit) {
                    $unit = ProductUnit::where('name', $name)->first();
                    if ($unit) {
                        if (empty($unit->uuid)) {
                            $unit->uuid = $uuid;
                            $unit->save();
                        }
                    } else {
                        $unit = new ProductUnit;
                        $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                        $unit->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'description' => $item['description'] ?? ($item['code'] ?? null),
                            'status' => $status,
                        ]);
                        $unit->save();
                    }
                }

                $idMap['units'][$uuid] = $unit->id;
                $flatIdMap[$uuid] = $unit->id;
            }

            // 2. Categories (ProductCategory)
            $rawCategories = $payload['categories'] ?? $payload['product_categories'] ?? [];
            foreach ($rawCategories as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();
                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $cat = ProductCategory::where('uuid', $uuid)->first();
                if (! $cat) {
                    $cat = ProductCategory::where('name', $name)->first();
                    if ($cat) {
                        if (empty($cat->uuid)) {
                            $cat->uuid = $uuid;
                            $cat->save();
                        }
                    } else {
                        $cat = new ProductCategory;
                        $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                        $cat->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'description' => $item['description'] ?? null,
                            'status' => $status,
                        ]);
                        $cat->save();
                    }
                }

                $idMap['categories'][$uuid] = $cat->id;
                $flatIdMap[$uuid] = $cat->id;
            }

            // 3. Derived Sale Units
            $rawSaleUnits = $payload['sale_units'] ?? [];
            foreach ($rawSaleUnits as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();
                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $baseUnitId = $item['base_unit_id'] ?? null;
                if (! $baseUnitId && ! empty($item['base_unit_uuid'])) {
                    $baseUnitId = $flatIdMap[$item['base_unit_uuid']] ?? ProductUnit::where('uuid', $item['base_unit_uuid'])->value('id');
                }
                $baseUnitName = $item['base_unit_name'] ?? ($baseUnitId ? ProductUnit::where('id', $baseUnitId)->value('name') : 'Piece');

                $saleUnit = SaleUnit::where('uuid', $uuid)->first();
                if (! $saleUnit) {
                    $saleUnit = SaleUnit::where('name', $name)->first();
                    if ($saleUnit) {
                        if (empty($saleUnit->uuid)) {
                            $saleUnit->uuid = $uuid;
                            $saleUnit->save();
                        }
                    } else {
                        $saleUnit = new SaleUnit;
                        $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                        $saleUnit->forceFill([
                            'uuid' => $uuid,
                            'base_unit_id' => $baseUnitId,
                            'base_unit_name' => $baseUnitName,
                            'name' => $name,
                            'description' => $item['description'] ?? null,
                            'status' => $status,
                        ]);
                        $saleUnit->save();
                    }
                }

                $idMap['sale_units'][$uuid] = $saleUnit->id;
                $flatIdMap[$uuid] = $saleUnit->id;
            }

            // 4. Derived Purchase Units
            $rawPurchaseUnits = $payload['purchase_units'] ?? [];
            foreach ($rawPurchaseUnits as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();
                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $baseUnitId = $item['base_unit_id'] ?? null;
                if (! $baseUnitId && ! empty($item['base_unit_uuid'])) {
                    $baseUnitId = $flatIdMap[$item['base_unit_uuid']] ?? ProductUnit::where('uuid', $item['base_unit_uuid'])->value('id');
                }
                $baseUnitName = $item['base_unit_name'] ?? ($baseUnitId ? ProductUnit::where('id', $baseUnitId)->value('name') : 'Piece');

                $purchaseUnit = PurchaseUnit::where('uuid', $uuid)->first();
                if (! $purchaseUnit) {
                    $purchaseUnit = PurchaseUnit::where('name', $name)->first();
                    if ($purchaseUnit) {
                        if (empty($purchaseUnit->uuid)) {
                            $purchaseUnit->uuid = $uuid;
                            $purchaseUnit->save();
                        }
                    } else {
                        $purchaseUnit = new PurchaseUnit;
                        $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                        $purchaseUnit->forceFill([
                            'uuid' => $uuid,
                            'base_unit_id' => $baseUnitId,
                            'base_unit_name' => $baseUnitName,
                            'name' => $name,
                            'description' => $item['description'] ?? null,
                            'status' => $status,
                        ]);
                        $purchaseUnit->save();
                    }
                }

                $idMap['purchase_units'][$uuid] = $purchaseUnit->id;
                $flatIdMap[$uuid] = $purchaseUnit->id;
            }

            // 5. Subcategories (SubCategory)
            $rawSubCategories = $payload['sub_categories'] ?? $payload['subcategories'] ?? [];
            foreach ($rawSubCategories as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();
                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $catId = $item['parent_category_id'] ?? ($item['category_id'] ?? null);
                if (! $catId && ! empty($item['category_uuid'])) {
                    $catId = $flatIdMap[$item['category_uuid']] ?? ProductCategory::where('uuid', $item['category_uuid'])->value('id');
                }
                $catName = $item['parent_category_name'] ?? ($catId ? ProductCategory::where('id', $catId)->value('name') : 'General');

                $subCat = SubCategory::where('uuid', $uuid)->first();
                if (! $subCat) {
                    $query = SubCategory::where('name', $name);
                    if ($catId) {
                        $query->where('parent_category_id', $catId);
                    }
                    $subCat = $query->first();
                    if ($subCat) {
                        if (empty($subCat->uuid)) {
                            $subCat->uuid = $uuid;
                            $subCat->save();
                        }
                    } else {
                        $subCat = new SubCategory;
                        $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                        $subCat->forceFill([
                            'uuid' => $uuid,
                            'parent_category_id' => $catId,
                            'parent_category_name' => $catName,
                            'name' => $name,
                            'description' => $item['description'] ?? null,
                            'status' => $status,
                        ]);
                        $subCat->save();
                    }
                }

                $idMap['sub_categories'][$uuid] = $subCat->id;
                $flatIdMap[$uuid] = $subCat->id;
            }

            // 6. Brands (Brand)
            $rawBrands = $payload['brands'] ?? [];
            foreach ($rawBrands as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();
                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $brand = Brand::where('uuid', $uuid)->first();
                if (! $brand) {
                    $brand = Brand::where('name', $name)->first();
                    if ($brand) {
                        if (empty($brand->uuid)) {
                            $brand->uuid = $uuid;
                            $brand->save();
                        }
                    } else {
                        $brand = new Brand;
                        $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                        $brand->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'code' => $item['code'] ?? null,
                            'description' => $item['description'] ?? null,
                            'status' => $status,
                        ]);
                        $brand->save();
                    }
                }

                $idMap['brands'][$uuid] = $brand->id;
                $flatIdMap[$uuid] = $brand->id;
            }

            // 7. Suppliers (Supplier)
            $rawSuppliers = $payload['suppliers'] ?? [];
            foreach ($rawSuppliers as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();
                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $supplier = Supplier::where('uuid', $uuid)->first();
                if (! $supplier) {
                    $phone = ! empty($item['phone']) ? trim($item['phone']) : null;
                    if ($phone) {
                        $supplier = Supplier::where('phone', $phone)->first();
                    }
                    if (! $supplier) {
                        $supplier = Supplier::where('name', $name)->first();
                    }

                    if ($supplier) {
                        if (empty($supplier->uuid)) {
                            $supplier->uuid = $uuid;
                            $supplier->save();
                        }
                    } else {
                        $supplier = new Supplier;
                        $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                        $supplier->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'phone' => $item['phone'] ?? null,
                            'email' => $item['email'] ?? null,
                            'address' => $item['address'] ?? null,
                            'status' => $status,
                        ]);
                        $supplier->save();
                    }
                }

                $idMap['suppliers'][$uuid] = $supplier->id;
                $flatIdMap[$uuid] = $supplier->id;
            }

            // 8. Outlets / Warehouses
            $rawOutlets = $payload['warehouses'] ?? $payload['outlets'] ?? [];
            foreach ($rawOutlets as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();
                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $outlet = Outlet::where('uuid', $uuid)->first();
                if (! $outlet) {
                    $code = ! empty($item['code']) ? trim($item['code']) : null;
                    if ($code) {
                        $outlet = Outlet::where('code', $code)->first();
                    }
                    if (! $outlet) {
                        $outlet = Outlet::where('name', $name)->first();
                    }

                    if ($outlet) {
                        if (empty($outlet->uuid)) {
                            $outlet->uuid = $uuid;
                            $outlet->save();
                        }
                    } else {
                        $outlet = new Outlet;
                        $outlet->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'code' => $item['code'] ?? null,
                            'phone' => $item['phone'] ?? null,
                            'email' => $item['email'] ?? null,
                            'address' => $item['address'] ?? null,
                            'is_active' => isset($item['is_active']) ? (bool) $item['is_active'] : true,
                        ]);
                        $outlet->save();
                    }
                }

                $idMap['outlets'][$uuid] = $outlet->id;
                $flatIdMap[$uuid] = $outlet->id;
            }

            // Flush cache
            try {
                Cache::store('file')->flush();
            } catch (\Throwable $e) {
                // Ignore cache flush failures
            }

            // Merge flat id mappings into top-level id_map as well for dual-format compatibility
            foreach ($flatIdMap as $k => $v) {
                $idMap[$k] = $v;
            }

            return [
                'id_map' => $idMap,
                'flat_id_map' => $flatIdMap,
            ];
        });
    }
}
