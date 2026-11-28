<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized Senior Accounting engine for Quotations.
 *
 * Ensures single-source-of-truth parity with SaleController / POS checkout:
 * - Tax-exclusive order tax base (prevents compound Tax-on-Tax on item-level taxes).
 * - Strict lifecycle controls (forbids manual 'Ordered' spoofing; enforces expiry_date).
 * - Segregated Pipeline vs Converted (Closed-Won) vs Declined/Expired (Closed-Lost) KPI metrics.
 * - Multi-unit & warehouse normalization and product cost masking (`products.view_cost`).
 */
class QuotationAccountingService
{
    public const MANUAL_ALLOWED_STATUSES = ['Sent', 'Pending', 'Accepted', 'Declined', 'Expired'];
    public const OPEN_PIPELINE_STATUSES = ['Sent', 'Pending', 'Accepted'];
    public const NON_CONVERTIBLE_STATUSES = ['Ordered', 'Declined', 'Expired'];

    /**
     * Compute authoritative Quotation document totals using Tax-Exclusive ordering.
     * Matches SaleController::createSaleFromValidated 1:1.
     */
    public function calculateDocumentTotals(
        array $items,
        float $orderDiscount = 0.0,
        float $taxRate = 0.0,
        ?float $flatTaxAmount = null,
        float $shipping = 0.0
    ): array {
        $itemsSubtotal = 0.0;
        $taxExclusiveBaseTotal = 0.0;
        $normalizedItems = [];

        foreach ($items as $item) {
            $qty = max(0.0, (float) ($item['quantity'] ?? 0));
            $unitPrice = max(0.0, (float) ($item['unit_price'] ?? 0));
            $grossLine = max(0.0, round($qty * $unitPrice, 2));
            $itemDiscount = round(min($grossLine, max(0.0, (float) ($item['discount'] ?? 0))), 2);
            $itemTax = round(max(0.0, (float) ($item['tax'] ?? 0)), 2);

            $taxExclusiveLine = round(max(0.0, $grossLine - $itemDiscount), 2);
            $lineSubtotal = round($taxExclusiveLine + $itemTax, 2);

            $itemsSubtotal += $lineSubtotal;
            $taxExclusiveBaseTotal += $taxExclusiveLine;

            $item['discount'] = $itemDiscount;
            $item['tax'] = $itemTax;
            $item['computed_subtotal'] = $lineSubtotal;
            $item['tax_exclusive_subtotal'] = $taxExclusiveLine;
            $normalizedItems[] = $item;
        }

        $itemsSubtotal = round($itemsSubtotal, 2);
        $taxExclusiveBaseTotal = round($taxExclusiveBaseTotal, 2);
        $rawDiscount = max(0.0, $orderDiscount);
        // Match SaleController 1:1: order discount applies strictly to the tax-exclusive merchandise base (never eats item tax).
        $orderDiscount = round(min($rawDiscount, $taxExclusiveBaseTotal), 2);
        $taxRate = min(100.0, max(0.0, $taxRate));
        $shipping = round(max(0.0, $shipping), 2);

        // Order-level percentage tax applies strictly to the tax-exclusive net base (never compound Tax-on-Tax).
        $taxableBase = max(0.0, round($taxExclusiveBaseTotal - $orderDiscount, 2));
        $orderTaxAmount = $taxRate > 0
            ? round($taxableBase * ($taxRate / 100.0), 2)
            : (($flatTaxAmount !== null && $flatTaxAmount > 0) ? round($flatTaxAmount, 2) : 0.0);

        $grandTotal = round(max(0.0, $itemsSubtotal - $orderDiscount + $orderTaxAmount + $shipping), 2);

        return [
            'items' => $normalizedItems,
            'items_subtotal' => $itemsSubtotal,
            'tax_exclusive_base' => $taxExclusiveBaseTotal,
            'taxable_base' => round($taxableBase, 2),
            'discount' => $orderDiscount,
            'tax_amount' => $orderTaxAmount,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Check whether expiry_date has strictly passed (inclusive through end of expiry day).
     */
    public function hasPastExpiryDate(Quotation $quotation, ?Carbon $asOf = null): bool
    {
        if (empty($quotation->expiry_date)) {
            return false;
        }

        try {
            $expiryEndOfDay = Carbon::parse($quotation->expiry_date)->endOfDay();
            return $expiryEndOfDay->lt($asOf ?? Carbon::now());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check whether a Quotation is effectively expired by explicit status or past expiry_date.
     */
    public function isExpired(Quotation $quotation, ?Carbon $asOf = null): bool
    {
        if ($quotation->status === 'Expired') {
            return true;
        }

        if (!in_array($quotation->status, self::OPEN_PIPELINE_STATUSES, true)) {
            return false;
        }

        return $this->hasPastExpiryDate($quotation, $asOf);
    }

    /**
     * Effective accounting status taking past-due expiry_date into account.
     */
    public function effectiveStatus(Quotation $quotation, ?Carbon $asOf = null, ?bool $precomputedExpired = null): string
    {
        $isExpired = $precomputedExpired ?? $this->isExpired($quotation, $asOf);
        return $isExpired && in_array($quotation->status, self::OPEN_PIPELINE_STATUSES, true)
            ? 'Expired'
            : (string) $quotation->status;
    }

    /**
     * Return localized validation error if a Quotation cannot be converted to a Sale, or null if valid.
     */
    public function conversionBlockReason(Quotation $quotation): ?string
    {
        if (in_array($quotation->status, self::NON_CONVERTIBLE_STATUSES, true)) {
            return "Quotation #{$quotation->reference_no} သည် {$quotation->status} ဖြစ်နေပြီးဖြစ်သဖြင့် အရောင်းဘောင်ချာသို့ ပြောင်းလဲ၍ မရနိုင်ပါ။";
        }

        if ($this->hasPastExpiryDate($quotation)) {
            $expiryStr = Carbon::parse($quotation->expiry_date)->toDateString();
            return "Quotation #{$quotation->reference_no} သည် သက်တမ်းကုန်ဆုံးရက် ({$expiryStr}) ကျော်လွန်နေပြီဖြစ်သဖြင့် အရောင်းဘောင်ချာသို့ ပြောင်းလဲ၍ မရနိုင်ပါ။";
        }

        return null;
    }

    /**
     * Determine proper status when a previously converted Sale is voided or deleted.
     */
    public function statusAfterSaleReversal(Quotation $quotation): string
    {
        return $this->hasPastExpiryDate($quotation) ? 'Expired' : 'Sent';
    }

    /**
     * Compute segregated Pipeline vs Converted vs Declined/Expired KPI metrics in a single query.
     */
    public function computeSummaryStats($baseQuery): array
    {
        $todayDate = Carbon::today()->toDateString();

        $aggQuery = (clone $baseQuery)->setEagerLoads([]);
        $aggQuery->getQuery()->columns = null;
        $aggQuery->getQuery()->orders = null;

        $agg = $aggQuery->toBase()->selectRaw(
            "COUNT(*) as total_records, " .
            "MAX(quotations.updated_at) as max_updated, " .
            "COALESCE(SUM(CASE WHEN quotations.status IN ('Sent', 'Pending', 'Accepted') AND (quotations.expiry_date IS NULL OR quotations.expiry_date >= ?) THEN quotations.grand_total ELSE 0 END), 0) as pipeline_total, " .
            "SUM(CASE WHEN quotations.status IN ('Sent', 'Pending', 'Accepted') AND (quotations.expiry_date IS NULL OR quotations.expiry_date >= ?) THEN 1 ELSE 0 END) as pipeline_count, " .
            "COALESCE(SUM(CASE WHEN quotations.status = 'Ordered' THEN quotations.grand_total ELSE 0 END), 0) as converted_total, " .
            "SUM(CASE WHEN quotations.status = 'Ordered' THEN 1 ELSE 0 END) as converted_count, " .
            "COALESCE(SUM(CASE WHEN quotations.status IN ('Declined', 'Expired') OR (quotations.status IN ('Sent', 'Pending', 'Accepted') AND quotations.expiry_date IS NOT NULL AND quotations.expiry_date < ?) THEN quotations.grand_total ELSE 0 END), 0) as lost_total, " .
            "SUM(CASE WHEN quotations.status IN ('Declined', 'Expired') OR (quotations.status IN ('Sent', 'Pending', 'Accepted') AND quotations.expiry_date IS NOT NULL AND quotations.expiry_date < ?) THEN 1 ELSE 0 END) as lost_count, " .
            "COALESCE(SUM(quotations.grand_total), 0) as gross_total",
            [$todayDate, $todayDate, $todayDate, $todayDate]
        )->first();

        $pipelineTotal = round((float) ($agg->pipeline_total ?? 0.0), 2);

        return [
            'total_records' => (int) ($agg->total_records ?? 0),
            'max_updated' => (string) ($agg->max_updated ?? 'none'),
            'pipeline_total' => $pipelineTotal,
            'pipeline_count' => (int) ($agg->pipeline_count ?? 0),
            'converted_total' => round((float) ($agg->converted_total ?? 0.0), 2),
            'converted_count' => (int) ($agg->converted_count ?? 0),
            'lost_total' => round((float) ($agg->lost_total ?? 0.0), 2),
            'lost_count' => (int) ($agg->lost_count ?? 0),
            'gross_total' => round((float) ($agg->gross_total ?? 0.0), 2),
            'total_grand_total' => $pipelineTotal,
        ];
    }

    /**
     * Resolve warehouse_id safely using ProductUnitConversionService.
     */
    public function resolveWarehouseId(?int $warehouseId, ?string $warehouseName, ProductUnitConversionService $unitService): ?int
    {
        return $unitService->warehouseId($warehouseId, $warehouseName);
    }

    /**
     * Normalize multi-unit item fields against ProductUnitConversionService when linked to a Product.
     */
    public function resolveItemUnitFields(array $item, ?Product $product, ProductUnitConversionService $unitService): array
    {
        $qty = max(0.0, (float) ($item['quantity'] ?? 0));
        $unitPrice = max(0.0, (float) ($item['unit_price'] ?? 0));
        $unitId = !empty($item['unit_id']) ? (int) $item['unit_id'] : null;
        $unitName = isset($item['unit_name']) ? trim((string) $item['unit_name']) : null;
        $variantId = !empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;

        $conversionRate = (float) ($item['conversion_rate'] ?? 1.0);
        if ($conversionRate <= 0) {
            $conversionRate = 1.0;
        }

        if ($product) {
            $resolved = $unitService->resolve(
                product: $product,
                type: 'sale',
                unitId: $unitId,
                unitName: $unitName !== '' ? $unitName : null,
                variantId: $variantId
            );
            $unitId = $resolved['unit_id'] ?? $unitId;
            $unitName = $resolved['unit_name'] ?? ($unitName !== '' ? $unitName : ($product->unit_name ?: 'Piece'));
            $conversionRate = max(0.0001, (float) ($resolved['conversion_rate'] ?? $conversionRate));
        }

        $baseQuantity = isset($item['base_quantity']) && (float) $item['base_quantity'] > 0 && !$product
            ? (float) $item['base_quantity']
            : round($qty * $conversionRate, 4);

        $baseUnitPrice = isset($item['base_unit_price']) && (float) $item['base_unit_price'] > 0 && !$product
            ? (float) $item['base_unit_price']
            : round($unitPrice / $conversionRate, 4);

        return [
            'product_id' => $product?->id ?? (!empty($item['product_id']) ? (int) $item['product_id'] : null),
            'product_variant_id' => $variantId,
            'unit_id' => $unitId,
            'unit_name' => $unitName ?: 'Piece',
            'conversion_rate' => $conversionRate,
            'base_quantity' => $baseQuantity,
            'base_unit_price' => $baseUnitPrice,
        ];
    }

    /**
     * Resolve effective authenticated user across CheckPermission bearer-token resolver and guard auth().
     */
    public function canViewCost(?\Illuminate\Http\Request $request = null): bool
    {
        $req = $request ?? request();
        $user = $req?->user() ?? auth()->user();
        if ($user === null && $req && $req->bearerToken()) {
            $user = \App\Models\User::resolveFromRequest($req);
        }
        if ($user === null) {
            return true;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return method_exists($user, 'hasPermission')
            ? $user->hasPermission('products.view_cost')
            : false;
    }

    /**
     * Hide sensitive product & variant cost attributes from API responses when caller lacks `products.view_cost`.
     */
    public function maskSensitiveCostFields(?Quotation $quotation, ?bool $canViewCost = null): ?Quotation
    {
        if (!$quotation) {
            return null;
        }

        $allowed = $canViewCost ?? $this->canViewCost();
        if ($allowed) {
            return $quotation;
        }

        if ($quotation->relationLoaded('items')) {
            foreach ($quotation->items as $item) {
                if ($item->relationLoaded('product') && $item->product) {
                    $item->product->makeHidden(['cost_price', 'purchase_price']);
                }
                if ($item->relationLoaded('variant') && $item->variant) {
                    $item->variant->makeHidden(['cost_price', 'purchase_price', 'cost']);
                }
            }
        }

        return $quotation;
    }

    /**
     * Append live expiry metadata and optional linked sale reference for UI clarity.
     */
    public function enrichQuotationResponse(Quotation $quotation, ?bool $canViewCost = null): Quotation
    {
        $this->maskSensitiveCostFields($quotation, $canViewCost);
        $isExpired = $this->isExpired($quotation);
        $quotation->setAttribute('is_expired', $isExpired);
        $quotation->setAttribute('effective_status', $this->effectiveStatus($quotation, null, $isExpired));
        if ($quotation->relationLoaded('sale') && $quotation->sale) {
            $quotation->setAttribute('sale_id', $quotation->sale->id);
            $quotation->setAttribute('sale_reference_no', $quotation->sale->reference_no);
        }

        return $quotation;
    }

    /**
     * Resolve active store currency code dynamically (avoiding hardcoded 'MMK' in CSV headers).
     */
    public function resolveDefaultCurrencyCode(): string
    {
        try {
            if (Schema::hasTable('currencies')) {
                $code = Currency::where('is_default', true)->value('code');
                if (!empty($code)) {
                    return strtoupper(trim((string) $code));
                }
            }
            if (Schema::hasTable('settings')) {
                $settingCode = Setting::get('default_currency', 'MMK');
                if (!empty($settingCode)) {
                    return strtoupper(trim((string) $settingCode));
                }
            }
        } catch (\Throwable $e) {
            // Fall through safely in unit/partial schema contexts
        }

        return 'MMK';
    }
}
