<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\PurchaseUnit;
use App\Models\SaleUnit;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Variation;
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
                'variations' => [],
                'outlets' => [],
            ];
            $flatIdMap = [];

            // 1. Base Units (ProductUnit)
            $rawUnits = $payload['units'] ?? $payload['product_units'] ?? [];
            foreach ($rawUnits as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $unit = ProductUnit::where('uuid', $uuid)->first();
                    if (! $unit && ! empty($item['id']) && $item['id'] > 0) {
                        $unit = ProductUnit::find($item['id']);
                    }
                    if ($unit) {
                        $uId = $unit->id;
                        $hasDependent = Product::where('base_unit_id', $uId)->orWhere('unit_name', $unit->name)->exists()
                            || SaleUnit::where('base_unit_id', $uId)->exists()
                            || PurchaseUnit::where('base_unit_id', $uId)->exists();
                        if (! $hasDependent) {
                            $unit->delete();
                        }
                        $idMap['units'][$uuid] = $uId;
                        $flatIdMap[$uuid] = $uId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                $description = $item['description'] ?? ($item['code'] ?? null);

                $unit = ProductUnit::where('uuid', $uuid)->first();
                if (! $unit && ! empty($item['id']) && $item['id'] > 0) {
                    $unit = ProductUnit::find($item['id']);
                }

                if (! $unit) {
                    $unit = ProductUnit::where('name', $name)->first();
                    if ($unit) {
                        if (empty($unit->uuid)) {
                            $unit->uuid = $uuid;
                        }
                        if ($description !== null) {
                            $unit->description = $description;
                        }
                        $unit->status = $status;
                        $unit->save();
                    } else {
                        $unit = new ProductUnit;
                        $unit->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'description' => $description,
                            'status' => $status,
                        ]);
                        $unit->save();
                    }
                } else {
                    $unit->name = $name;
                    if (empty($unit->uuid)) {
                        $unit->uuid = $uuid;
                    }
                    if ($description !== null) {
                        $unit->description = $description;
                    }
                    $unit->status = $status;
                    $unit->save();
                }

                $idMap['units'][$uuid] = $unit->id;
                $flatIdMap[$uuid] = $unit->id;
            }

            // 2. Categories (ProductCategory)
            $rawCategories = $payload['categories'] ?? $payload['product_categories'] ?? [];
            foreach ($rawCategories as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $cat = ProductCategory::where('uuid', $uuid)->first();
                    if (! $cat && ! empty($item['id']) && $item['id'] > 0) {
                        $cat = ProductCategory::find($item['id']);
                    }
                    if ($cat) {
                        $catId = $cat->id;
                        $hasDependent = Product::where('category_id', $catId)->orWhere('category_name', $cat->name)->exists()
                            || SubCategory::where('parent_category_id', $catId)->exists();
                        if (! $hasDependent) {
                            $cat->delete();
                        }
                        $idMap['categories'][$uuid] = $catId;
                        $flatIdMap[$uuid] = $catId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                $description = $item['description'] ?? null;

                $cat = ProductCategory::where('uuid', $uuid)->first();
                if (! $cat && ! empty($item['id']) && $item['id'] > 0) {
                    $cat = ProductCategory::find($item['id']);
                }

                if (! $cat) {
                    $cat = ProductCategory::where('name', $name)->first();
                    if ($cat) {
                        if (empty($cat->uuid)) {
                            $cat->uuid = $uuid;
                        }
                        if ($description !== null) {
                            $cat->description = $description;
                        }
                        $cat->status = $status;
                        $cat->save();
                    } else {
                        $cat = new ProductCategory;
                        $cat->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'description' => $description,
                            'status' => $status,
                        ]);
                        $cat->save();
                    }
                } else {
                    $cat->name = $name;
                    if (empty($cat->uuid)) {
                        $cat->uuid = $uuid;
                    }
                    if ($description !== null) {
                        $cat->description = $description;
                    }
                    $cat->status = $status;
                    $cat->save();
                }

                $idMap['categories'][$uuid] = $cat->id;
                $flatIdMap[$uuid] = $cat->id;
            }

            // 3. Derived Sale Units
            $rawSaleUnits = $payload['sale_units'] ?? [];
            foreach ($rawSaleUnits as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $saleUnit = SaleUnit::where('uuid', $uuid)->first();
                    if (! $saleUnit && ! empty($item['id']) && $item['id'] > 0) {
                        $saleUnit = SaleUnit::find($item['id']);
                    }
                    if ($saleUnit) {
                        $suId = $saleUnit->id;
                        $hasDependent = Product::where('sale_unit_id', $suId)->orWhere('sale_unit', $saleUnit->name)->exists();
                        if (! $hasDependent) {
                            $saleUnit->delete();
                        }
                        $idMap['sale_units'][$uuid] = $suId;
                        $flatIdMap[$uuid] = $suId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $baseUnitId = $item['base_unit_id'] ?? null;
                if (! $baseUnitId && ! empty($item['base_unit_uuid'])) {
                    $baseUnitId = $flatIdMap[$item['base_unit_uuid']] ?? ProductUnit::where('uuid', $item['base_unit_uuid'])->value('id');
                }
                $baseUnitName = $item['base_unit_name'] ?? ($baseUnitId ? ProductUnit::where('id', $baseUnitId)->value('name') : 'Piece');
                $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                $description = $item['description'] ?? null;

                $saleUnit = SaleUnit::where('uuid', $uuid)->first();
                if (! $saleUnit && ! empty($item['id']) && $item['id'] > 0) {
                    $saleUnit = SaleUnit::find($item['id']);
                }

                if (! $saleUnit) {
                    $saleUnit = SaleUnit::where('name', $name)->first();
                    if ($saleUnit) {
                        if (empty($saleUnit->uuid)) {
                            $saleUnit->uuid = $uuid;
                        }
                        if ($baseUnitId) {
                            $saleUnit->base_unit_id = $baseUnitId;
                            $saleUnit->base_unit_name = $baseUnitName;
                        }
                        if ($description !== null) {
                            $saleUnit->description = $description;
                        }
                        $saleUnit->status = $status;
                        $saleUnit->save();
                    } else {
                        $saleUnit = new SaleUnit;
                        $saleUnit->forceFill([
                            'uuid' => $uuid,
                            'base_unit_id' => $baseUnitId,
                            'base_unit_name' => $baseUnitName,
                            'name' => $name,
                            'description' => $description,
                            'status' => $status,
                        ]);
                        $saleUnit->save();
                    }
                } else {
                    $saleUnit->name = $name;
                    if (empty($saleUnit->uuid)) {
                        $saleUnit->uuid = $uuid;
                    }
                    if ($baseUnitId) {
                        $saleUnit->base_unit_id = $baseUnitId;
                        $saleUnit->base_unit_name = $baseUnitName;
                    }
                    if ($description !== null) {
                        $saleUnit->description = $description;
                    }
                    $saleUnit->status = $status;
                    $saleUnit->save();
                }

                $idMap['sale_units'][$uuid] = $saleUnit->id;
                $flatIdMap[$uuid] = $saleUnit->id;
            }

            // 4. Derived Purchase Units
            $rawPurchaseUnits = $payload['purchase_units'] ?? [];
            foreach ($rawPurchaseUnits as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $purchaseUnit = PurchaseUnit::where('uuid', $uuid)->first();
                    if (! $purchaseUnit && ! empty($item['id']) && $item['id'] > 0) {
                        $purchaseUnit = PurchaseUnit::find($item['id']);
                    }
                    if ($purchaseUnit) {
                        $puId = $purchaseUnit->id;
                        $hasDependent = Product::where('purchase_unit_id', $puId)->orWhere('purchase_unit', $purchaseUnit->name)->exists();
                        if (! $hasDependent) {
                            $purchaseUnit->delete();
                        }
                        $idMap['purchase_units'][$uuid] = $puId;
                        $flatIdMap[$uuid] = $puId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $baseUnitId = $item['base_unit_id'] ?? null;
                if (! $baseUnitId && ! empty($item['base_unit_uuid'])) {
                    $baseUnitId = $flatIdMap[$item['base_unit_uuid']] ?? ProductUnit::where('uuid', $item['base_unit_uuid'])->value('id');
                }
                $baseUnitName = $item['base_unit_name'] ?? ($baseUnitId ? ProductUnit::where('id', $baseUnitId)->value('name') : 'Piece');
                $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                $description = $item['description'] ?? null;

                $purchaseUnit = PurchaseUnit::where('uuid', $uuid)->first();
                if (! $purchaseUnit && ! empty($item['id']) && $item['id'] > 0) {
                    $purchaseUnit = PurchaseUnit::find($item['id']);
                }

                if (! $purchaseUnit) {
                    $purchaseUnit = PurchaseUnit::where('name', $name)->first();
                    if ($purchaseUnit) {
                        if (empty($purchaseUnit->uuid)) {
                            $purchaseUnit->uuid = $uuid;
                        }
                        if ($baseUnitId) {
                            $purchaseUnit->base_unit_id = $baseUnitId;
                            $purchaseUnit->base_unit_name = $baseUnitName;
                        }
                        if ($description !== null) {
                            $purchaseUnit->description = $description;
                        }
                        $purchaseUnit->status = $status;
                        $purchaseUnit->save();
                    } else {
                        $purchaseUnit = new PurchaseUnit;
                        $purchaseUnit->forceFill([
                            'uuid' => $uuid,
                            'base_unit_id' => $baseUnitId,
                            'base_unit_name' => $baseUnitName,
                            'name' => $name,
                            'description' => $description,
                            'status' => $status,
                        ]);
                        $purchaseUnit->save();
                    }
                } else {
                    $purchaseUnit->name = $name;
                    if (empty($purchaseUnit->uuid)) {
                        $purchaseUnit->uuid = $uuid;
                    }
                    if ($baseUnitId) {
                        $purchaseUnit->base_unit_id = $baseUnitId;
                        $purchaseUnit->base_unit_name = $baseUnitName;
                    }
                    if ($description !== null) {
                        $purchaseUnit->description = $description;
                    }
                    $purchaseUnit->status = $status;
                    $purchaseUnit->save();
                }

                $idMap['purchase_units'][$uuid] = $purchaseUnit->id;
                $flatIdMap[$uuid] = $purchaseUnit->id;
            }

            // 5. Subcategories (SubCategory)
            $rawSubCategories = $payload['sub_categories'] ?? $payload['subcategories'] ?? [];
            foreach ($rawSubCategories as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $subCat = SubCategory::where('uuid', $uuid)->first();
                    if (! $subCat && ! empty($item['id']) && $item['id'] > 0) {
                        $subCat = SubCategory::find($item['id']);
                    }
                    if ($subCat) {
                        $subCatId = $subCat->id;
                        $hasDependent = Product::where('sub_category_id', $subCatId)->orWhere('sub_category_name', $subCat->name)->exists();
                        if (! $hasDependent) {
                            $subCat->delete();
                        }
                        $idMap['sub_categories'][$uuid] = $subCatId;
                        $flatIdMap[$uuid] = $subCatId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $catId = $item['parent_category_id'] ?? ($item['category_id'] ?? null);
                if (! $catId && ! empty($item['category_uuid'])) {
                    $catId = $flatIdMap[$item['category_uuid']] ?? ProductCategory::where('uuid', $item['category_uuid'])->value('id');
                }
                $catName = $item['parent_category_name'] ?? ($catId ? ProductCategory::where('id', $catId)->value('name') : 'General');
                $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                $description = $item['description'] ?? null;

                $subCat = SubCategory::where('uuid', $uuid)->first();
                if (! $subCat && ! empty($item['id']) && $item['id'] > 0) {
                    $subCat = SubCategory::find($item['id']);
                }

                if (! $subCat) {
                    $query = SubCategory::where('name', $name);
                    if ($catId) {
                        $query->where('parent_category_id', $catId);
                    }
                    $subCat = $query->first();
                    if ($subCat) {
                        if (empty($subCat->uuid)) {
                            $subCat->uuid = $uuid;
                        }
                        if ($catId) {
                            $subCat->parent_category_id = $catId;
                            $subCat->parent_category_name = $catName;
                        }
                        if ($description !== null) {
                            $subCat->description = $description;
                        }
                        $subCat->status = $status;
                        $subCat->save();
                    } else {
                        $subCat = new SubCategory;
                        $subCat->forceFill([
                            'uuid' => $uuid,
                            'parent_category_id' => $catId,
                            'parent_category_name' => $catName,
                            'name' => $name,
                            'description' => $description,
                            'status' => $status,
                        ]);
                        $subCat->save();
                    }
                } else {
                    $subCat->name = $name;
                    if (empty($subCat->uuid)) {
                        $subCat->uuid = $uuid;
                    }
                    if ($catId) {
                        $subCat->parent_category_id = $catId;
                        $subCat->parent_category_name = $catName;
                    }
                    if ($description !== null) {
                        $subCat->description = $description;
                    }
                    $subCat->status = $status;
                    $subCat->save();
                }

                $idMap['sub_categories'][$uuid] = $subCat->id;
                $flatIdMap[$uuid] = $subCat->id;
            }

            // 6. Brands (Brand)
            $rawBrands = $payload['brands'] ?? [];
            foreach ($rawBrands as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $brand = Brand::where('uuid', $uuid)->first();
                    if (! $brand && ! empty($item['id']) && $item['id'] > 0) {
                        $brand = Brand::find($item['id']);
                    }
                    if ($brand) {
                        $brandId = $brand->id;
                        $hasDependent = Product::where('brand_id', $brandId)->orWhere('brand_name', $brand->name)->exists();
                        if (! $hasDependent) {
                            $brand->delete();
                        }
                        $idMap['brands'][$uuid] = $brandId;
                        $flatIdMap[$uuid] = $brandId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                $code = $item['code'] ?? null;
                $description = $item['description'] ?? null;

                $brand = Brand::where('uuid', $uuid)->first();
                if (! $brand && ! empty($item['id']) && $item['id'] > 0) {
                    $brand = Brand::find($item['id']);
                }

                if (! $brand) {
                    $brand = Brand::where('name', $name)->first();
                    if ($brand) {
                        if (empty($brand->uuid)) {
                            $brand->uuid = $uuid;
                        }
                        if ($code !== null) {
                            $brand->code = $code;
                        }
                        if ($description !== null) {
                            $brand->description = $description;
                        }
                        $brand->status = $status;
                        $brand->save();
                    } else {
                        $brand = new Brand;
                        $brand->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'code' => $code,
                            'description' => $description,
                            'status' => $status,
                        ]);
                        $brand->save();
                    }
                } else {
                    $brand->name = $name;
                    if (empty($brand->uuid)) {
                        $brand->uuid = $uuid;
                    }
                    if ($code !== null) {
                        $brand->code = $code;
                    }
                    if ($description !== null) {
                        $brand->description = $description;
                    }
                    $brand->status = $status;
                    $brand->save();
                }

                $idMap['brands'][$uuid] = $brand->id;
                $flatIdMap[$uuid] = $brand->id;
            }

            // 7. Suppliers (Supplier)
            $rawSuppliers = $payload['suppliers'] ?? [];
            foreach ($rawSuppliers as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $supplier = Supplier::where('uuid', $uuid)->first();
                    if (! $supplier && ! empty($item['id']) && $item['id'] > 0) {
                        $supplier = Supplier::find($item['id']);
                    }
                    if ($supplier) {
                        $supId = $supplier->id;
                        $hasDependent = DB::table('purchases')->where('supplier_id', $supId)->exists()
                            || Product::where('supplier_id', $supId)->orWhere('supplier_name', $supplier->name)->exists();
                        if (! $hasDependent) {
                            $supplier->delete();
                        }
                        $idMap['suppliers'][$uuid] = $supId;
                        $flatIdMap[$uuid] = $supId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                $phone = ! empty($item['phone']) ? trim($item['phone']) : null;
                $email = $item['email'] ?? null;
                $address = $item['address'] ?? null;
                $contactPerson = $item['contact_person'] ?? null;
                $category = ! empty($item['category']) ? $item['category'] : 'General';
                $paymentTerms = ! empty($item['payment_terms']) ? $item['payment_terms'] : 'Net 30 Days';
                $notes = $item['notes'] ?? null;

                $supplier = Supplier::where('uuid', $uuid)->first();
                if (! $supplier && ! empty($item['id']) && $item['id'] > 0) {
                    $supplier = Supplier::find($item['id']);
                }

                if (! $supplier) {
                    if ($phone) {
                        $supplier = Supplier::where('phone', $phone)->first();
                    }
                    if (! $supplier) {
                        $supplier = Supplier::where('name', $name)->first();
                    }

                    if ($supplier) {
                        if (empty($supplier->uuid)) {
                            $supplier->uuid = $uuid;
                        }
                        $supplier->name = $name;
                        if ($phone !== null) $supplier->phone = $phone;
                        if ($email !== null) $supplier->email = $email;
                        if ($address !== null) $supplier->address = $address;
                        if ($contactPerson !== null) $supplier->contact_person = $contactPerson;
                        if (! empty($item['category'])) $supplier->category = $item['category'];
                        if (! empty($item['payment_terms'])) $supplier->payment_terms = $item['payment_terms'];
                        if ($notes !== null) $supplier->notes = $notes;
                        $supplier->status = $status;
                        $supplier->save();
                    } else {
                        $supplier = new Supplier;
                        $supplierData = [
                            'uuid' => $uuid,
                            'name' => $name,
                            'phone' => $phone ?? '-',
                            'status' => $status,
                            'category' => $category,
                            'payment_terms' => $paymentTerms,
                        ];
                        if ($email !== null) $supplierData['email'] = $email;
                        if ($address !== null) $supplierData['address'] = $address;
                        if ($contactPerson !== null) $supplierData['contact_person'] = $contactPerson;
                        if ($notes !== null) $supplierData['notes'] = $notes;

                        $supplier->forceFill($supplierData);
                        $supplier->save();
                    }
                } else {
                    $supplier->name = $name;
                    if (empty($supplier->uuid)) {
                        $supplier->uuid = $uuid;
                    }
                    if ($phone !== null) $supplier->phone = $phone;
                    if ($email !== null) $supplier->email = $email;
                    if ($address !== null) $supplier->address = $address;
                    if ($contactPerson !== null) $supplier->contact_person = $contactPerson;
                    if (! empty($item['category'])) $supplier->category = $item['category'];
                    if (! empty($item['payment_terms'])) $supplier->payment_terms = $item['payment_terms'];
                    if ($notes !== null) $supplier->notes = $notes;
                    $supplier->status = $status;
                    $supplier->save();
                }

                $idMap['suppliers'][$uuid] = $supplier->id;
                $flatIdMap[$uuid] = $supplier->id;
            }

            // 8. Variations (Variation)
            $rawVariations = $payload['variations'] ?? [];
            foreach ($rawVariations as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $variation = Variation::where('uuid', $uuid)->first();
                    if (! $variation && ! empty($item['id']) && $item['id'] > 0) {
                        $variation = Variation::find($item['id']);
                    }
                    if ($variation) {
                        $varId = $variation->id;
                        $hasDependent = DB::table('products')->where('variation_id', $varId)->exists();
                        if (! $hasDependent) {
                            $variation->delete();
                        }
                        $idMap['variations'][$uuid] = $varId;
                        $flatIdMap[$uuid] = $varId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $status = (isset($item['is_active']) && ! $item['is_active']) || (isset($item['status']) && $item['status'] === 'Inactive') ? 'Inactive' : 'Active';
                $types = $item['variation_types'] ?? ($item['types'] ?? []);
                if (is_string($types)) {
                    $decoded = json_decode($types, true);
                    $types = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $types)));
                }

                $variation = Variation::where('uuid', $uuid)->first();
                if (! $variation && ! empty($item['id']) && $item['id'] > 0) {
                    $variation = Variation::find($item['id']);
                }

                if (! $variation) {
                    $variation = Variation::where('name', $name)->first();
                    if ($variation) {
                        if (empty($variation->uuid)) {
                            $variation->uuid = $uuid;
                        }
                        if (! empty($types)) {
                            $variation->variation_types = array_values($types);
                        }
                        $variation->status = $status;
                        $variation->save();
                    } else {
                        $variation = new Variation;
                        $variation->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'variation_types' => array_values($types),
                            'status' => $status,
                        ]);
                        $variation->save();
                    }
                } else {
                    $variation->name = $name;
                    if (empty($variation->uuid)) {
                        $variation->uuid = $uuid;
                    }
                    if (! empty($types)) {
                        $variation->variation_types = array_values($types);
                    }
                    $variation->status = $status;
                    $variation->save();
                }

                $idMap['variations'][$uuid] = $variation->id;
                $flatIdMap[$uuid] = $variation->id;
            }

            // 9. Outlets / Warehouses
            $rawOutlets = $payload['warehouses'] ?? $payload['outlets'] ?? [];
            foreach ($rawOutlets as $item) {
                $uuid = $item['uuid'] ?? (string) Str::uuid();

                if (($item['action'] ?? null) === 'delete') {
                    $outlet = Outlet::where('uuid', $uuid)->first();
                    if (! $outlet && ! empty($item['id']) && $item['id'] > 0) {
                        $outlet = Outlet::find($item['id']);
                    }
                    if ($outlet) {
                        $outletId = $outlet->id;
                        $hasDependent = DB::table('sales')->where('outlet_id', $outletId)->exists();
                        if (! $hasDependent) {
                            $outlet->delete();
                        }
                        $idMap['outlets'][$uuid] = $outletId;
                        $flatIdMap[$uuid] = $outletId;
                    }
                    continue;
                }

                $name = trim($item['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $code = ! empty($item['code']) ? trim($item['code']) : null;
                $phone = ! empty($item['phone']) ? trim($item['phone']) : null;
                $email = $item['email'] ?? null;
                $address = $item['address'] ?? null;
                $isActive = isset($item['is_active']) ? (bool) $item['is_active'] : true;

                $outlet = Outlet::where('uuid', $uuid)->first();
                if (! $outlet && ! empty($item['id']) && $item['id'] > 0) {
                    $outlet = Outlet::find($item['id']);
                }

                if (! $outlet) {
                    if ($code) {
                        $outlet = Outlet::where('code', $code)->first();
                    }
                    if (! $outlet) {
                        $outlet = Outlet::where('name', $name)->first();
                    }

                    if ($outlet) {
                        if (empty($outlet->uuid)) {
                            $outlet->uuid = $uuid;
                        }
                        $outlet->name = $name;
                        if ($code !== null) $outlet->code = $code;
                        if ($phone !== null) $outlet->phone = $phone;
                        if ($email !== null) $outlet->email = $email;
                        if ($address !== null) $outlet->address = $address;
                        $outlet->is_active = $isActive;
                        $outlet->save();
                    } else {
                        $outlet = new Outlet;
                        $outlet->forceFill([
                            'uuid' => $uuid,
                            'name' => $name,
                            'code' => $code,
                            'phone' => $phone,
                            'email' => $email,
                            'address' => $address,
                            'is_active' => $isActive,
                        ]);
                        $outlet->save();
                    }
                } else {
                    $outlet->name = $name;
                    if (empty($outlet->uuid)) {
                        $outlet->uuid = $uuid;
                    }
                    if ($code !== null) $outlet->code = $code;
                    if ($phone !== null) $outlet->phone = $phone;
                    if ($email !== null) $outlet->email = $email;
                    if ($address !== null) $outlet->address = $address;
                    $outlet->is_active = $isActive;
                    $outlet->save();
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
