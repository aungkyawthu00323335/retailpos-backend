<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FifoInventoryService;
use App\Services\ProductUnitConversionService;
use App\Services\PurchaseAccountingService;
use App\Support\ListCursorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleReturnController extends Controller
{
    use \App\Traits\GeneratesSequentialCode;
    /** Return reason options */
    public const RETURN_REASONS = [
        'Defective / Damaged Product',
        'Wrong Item Delivered',
        'Customer Changed Mind',
        'Expired Product',
        'Duplicate Order',
        'Other',
    ];

    protected function escapeCsvCell($value): string
    {
        $str = (string)($value ?? '');
        if ($str === '') {
            return '';
        }
        $trimmed = ltrim($str);
        if ($trimmed !== '') {
            $first = $trimmed[0];
            if (in_array($first, ['=', '+', '@', "\t", "\r"], true)
                || ($first === '-' && !preg_match('/^-\d+(\.\d+)?$/', $trimmed))
            ) {
                return "'" . $str;
            }
        }
        return $str;
    }

    protected function maskSaleReturnCostFields(?SaleReturn $ret, bool $canViewCost, bool $maskItems = true): ?SaleReturn
    {
        if (!$ret || $canViewCost) {
            return $ret;
        }
        $ret->makeHidden(['return_cost_total', 'cost_variance']);
        if ($maskItems) {
            $rawItems = $ret->items;
            if (is_array($rawItems) && !empty($rawItems)) {
                $masked = array_map(static function ($item) {
                    if (is_array($item)) {
                        unset($item['unit_cost'], $item['base_unit_cost'], $item['total_cost']);
                    }
                    return $item;
                }, $rawItems);
                $ret->setAttribute('items_payload', json_encode($masked));
            }
        }
        return $ret;
    }

    protected function throwValidationError(string $field, string $message): never
    {
        $validator = Validator::make([], []);
        $validator->errors()->add($field, $message);
        throw new ValidationException(
            validator: $validator,
            response: response()->json([
                'status'  => 'error',
                'message' => $message,
                'errors'  => [$field => [$message]],
            ], 422)
        );
    }

    protected function normalizeStatus(?string $status): string
    {
        $s = trim((string)$status);
        if (strcasecmp($s, 'Received') === 0 || strcasecmp($s, 'Completed') === 0 || $s === '') {
            return 'Completed';
        }
        if (strcasecmp($s, 'Pending') === 0) {
            return 'Pending';
        }
        if (strcasecmp($s, 'Voided') === 0) {
            return 'Voided';
        }
        return $s;
    }

    protected function resolveLinkedSale(?int $saleId, ?string $saleRef, bool $lock = false): ?Sale
    {
        $query = Sale::with('items');
        if ($lock) {
            $query->lockForUpdate();
        }
        if ($saleId) {
            $found = (clone $query)->find($saleId);
            if ($found) {
                return $found;
            }
        }
        $clean = trim((string)$saleRef);
        if ($clean !== '') {
            $variants = array_values(array_unique([
                $clean,
                str_replace('-', '_', $clean),
                str_replace('_', '-', $clean),
            ]));
            return (clone $query)->whereIn('reference_no', $variants)->first();
        }
        return null;
    }

    /**
     * Validate return items against the linked Completed Sale and cumulative prior active returns,
     * resolve units strictly, and recompute server line totals.
     */
    protected function validateAndPrepareReturnItems(
        array $rawItems,
        Sale $linkedSale,
        ?int $excludeReturnId,
        ProductUnitConversionService $unitService
    ): array {
        $soldBaseBySku = [];
        $soldLineTaxExclBase = 0.0;
        $soldLinePriceBySku = [];
        foreach ($linkedSale->items as $si) {
            $vId = !empty($si->product_variant_id) ? (int)$si->product_variant_id : 0;
            $skuKey = (int)$si->product_id . '_' . $vId;
            $cRate = (float)($si->conversion_rate > 0 ? $si->conversion_rate : 1.0);
            $bQty = (float)($si->base_quantity > 0 ? $si->base_quantity : ($si->quantity * $cRate));
            $soldBaseBySku[$skuKey] = round(($soldBaseBySku[$skuKey] ?? 0.0) + $bQty, 4);
            $lineBase = max(0.0, ((float)$si->quantity * (float)$si->unit_price) - (float)$si->discount);
            $soldLineTaxExclBase += $lineBase;
            $soldBasePrice = (float)($si->base_unit_price > 0 ? $si->base_unit_price : ((float)$si->unit_price / $cRate));
            $soldNetBasePrice = $bQty > 0.0001 ? max(0.0, round($lineBase / $bQty, 4)) : $soldBasePrice;
            if (!isset($soldLinePriceBySku[$skuKey]) || $soldBasePrice > $soldLinePriceBySku[$skuKey]['max_base_unit_price']) {
                $soldLinePriceBySku[$skuKey] = [
                    'unit_price'              => (float)$si->unit_price,
                    'conversion_rate'         => $cRate,
                    'max_base_unit_price'     => $soldBasePrice,
                    'max_net_base_unit_price' => $soldNetBasePrice,
                ];
            } else {
                $soldLinePriceBySku[$skuKey]['max_net_base_unit_price'] = max(
                    (float)($soldLinePriceBySku[$skuKey]['max_net_base_unit_price'] ?? 0.0),
                    $soldNetBasePrice
                );
            }
        }

        // Accumulate base quantities already returned across all active (non-Voided/Cancelled) returns for this Sale
        $priorReturns = SaleReturn::whereNotIn('status', ['Voided', 'Cancelled'])
            ->when($excludeReturnId, fn($q) => $q->where('id', '!=', $excludeReturnId))
            ->where(function ($q) use ($linkedSale) {
                $q->where('sale_id', $linkedSale->id);
                if (!empty($linkedSale->reference_no)) {
                    $clean = trim((string)$linkedSale->reference_no);
                    $variants = array_values(array_unique([
                        $clean,
                        str_replace('-', '_', $clean),
                        str_replace('_', '-', $clean),
                    ]));
                    $q->orWhereIn('sale_reference_no', $variants);
                }
            })
            ->lockForUpdate()
            ->get();

        $alreadyReturnedBaseBySku = [];
        $priorReturnedGrandTotal = 0.0;
        foreach ($priorReturns as $pr) {
            $priorReturnedGrandTotal = round($priorReturnedGrandTotal + max(0.0, (float)$pr->grand_total - (float)$pr->shipping), 2);
            foreach ($pr->items as $pri) {
                $pId = (int)($pri['product_id'] ?? 0);
                if ($pId <= 0) continue;
                $vId = !empty($pri['product_variant_id']) ? (int)$pri['product_variant_id'] : 0;
                $k = $pId . '_' . $vId;
                $cRate = (float)($pri['conversion_rate'] ?? 1.0);
                if ($cRate <= 0) $cRate = 1.0;
                $bQty = isset($pri['base_quantity']) && (float)$pri['base_quantity'] > 0
                    ? (float)$pri['base_quantity']
                    : ((float)($pri['quantity'] ?? 0) * $cRate);
                $alreadyReturnedBaseBySku[$k] = round(($alreadyReturnedBaseBySku[$k] ?? 0.0) + $bQty, 4);
            }
        }

        // Lock all involved products in ascending ID order to avoid deadlocks
        $prodIds = array_values(array_unique(array_filter(array_map(fn($it) => !empty($it['product_id']) ? (int)$it['product_id'] : null, $rawItems))));
        $prodCodes = array_values(array_unique(array_filter(array_map(fn($it) => !empty($it['product_code']) ? trim((string)$it['product_code']) : null, $rawItems))));
        sort($prodIds);
        $products = Product::with(['variants', 'unitLinks.unit'])
            ->where(function ($q) use ($prodIds, $prodCodes) {
                if (!empty($prodIds)) {
                    $q->whereIn('id', $prodIds);
                }
                if (!empty($prodCodes)) {
                    $q->orWhereIn('code', $prodCodes);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $productsById = $products->keyBy('id');
        $productsByCode = $products->keyBy('code');

        $incomingBaseBySku = [];
        $preparedItems = [];
        $computedSubtotal = 0.0;
        $taxExclusiveBase = 0.0;

        foreach ($rawItems as $idx => $it) {
            $product = !empty($it['product_id'])
                ? $productsById->get((int)$it['product_id'])
                : ($productsByCode->get(trim((string)($it['product_code'] ?? ''))) ?? null);
            if (!$product) {
                $this->throwValidationError("items.{$idx}.product_id", 'Returned product does not exist.');
            }

            $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
            if (($product->has_variants || $product->variants->isNotEmpty()) && empty($variantId)) {
                $this->throwValidationError("items.{$idx}.product_variant_id", "Please select a variation for '{$product->name}'.");
            }

            $skuKey = (int)$product->id . '_' . ($variantId ?? 0);
            if (!isset($soldBaseBySku[$skuKey])) {
                $this->throwValidationError("items.{$idx}.product_id", "Product '{$product->name}' was not part of linked Sale #{$linkedSale->reference_no}.");
            }

            // Strict unit conversion resolution
            $linkedUnit = $unitService->resolve(
                $product,
                'sale',
                isset($it['unit_id']) ? (int)$it['unit_id'] : null,
                $it['unit_name'] ?? null,
                $variantId
            );
            if (($linkedUnit['id'] ?? null) === null && !empty($it['conversion_rate']) && (float)$it['conversion_rate'] > 0) {
                $hasActiveLinks = $product->relationLoaded('unitLinks')
                    ? $product->unitLinks->contains(fn ($ul) => (bool)$ul->is_active && $ul->unit_type === 'sale')
                    : $product->unitLinks()->where('is_active', true)->where('unit_type', 'sale')->exists();
                if (!$hasActiveLinks) {
                    $linkedUnit['conversion_rate'] = (float)$it['conversion_rate'];
                }
            }
            $convRate = max(0.0001, (float)($linkedUnit['conversion_rate'] ?? 1.0));
            $qty = round(max(0.0, (float)($it['quantity'] ?? 0)), 4);
            if ($qty <= 0) {
                $this->throwValidationError("items.{$idx}.quantity", "Return quantity must be greater than zero for '{$product->name}'.");
            }
            $baseQty = $unitService->baseQuantity($qty, $convRate);
            $incomingBaseBySku[$skuKey] = round(($incomingBaseBySku[$skuKey] ?? 0.0) + $baseQty, 4);

            $priorUsed = $alreadyReturnedBaseBySku[$skuKey] ?? 0.0;
            $soldMax = $soldBaseBySku[$skuKey];
            if ($priorUsed + $incomingBaseBySku[$skuKey] > $soldMax + 0.0001) {
                $remainingAllowed = max(0.0, round($soldMax - $priorUsed, 4));
                $this->throwValidationError(
                    "items.{$idx}.quantity",
                    "Cumulative return quantity for '{$product->name}' exceeds remaining returnable quantity on Sale #{$linkedSale->reference_no} (Remaining base qty: {$remainingAllowed}, Requested: {$incomingBaseBySku[$skuKey]})."
                );
            }

            $maxBasePrice = (float)($soldLinePriceBySku[$skuKey]['max_base_unit_price'] ?? 0.0);
            $maxAllowedUnitPrice = round($maxBasePrice * $convRate, 4);
            $defaultUnitPrice = $maxAllowedUnitPrice > 0 ? $maxAllowedUnitPrice : (float)($product->selling_price ?? 0);
            $unitPrice = isset($it['unit_price']) ? round(max(0.0, (float)$it['unit_price']), 4) : $defaultUnitPrice;
            if (isset($soldLinePriceBySku[$skuKey]) && $unitPrice > $maxAllowedUnitPrice + 0.05) {
                $this->throwValidationError(
                    "items.{$idx}.unit_price",
                    "Return unit price ({$unitPrice}) for '{$product->name}' cannot exceed the original sold unit price ({$maxAllowedUnitPrice})."
                );
            }
            $lineDisc = round(max(0.0, (float)($it['discount'] ?? 0)), 2);
            $lineTax = round(max(0.0, (float)($it['tax'] ?? 0)), 2);
            $lineBase = max(0.0, ($qty * $unitPrice) - $lineDisc);
            $maxNetBasePrice = (float)($soldLinePriceBySku[$skuKey]['max_net_base_unit_price'] ?? $maxBasePrice);
            $maxAllowedLineBase = round($maxNetBasePrice * $baseQty, 2);
            if (isset($soldLinePriceBySku[$skuKey]) && $lineBase > $maxAllowedLineBase + 0.05) {
                $this->throwValidationError(
                    "items.{$idx}.unit_price",
                    "Net return value ({$lineBase}) for '{$product->name}' cannot exceed the net discounted sold value ({$maxAllowedLineBase})."
                );
            }
            $lineSubtotal = round($lineBase + $lineTax, 2);

            $computedSubtotal += $lineSubtotal;
            $taxExclusiveBase += $lineBase;

            $preparedItems[] = [
                'product_id'         => (int)$product->id,
                'product_variant_id' => $variantId,
                'variant_name'       => $it['variant_name'] ?? null,
                'product_code'       => $it['product_code'] ?? $product->code,
                'product_name'       => $it['product_name'] ?? $product->name,
                'unit_id'            => $linkedUnit['unit_id'] ?? ($it['unit_id'] ?? $product->sale_unit_id ?? $product->unit_id),
                'unit_name'          => $linkedUnit['unit_name'] ?: ($it['unit_name'] ?? ''),
                'conversion_rate'    => $convRate,
                'quantity'           => $qty,
                'base_quantity'      => $baseQty,
                'sold_quantity'      => $it['sold_quantity'] ?? $soldMax,
                'unit_price'         => $unitPrice,
                'base_unit_price'    => $unitService->basePrice($unitPrice, $convRate),
                'discount'           => $lineDisc,
                'tax'                => $lineTax,
                'subtotal'           => $lineSubtotal,
                '_product'           => $product,
            ];
        }

        return [
            'items'                     => $preparedItems,
            'subtotal'                  => round($computedSubtotal, 2),
            'tax_exclusive_base'        => round($taxExclusiveBase, 2),
            'sale_tax_exclusive_base'   => round($soldLineTaxExclBase, 2),
            'prior_returned_grand_total'=> $priorReturnedGrandTotal,
            'products_by_id'            => $productsById,
        ];
    }

    /**
     * Recompute and persist authoritative AR credit offset on the linked Sale
     * from all Completed sibling SaleReturns.
     */
    protected function syncLinkedSaleReturnCredit(?Sale $linkedSale): void
    {
        if (!$linkedSale) {
            return;
        }
        $lockedSale = Sale::where('id', $linkedSale->id)->lockForUpdate()->first();
        if (!$lockedSale) {
            return;
        }
        $totalCredit = (float)SaleReturn::where('status', 'Completed')
            ->where(function ($q) use ($lockedSale) {
                $q->where('sale_id', $lockedSale->id);
                if (!empty($lockedSale->reference_no)) {
                    $q->orWhere('sale_reference_no', $lockedSale->reference_no);
                }
            })
            ->sum('applied_credit_amount');

        $totalCredit = min((float)$lockedSale->grand_total, max(0.0, round($totalCredit, 2)));
        $netCollectible = max(0.0, round((float)$lockedSale->grand_total - $totalCredit, 2));
        $newDue = max(0.0, round($netCollectible - (float)$lockedSale->paid_amount, 2));

        $newPaymentStatus = $newDue <= 0.001
            ? 'Paid'
            : (((float)$lockedSale->paid_amount > 0.001 || $totalCredit > 0.001) ? 'Partial' : 'Unpaid');

        $lockedSale->update([
            'returned_credit_amount' => $totalCredit,
            'due_amount'             => $newDue,
            'payment_status'         => $newPaymentStatus,
        ]);
    }

    /**
     * Generate next sequential sale return reference code (e.g. SR_0010001).
     */
    public function generateCode()
    {
        return response()->json($this->peekNextSequentialCode('sale_return'));
    }

    public function export(Request $request)
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $query = $this->buildFilteredQuery($request, true);

        $sortColumn = $request->input('sort_by', 'id');
        $sortDirection = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumnMap = [
            'reference' => 'reference_no',
            'customer'  => 'customer_name',
            'warehouse' => 'warehouse_name',
            'amount'    => 'grand_total',
            'paid'      => 'paid_amount',
            'due'       => 'due_amount',
            'user'      => 'created_by',
            'sale_ref'  => 'sale_reference_no',
        ];
        if (isset($sortColumnMap[$sortColumn])) {
            $sortColumn = $sortColumnMap[$sortColumn];
        }
        $allowedSorts = [
            'id', 'reference_no', 'sale_reference_no', 'customer_name', 'warehouse_name',
            'date', 'grand_total', 'paid_amount', 'due_amount', 'status',
            'payment_status', 'created_by', 'updated_at',
        ];
        if (!in_array($sortColumn, $allowedSorts, true)) {
            $sortColumn = 'id';
        }
        $query->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sale_returns_export_' . date('Ymd_His') . '.csv"',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        $callback = function () use ($query, $request) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, ['Sales Returns Export']);
            fputcsv($output, ['Generated At', date('Y-m-d H:i:s')]);

            $filterMeta = [];
            if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
                $filterMeta[] = ['Warehouse', $request->input('warehouse')];
            }
            if ($request->filled('customer') && $request->input('customer') !== 'All') {
                $filterMeta[] = ['Customer', $request->input('customer')];
            }
            if ($request->filled('status') && $request->input('status') !== 'All') {
                $filterMeta[] = ['Status', $request->input('status')];
            }
            if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
                $filterMeta[] = ['Payment Status', $request->input('payment_status')];
            }
            if ($request->filled('payment_type') && $request->input('payment_type') !== 'All') {
                $filterMeta[] = ['Payment Type', $request->input('payment_type')];
            }
            if ($request->filled('return_reason') && $request->input('return_reason') !== 'All') {
                $filterMeta[] = ['Return Reason', $request->input('return_reason')];
            }
            if ($request->filled('refund_tender') && $request->input('refund_tender') !== 'All') {
                $filterMeta[] = ['Refund Tender', $request->input('refund_tender')];
            }
            $uFilter = $request->input('created_by') ?: $request->input('user');
            if (!empty($uFilter) && $uFilter !== 'All') {
                $filterMeta[] = ['Created By', $uFilter];
            }
            $fDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
            $tDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
            if (!empty($fDate) || !empty($tDate)) {
                $filterMeta[] = ['Date Range', trim(($fDate ?: 'Start') . ' to ' . ($tDate ?: 'End'))];
            }
            if ($request->filled('search')) {
                $filterMeta[] = ['Search', $request->input('search')];
            }
            foreach ($filterMeta as $fm) {
                fputcsv($output, [$fm[0], $this->escapeCsvCell($fm[1])]);
            }
            fputcsv($output, []);

            fputcsv($output, [
                'SN', 'Return Reference', 'Sale Reference', 'Customer', 'Warehouse',
                'Status', 'Return Reason', 'Grand Total', 'Paid Amount', 'Due Amount',
                'Refund Tender', 'Payment Status', 'Created By', 'User Role', 'Date', 'Time', 'Notes',
                'Subtotal', 'Discount', 'Tax Rate (%)', 'Tax Amount', 'Shipping', 'Applied Credit'
            ]);

            $sn = 1;
            $totalGrand = 0.0;
            $totalPaid = 0.0;
            $totalDue = 0.0;
            $totalSubtotal = 0.0;
            $totalDiscount = 0.0;
            $totalTax = 0.0;
            $totalShipping = 0.0;
            $totalCredit = 0.0;
            $flushCount = 0;

            foreach ($query->toBase()->cursor() as $ret) {
                $isActiveStatus = ($ret->status === 'Completed');
                $grand = (float)$ret->grand_total;
                $paid = (float)$ret->paid_amount;
                $due = (float)$ret->due_amount;
                $sub = (float)($ret->subtotal ?? 0);
                $disc = (float)($ret->discount ?? 0);
                $taxRate = (float)($ret->tax_rate ?? 0);
                $taxAmt = (float)($ret->tax_amount ?? 0);
                $ship = (float)($ret->shipping ?? 0);
                $credit = (float)($ret->applied_credit_amount ?? 0);
                if ($isActiveStatus) {
                    $totalGrand += $grand;
                    $totalPaid += $paid;
                    $totalDue += $due;
                    $totalSubtotal += $sub;
                    $totalDiscount += $disc;
                    $totalTax += $taxAmt;
                    $totalShipping += $ship;
                    $totalCredit += $credit;
                }

                $userName = $ret->user_name ?: ($ret->created_by ?: 'System');
                $userRole = $ret->user_role ?: 'Sales Staff';

                $dateOnly = $ret->date ? explode(' ', trim($ret->date))[0] : '';
                $timeStr = '';
                if ($ret->created_at) {
                    $timeStr = date('H:i:s', strtotime($ret->created_at));
                } elseif ($ret->date && strpos($ret->date, ' ') !== false) {
                    $timeStr = explode(' ', trim($ret->date))[1];
                }

                fputcsv($output, [
                    $sn++,
                    $this->escapeCsvCell($ret->reference_no),
                    $this->escapeCsvCell($ret->sale_reference_no ?: '—'),
                    $this->escapeCsvCell($ret->customer_name ?: 'Walk-in Customer'),
                    $this->escapeCsvCell($ret->warehouse_name),
                    $this->escapeCsvCell($ret->status),
                    $this->escapeCsvCell($ret->return_reason ?: 'General'),
                    number_format($grand, 2, '.', ''),
                    number_format($paid, 2, '.', ''),
                    number_format($due, 2, '.', ''),
                    $this->escapeCsvCell($ret->refund_tender ?: 'Cash'),
                    $this->escapeCsvCell($ret->payment_status ?: 'Completed'),
                    $this->escapeCsvCell($userName),
                    $this->escapeCsvCell($userRole),
                    $dateOnly,
                    $timeStr,
                    $this->escapeCsvCell($ret->notes ?: ''),
                    number_format($sub, 2, '.', ''),
                    number_format($disc, 2, '.', ''),
                    number_format($taxRate, 2, '.', ''),
                    number_format($taxAmt, 2, '.', ''),
                    number_format($ship, 2, '.', ''),
                    number_format($credit, 2, '.', ''),
                ]);

                if (++$flushCount % 500 === 0) {
                    flush();
                }
            }

            fputcsv($output, [
                'TOTAL', ($sn - 1) . ' Returns', '—', '—', '—', '—', '—',
                number_format($totalGrand, 2, '.', ''),
                number_format($totalPaid, 2, '.', ''),
                number_format($totalDue, 2, '.', ''),
                '—', '—', '—', '—', '—', '—', '—',
                number_format($totalSubtotal, 2, '.', ''),
                number_format($totalDiscount, 2, '.', ''),
                '—',
                number_format($totalTax, 2, '.', ''),
                number_format($totalShipping, 2, '.', ''),
                number_format($totalCredit, 2, '.', ''),
            ]);

            fclose($output);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function buildFilteredQuery(Request $request, bool $noItems = false)
    {
        $columns = [
            'id', 'reference_no', 'sale_id', 'sale_reference_no', 'customer_id', 'customer_name',
            'warehouse_id', 'warehouse_name', 'cash_register_id', 'date', 'status', 'tax_rate', 'tax_amount',
            'discount', 'shipping', 'subtotal', 'grand_total', 'paid_amount', 'due_amount',
            'applied_credit_amount', 'return_cost_total', 'cost_variance',
            'payment_status', 'payment_type', 'notes', 'return_reason', 'refund_tender',
            'created_by', 'user_id', 'user_name', 'user_role', 'created_at', 'updated_at',
        ];
        $query = $noItems ? SaleReturn::select($columns) : SaleReturn::query();

        $accounting = app(PurchaseAccountingService::class);
        $allowedOutlets = $accounting->extractAllowedOutletIds($request);
        $accounting->applyOutletScope($query, $allowedOutlets, 'warehouse_id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->filled('customer') && $request->input('customer') !== 'All') {
            $query->where('customer_name', $request->input('customer'));
        }

        if ($request->filled('warehouse') && $request->input('warehouse') !== 'All') {
            $whInput = trim((string)$request->input('warehouse'));
            if (is_numeric($whInput)) {
                $query->where('warehouse_id', (int)$whInput);
            } else {
                $query->where('warehouse_name', $whInput);
            }
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int)$request->input('warehouse_id'));
        }

        if ($request->filled('status') && $request->input('status') !== 'All') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('payment_status') && $request->input('payment_status') !== 'All') {
            $query->where('payment_status', $request->input('payment_status'));
        }

        if ($request->filled('payment_type') && $request->input('payment_type') !== 'All') {
            $accounting->applyPaymentTypeFilter($query, (string)$request->input('payment_type'), 'payment_type', null, 'payments_payload');
        }

        if ($request->filled('return_reason') && $request->input('return_reason') !== 'All') {
            $query->where('return_reason', $request->input('return_reason'));
        }

        if ($request->filled('refund_tender') && $request->input('refund_tender') !== 'All') {
            $query->where('refund_tender', $request->input('refund_tender'));
        }

        $userFilter = $request->input('created_by') ?: $request->input('user');
        if (!empty($userFilter) && $userFilter !== 'All') {
            $cb = trim((string)$userFilter);
            $query->where(function ($q) use ($cb) {
                $q->where('created_by', $cb)
                  ->orWhere('created_by', 'like', $cb . ' (%')
                  ->orWhere('user_name', $cb);
            });
        }

        $fromDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
        $toDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
        $accounting->applyDateRangeFilter($query, $fromDate, $toDate, 'date');

        if ($request->filled('search')) {
            $search = trim((string)$request->input('search'));
            if (preg_match('/^(SR|SRN)[-_]/i', $search)) {
                $altHyphen = str_replace('_', '-', $search);
                $altUnderscore = str_replace('-', '_', $search);
                $query->where(function ($q) use ($search, $altHyphen, $altUnderscore) {
                    $q->where('reference_no', 'like', "{$search}%");
                    if ($altHyphen !== $search) {
                        $q->orWhere('reference_no', 'like', "{$altHyphen}%");
                    }
                    if ($altUnderscore !== $search) {
                        $q->orWhere('reference_no', 'like', "{$altUnderscore}%");
                    }
                });
            } else {
                $query->where(function ($q) use ($search) {
                    $q->where('reference_no', 'like', "%{$search}%")
                      ->orWhere('sale_reference_no', 'like', "%{$search}%")
                      ->orWhere('customer_name', 'like', "%{$search}%")
                      ->orWhere('warehouse_name', 'like', "%{$search}%")
                      ->orWhere('created_by', 'like', "%{$search}%")
                      ->orWhere('user_name', 'like', "%{$search}%")
                      ->orWhere('return_reason', 'like', "%{$search}%")
                      ->orWhere('items_payload', 'like', "%{$search}%")
                      ->orWhere('notes', 'like', "%{$search}%");
                });
            }
        }

        return $query;
    }

    public function index(Request $request)
    {
        $noItems = $request->boolean('no_items', false);
        $query = $this->buildFilteredQuery($request, $noItems);

        $userFilter = $request->input('created_by') ?: $request->input('user');
        $fromDate = $request->input('from_date') ?: ($request->input('date_from') ?: $request->input('start_date'));
        $toDate = $request->input('to_date') ?: ($request->input('date_to') ?: $request->input('end_date'));
        $hasFilters = $request->filled('customer_id')
            || ($request->filled('customer') && $request->input('customer') !== 'All')
            || ($request->filled('warehouse') && $request->input('warehouse') !== 'All')
            || $request->filled('warehouse_id')
            || ($request->filled('status') && $request->input('status') !== 'All')
            || ($request->filled('payment_status') && $request->input('payment_status') !== 'All')
            || ($request->filled('payment_type') && $request->input('payment_type') !== 'All')
            || ($request->filled('return_reason') && $request->input('return_reason') !== 'All')
            || ($request->filled('refund_tender') && $request->input('refund_tender') !== 'All')
            || (!empty($userFilter) && $userFilter !== 'All')
            || !empty($fromDate)
            || !empty($toDate)
            || $request->filled('search');

        $skipSummary = $request->boolean('skip_summary', false);
        $knownTotal = $request->filled('known_total') ? max(0, (int)$request->input('known_total')) : null;
        $accounting = app(PurchaseAccountingService::class);
        $canViewCost = $accounting->canViewCost($request);
        $isAll = $request->boolean('all') || $request->input('per_page') === 'all';

        $sortColumn = $request->input('sort_by', 'id');
        $sortDirection = strtolower((string)$request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumnMap = [
            'reference' => 'reference_no',
            'customer'  => 'customer_name',
            'warehouse' => 'warehouse_name',
            'amount'    => 'grand_total',
            'paid'      => 'paid_amount',
            'due'       => 'due_amount',
            'user'      => 'created_by',
            'sale_ref'  => 'sale_reference_no',
        ];
        if (isset($sortColumnMap[$sortColumn])) {
            $sortColumn = $sortColumnMap[$sortColumn];
        }
        $allowedSortColumns = [
            'id', 'reference_no', 'sale_reference_no', 'customer_name', 'warehouse_name',
            'date', 'grand_total', 'paid_amount', 'due_amount', 'status',
            'payment_status', 'created_by', 'updated_at',
        ];
        if (!in_array($sortColumn, $allowedSortColumns, true)) {
            $sortColumn = 'id';
        }

        $perPage = min(500, max(1, (int)$request->input('per_page', 50)));
        $page = max(1, (int)$request->input('page', 1));
        $cursorData = !$isAll
            ? ListCursorHelper::decode(
                (string)$request->input('cursor', ''),
                $sortColumn,
                $sortDirection,
                $allowedSortColumns,
                ['id'],
                ['grand_total', 'paid_amount', 'due_amount']
            )
            : null;
        $isScrollPage = ($page > 1 || $cursorData !== null);

        if ($skipSummary && $knownTotal !== null && $isScrollPage) {
            $totalRecords = $knownTotal;
            $totalGrandTotal = 0.0;
            $totalPaid = 0.0;
            $totalDue = 0.0;
        } else {
            $aggregatesQuery = (clone $query)->toBase();
            $aggregatesQuery->columns = null;
            $aggregatesQuery->orders = null;
            $aggregates = $aggregatesQuery->selectRaw("
                COUNT(*) as total_records,
                COALESCE(SUM(CASE WHEN status = 'Completed' THEN grand_total ELSE 0 END), 0) as total_grand_total,
                COALESCE(SUM(CASE WHEN status = 'Completed' THEN paid_amount ELSE 0 END), 0) as total_paid,
                COALESCE(SUM(CASE WHEN status = 'Completed' THEN due_amount ELSE 0 END), 0) as total_due
            ")->first();

            $totalRecords = (int)($aggregates->total_records ?? 0);
            $totalGrandTotal = (float)($aggregates->total_grand_total ?? 0);
            $totalPaid = (float)($aggregates->total_paid ?? 0);
            $totalDue = (float)($aggregates->total_due ?? 0);
        }

        if ($cursorData !== null) {
            ListCursorHelper::apply($query, $sortColumn, $sortDirection, $cursorData);
        }
        $query->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        $cacheControl = 'no-store, no-cache, must-revalidate, private';
        if ($isAll) {
            $limit = min(500, max(1, (int)$request->input('limit', 500)));
            $returns = $query->take($limit)->get();
            if ($noItems) {
                $returns->each->setAppends([]);
            }
            $returns->each(fn($ret) => $this->maskSaleReturnCostFields($ret, $canViewCost, !$noItems));
            return response()->json([
                'status'  => 'success',
                'data'    => $returns,
                'total'   => $totalRecords,
                'summary' => [
                    'total_records'     => $totalRecords,
                    'total_grand_total' => $totalGrandTotal,
                    'total_paid'        => $totalPaid,
                    'total_due'         => $totalDue,
                ],
                'pagination' => [
                    'current_page' => 1,
                    'per_page'     => $returns->count(),
                    'total'        => $totalRecords,
                    'last_page'    => (int)ceil($totalRecords / max(1, $limit)),
                ],
            ])->header('Cache-Control', $cacheControl);
        }

        $offset = $cursorData !== null ? 0 : (($page - 1) * $perPage);
        $fetched = $query->offset($offset)->limit($perPage + 1)->get();
        $hasMore = $fetched->count() > $perPage;
        $returnsItems = $hasMore ? $fetched->slice(0, $perPage)->values() : $fetched;
        $lastRowObj = $returnsItems->last();
        $nextCursor = ($hasMore && $lastRowObj !== null)
            ? ListCursorHelper::encode((object)$lastRowObj, $sortColumn, $sortDirection, $allowedSortColumns)
            : null;
        if ($noItems) {
            $returnsItems->each->setAppends([]);
        }
        $returnsItems->each(fn($ret) => $this->maskSaleReturnCostFields($ret, $canViewCost, !$noItems));
        $lastPage = max(1, (int)ceil($totalRecords / max(1, $perPage)));

        $pagination = [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total'        => $totalRecords,
            'last_page'    => $lastPage,
            'has_more'     => $hasMore,
        ];
        if ($nextCursor !== null) {
            $pagination['next_cursor'] = $nextCursor;
        }

        $payload = [
            'status'  => 'success',
            'data'    => $returnsItems->values(),
            'total'   => $totalRecords,
            'summary' => [
                'total_records'     => $totalRecords,
                'total_grand_total' => $totalGrandTotal,
                'total_paid'        => $totalPaid,
                'total_due'         => $totalDue,
            ],
            'pagination' => $pagination,
        ];

        return response()->json($payload)
            ->header('Cache-Control', $cacheControl);
    }

    public function show(Request $request, $id)
    {
        $return = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();

        if (!$return) {
            return response()->json(['status' => 'error', 'message' => 'Sale return record not found'], 404);
        }

        $accounting = app(PurchaseAccountingService::class);
        $accounting->assertOutletAccess($request, $return->warehouse_id, $return->warehouse_name);
        $canViewCost = $accounting->canViewCost($request);
        $this->maskSaleReturnCostFields($return, $canViewCost);

        return response()->json([
            'status' => 'success',
            'data'   => $return,
        ]);
    }

    protected function resolveActiveCashRegisterId(
        ?int $explicitRegId,
        ?int $warehouseId,
        ?string $warehouseName,
        ?int $ownerId,
        ?string $ownerName,
        bool $requireOpenIfExplicit = true
    ): ?int {
        if ($explicitRegId) {
            $reg = CashRegister::lockForUpdate()->find($explicitRegId);
            if (!$reg || !in_array(strtolower(trim((string)$reg->status)), ['open', 'opened'], true)) {
                if ($requireOpenIfExplicit) {
                    $this->throwValidationError('cash_register_id', 'Selected cash register is closed or does not exist. Please select an open cash register.');
                }
                return $explicitRegId;
            }
            if ($warehouseId && $reg->warehouse_id && (int)$reg->warehouse_id !== (int)$warehouseId) {
                $this->throwValidationError('cash_register_id', 'Selected cash register belongs to a different warehouse/branch than the sale return.');
            }
            if ($requireOpenIfExplicit) {
                $actor = User::resolveFromRequest(request()) ?? request()?->user() ?? ($ownerId ? User::find($ownerId) : null);
                $actorRole = strtolower(trim((string)($actor?->roleModel?->name ?? $actor?->role ?? '')));
                $isManagerActor = in_array($actorRole, ['super admin', 'admin', 'manager'], true)
                    || ($actor && method_exists($actor, 'isSuperAdmin') && $actor->isSuperAdmin());
                $effectiveOwnerId = $actor?->id ?: $ownerId;
                if (!$isManagerActor && $reg->user_id && $effectiveOwnerId && (int)$reg->user_id !== (int)$effectiveOwnerId) {
                    $this->throwValidationError('cash_register_id', "You cannot post transactions to another staff member's open cash register.");
                }
            }
            return (int)$reg->id;
        }

        if (!$warehouseId && empty($warehouseName)) {
            return null;
        }

        $query = CashRegister::whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened'])
            ->when($warehouseId, fn($q) => $q->where('warehouse_id', $warehouseId), fn($q) => $q->where('warehouse_name', $warehouseName));

        if ($ownerId || $ownerName) {
            $owned = (clone $query)->where(function ($q) use ($ownerId, $ownerName) {
                if ($ownerId) $q->where('user_id', $ownerId);
                if ($ownerName) $q->orWhere('user_name', $ownerName);
            })->orderByDesc('id')->lockForUpdate()->first();
            if ($owned) {
                return (int)$owned->id;
            }
        }

        // Strict shift isolation: never silently attach another cashier's register
        return null;
    }

    protected function computeReturnMonetaryTotals(
        array $validated,
        array $preparedData,
        Sale $linkedSale,
        string $targetStatus,
        ?SaleReturn $existingReturn = null
    ): array {
        $computedSubtotal = (float)$preparedData['subtotal'];
        $returnTaxExclBase = (float)$preparedData['tax_exclusive_base'];
        $saleTaxExclBase = (float)$preparedData['sale_tax_exclusive_base'];
        $ratio = $saleTaxExclBase > 0.0001 ? min(1.0, $returnTaxExclBase / $saleTaxExclBase) : 1.0;

        $orderDiscount = array_key_exists('discount', $validated)
            ? round(min(max(0.0, (float)$validated['discount']), $computedSubtotal), 2)
            : round(min($computedSubtotal, (float)$linkedSale->discount * $ratio), 2);

        $shipping = max(0.0, (float)($validated['shipping'] ?? 0));
        $taxRate = array_key_exists('tax_rate', $validated)
            ? max(0.0, (float)$validated['tax_rate'])
            : max(0.0, (float)$linkedSale->tax_rate);

        $taxableBase = max(0.0, $returnTaxExclBase - $orderDiscount);
        if ($taxRate > 0) {
            $orderTaxAmount = round($taxableBase * ($taxRate / 100.0), 2);
        } elseif (array_key_exists('tax_amount', $validated)) {
            $orderTaxAmount = round(max(0.0, (float)$validated['tax_amount']), 2);
        } else {
            $orderTaxAmount = round((float)$linkedSale->tax_amount * $ratio, 2);
        }

        $grandTotal = max(0.0, round($computedSubtotal - $orderDiscount + $orderTaxAmount + $shipping, 2));
        $priorGoodsTotal = (float)$preparedData['prior_returned_grand_total'];
        $currentGoodsTotal = max(0.0, round($grandTotal - $shipping, 2));
        $saleGoodsMax = max((float)$linkedSale->grand_total, round((float)$linkedSale->grand_total - (float)$linkedSale->shipping, 2));
        if ($priorGoodsTotal + $currentGoodsTotal > (float)$linkedSale->grand_total + 0.05 || $currentGoodsTotal > $saleGoodsMax + 0.05) {
            $this->throwValidationError(
                'grand_total',
                "Total return value exceeds linked Sale #{$linkedSale->reference_no} grand total."
            );
        }

        // AR Credit-First Offsetting against unpaid due on linked Sale
        $appliedCredit = 0.0;
        if ($targetStatus === 'Completed') {
            $prevSelfCredit = ($existingReturn && $existingReturn->status === 'Completed')
                ? (float)$existingReturn->applied_credit_amount
                : 0.0;
            $availableCreditHeadroom = max(0.0, (float)$linkedSale->due_amount + $prevSelfCredit);
            $appliedCredit = round(min($grandTotal, $availableCreditHeadroom), 2);
        }

        $netCashRefundable = max(0.0, round($grandTotal - $appliedCredit, 2));

        // Payment / Refund validation (Pending returns remain strictly unposted until Completed)
        if ($targetStatus !== 'Completed') {
            $paidAmount = 0.0;
        } else {
            $paymentsInput = $validated['payments'] ?? null;
            if (is_array($paymentsInput) && !empty($paymentsInput)) {
                $paymentsSum = round(array_sum(array_map(fn($p) => max(0.0, (float)($p['amount'] ?? 0)), $paymentsInput)), 2);
                if ($paymentsSum > $netCashRefundable + 0.01) {
                    $this->throwValidationError(
                        'payments',
                        "Refund payments total ({$paymentsSum}) exceeds net cash/bank refundable amount ({$netCashRefundable}) after AR credit offset ({$appliedCredit})."
                    );
                }
                if (array_key_exists('paid_amount', $validated) && abs($paymentsSum - (float)$validated['paid_amount']) > 0.01 && abs($paymentsSum - min($netCashRefundable, (float)$validated['paid_amount'])) > 0.01) {
                    $this->throwValidationError('payments', 'Payments array total does not match paid_amount.');
                }
                $paidAmount = min($netCashRefundable, $paymentsSum);
            } else {
                $explicitPayStatus = $validated['payment_status'] ?? null;
                if ($explicitPayStatus === 'Unpaid') {
                    $paidAmount = 0.0;
                } elseif (array_key_exists('paid_amount', $validated)) {
                    $requestedPaid = round(max(0.0, (float)$validated['paid_amount']), 2);
                    if ($requestedPaid > $grandTotal + 0.01) {
                        $this->throwValidationError('paid_amount', 'Refund paid amount cannot exceed return grand total.');
                    }
                    $paidAmount = min($netCashRefundable, $requestedPaid);
                } elseif ($explicitPayStatus === 'Partial') {
                    $paidAmount = round($netCashRefundable * 0.5, 2);
                } else {
                    $paidAmount = $netCashRefundable;
                }
            }
        }

        $dueAmount = max(0.0, round($netCashRefundable - $paidAmount, 2));
        $paymentStatus = $dueAmount <= 0.001 ? 'Paid' : ($paidAmount > 0.001 ? 'Partial' : 'Unpaid');

        return [
            'subtotal'              => $computedSubtotal,
            'discount'              => $orderDiscount,
            'tax_rate'              => $taxRate,
            'tax_amount'            => $orderTaxAmount,
            'shipping'              => $shipping,
            'grand_total'           => $grandTotal,
            'applied_credit_amount' => $appliedCredit,
            'net_cash_refundable'   => $netCashRefundable,
            'paid_amount'           => round($paidAmount, 2),
            'due_amount'            => $dueAmount,
            'payment_status'        => $paymentStatus,
        ];
    }

    protected function validatePaymentLegRegisters(
        ?array $rawPayments,
        int $warehouseId,
        string $warehouseName,
        ?int $userId,
        ?string $userName
    ): void {
        if (!is_array($rawPayments) || empty($rawPayments)) {
            return;
        }
        foreach ($rawPayments as $p) {
            if (is_array($p) && !empty($p['cash_register_id'])) {
                $this->resolveActiveCashRegisterId(
                    (int)$p['cash_register_id'],
                    $warehouseId,
                    $warehouseName,
                    $userId,
                    $userName,
                    true
                );
            }
        }
    }

    protected function buildReturnPaymentsPayload(
        ?array $rawPayments,
        float $paidAmount,
        string $refundTender,
        string $cleanRef,
        int $returnId,
        ?int $defaultRegisterId,
        string $txDate,
        string $userName,
        string $userRole,
        ?string $notes
    ): array {
        $savedPayments = [];
        $tenderIdx = 1;
        $uniqueTypes = [];

        if (is_array($rawPayments) && !empty($rawPayments)) {
            $runningRemaining = $paidAmount;
            foreach ($rawPayments as $p) {
                $rawAmt = round(max(0.0, (float)($p['amount'] ?? 0)), 2);
                if ($rawAmt <= 0 || $runningRemaining <= 0.001) continue;
                $netAmt = round(min($runningRemaining, $rawAmt), 2);
                $runningRemaining = max(0.0, round($runningRemaining - $netAmt, 2));
                $pType = trim((string)($p['payment_type'] ?? $refundTender ?: 'Cash'));
                $uniqueTypes[$pType] = true;
                $legRegId = isset($p['cash_register_id']) && $p['cash_register_id'] !== ''
                    ? (int)$p['cash_register_id']
                    : $defaultRegisterId;
                $savedPayments[] = [
                    'id'               => $tenderIdx,
                    'reference_no'     => !empty($p['reference_no']) ? (string)$p['reference_no'] : ('PAY-' . ($cleanRef ?: $returnId) . '-' . date('YmdHis') . '-' . Str::upper(Str::random(4)) . '-' . $tenderIdx),
                    'payment_date'     => !empty($p['payment_date']) ? $p['payment_date'] : $txDate,
                    'payment_type'     => $pType,
                    'amount'           => $netAmt,
                    'received_amount'  => isset($p['received_amount']) ? (float)$p['received_amount'] : $rawAmt,
                    'change_amount'    => isset($p['change_amount']) ? (float)$p['change_amount'] : 0.0,
                    'cash_register_id' => $legRegId,
                    'user_name'        => $p['user_name'] ?? $userName,
                    'user_role'        => $p['user_role'] ?? $userRole,
                    'notes'            => $p['notes'] ?? ($notes ?? ''),
                ];
                $tenderIdx++;
            }
        } elseif ($paidAmount > 0.001) {
            $pType = $refundTender ?: 'Cash';
            $uniqueTypes[$pType] = true;
            $savedPayments[] = [
                'id'               => 1,
                'reference_no'     => 'PAY-' . ($cleanRef ?: $returnId) . '-' . date('YmdHis') . '-' . Str::upper(Str::random(4)),
                'payment_date'     => $txDate,
                'payment_type'     => $pType,
                'amount'           => $paidAmount,
                'received_amount'  => $paidAmount,
                'change_amount'    => 0.0,
                'cash_register_id' => $defaultRegisterId,
                'user_name'        => $userName,
                'user_role'        => $userRole,
                'notes'            => $notes ?? '',
            ];
        }

        $typeKeys = array_keys($uniqueTypes);
        $resolvedType = count($typeKeys) > 1
            ? 'Split Payment'
            : (count($typeKeys) === 1 ? $typeKeys[0] : ($refundTender ?: 'Cash'));

        return [
            'payments'     => $savedPayments,
            'payment_type' => $resolvedType,
        ];
    }

    protected function applyCompletedReturnEffects(
        SaleReturn $return,
        Sale $linkedSale,
        array $preparedItems,
        int $warehouseId,
        FifoInventoryService $fifoService,
        ProductUnitConversionService $unitService
    ): array {
        $totalReturnCost = 0.0;
        $totalCostVariance = 0.0;
        $touchedProductIds = [];
        $touchedVariantIds = [];
        $storedItems = [];

        foreach ($preparedItems as $it) {
            /** @var Product $product */
            $product = $it['_product'];
            unset($it['_product']);
            $variantId = $it['product_variant_id'] ? (int)$it['product_variant_id'] : null;
            $baseQty = (float)$it['base_quantity'];
            $convRate = (float)$it['conversion_rate'];

            $varObj = ($variantId && $product->relationLoaded('variants'))
                ? $product->variants->firstWhere('id', $variantId)
                : null;
            $fallbackBaseCost = (float)($varObj?->cost_price ?: ($varObj?->purchase_price ?: ($product->cost_price ?: ($product->purchase_price ?: 0.0))));

            $unitService->applyStock(
                product:        $product,
                warehouseId:    $warehouseId,
                variantId:      $variantId,
                baseQuantity:   $baseQty,
                direction:      'in',
                movementType:   'sale_return',
                referenceType:  'sale_return',
                referenceId:    (string)$return->id,
                note:           'Sale return #' . $return->reference_no,
                notifyRestock:  true,
                allowOverdraft: false,
                syncAggregates: false,
                unitCost:       $fallbackBaseCost > 0 ? $fallbackBaseCost : null,
                movementDate:   (string)($return->date ?? now())
            );

            $fifoRes = $fifoService->restoreForSaleReturn(
                warehouseId:         $warehouseId,
                productId:           (int)$product->id,
                variantId:           $variantId,
                baseQty:             $baseQty,
                saleReturn:          $return,
                linkedSale:          $linkedSale,
                productFallbackCost: $fallbackBaseCost
            );

            $avgBaseCost = (float)$fifoRes['avg_unit_cost'];
            if ($avgBaseCost > 0 && Schema::hasColumn('stock_movements', 'unit_cost')) {
                StockMovement::where('warehouse_id', $warehouseId)
                    ->where('product_id', (int)$product->id)
                    ->when(
                        $variantId !== null && $variantId > 0,
                        fn($q) => $q->where('product_variant_id', $variantId),
                        fn($q) => $q->whereNull('product_variant_id')
                    )
                    ->where('movement_type', 'sale_return')
                    ->where('reference_type', 'sale_return')
                    ->where('reference_id', (string)$return->id)
                    ->orderByDesc('id')
                    ->limit(1)
                    ->update(['unit_cost' => round($avgBaseCost, 4)]);
            }
            $it['base_unit_cost'] = $avgBaseCost;
            $it['unit_cost'] = round($avgBaseCost * $convRate, 4);
            $it['total_cost'] = round((float)$fifoRes['total_cost'], 4);
            $storedItems[] = $it;

            $totalReturnCost = round($totalReturnCost + (float)$fifoRes['total_cost'], 4);
            $totalCostVariance = round($totalCostVariance + (float)$fifoRes['cost_variance'], 4);
            $touchedProductIds[] = (int)$product->id;
            if ($variantId) {
                $touchedVariantIds[] = $variantId;
            }
        }

        if (!empty($touchedProductIds) || !empty($touchedVariantIds)) {
            $unitService->syncAggregatesForProducts($touchedProductIds, $touchedVariantIds);
        }

        if ($return->customer_id) {
            $cust = Customer::where('id', $return->customer_id)->lockForUpdate()->first();
            if ($cust) {
                $cust->update([
                    'total_spent' => max(0.0, round((float)$cust->total_spent - (float)$return->grand_total, 2)),
                ]);
            }
        }

        return [
            'items'             => $storedItems,
            'return_cost_total' => $totalReturnCost,
            'cost_variance'     => $totalCostVariance,
        ];
    }

    protected function reverseCompletedReturnEffects(
        SaleReturn $return,
        ?int $warehouseId,
        FifoInventoryService $fifoService,
        ProductUnitConversionService $unitService,
        string $movementType = 'sale_return_void'
    ): void {
        if ($blockingMsg = $fifoService->assertSaleReturnBatchesDeletable($return)) {
            $this->throwValidationError('status', $blockingMsg);
        }

        $items = $return->items;
        $resolvedWhId = $warehouseId ?: $unitService->warehouseId($return->warehouse_id, $return->warehouse_name);
        if (!empty($items) && is_array($items) && $resolvedWhId) {
            $prodIds = array_values(array_unique(array_filter(array_column($items, 'product_id'))));
            sort($prodIds);
            $productsMap = !empty($prodIds)
                ? Product::whereIn('id', $prodIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
                : collect();

            $touchedProductIds = [];
            $touchedVariantIds = [];
            foreach ($items as $it) {
                $pId = !empty($it['product_id']) ? (int)$it['product_id'] : null;
                $product = $pId ? $productsMap->get($pId) : (!empty($it['product_code']) ? Product::where('code', $it['product_code'])->lockForUpdate()->first() : null);
                if (!$product) {
                    continue;
                }
                $variantId = !empty($it['product_variant_id']) ? (int)$it['product_variant_id'] : null;
                $convRate = max(0.0001, (float)($it['conversion_rate'] ?? 1.0));
                $qty = (float)($it['quantity'] ?? 0);
                $baseQty = isset($it['base_quantity']) && (float)$it['base_quantity'] > 0
                    ? (float)$it['base_quantity']
                    : $unitService->baseQuantity($qty, $convRate);
                if ($baseQty > 0.0001) {
                    $revCost = isset($it['base_unit_cost']) && (float)$it['base_unit_cost'] > 0
                        ? (float)$it['base_unit_cost']
                        : null;
                    $unitService->applyStock(
                        product:        $product,
                        warehouseId:    $resolvedWhId,
                        variantId:      $variantId,
                        baseQuantity:   $baseQty,
                        direction:      'out',
                        movementType:   $movementType,
                        referenceType:  'sale_return',
                        referenceId:    (string)$return->id,
                        note:           'Sale return reversed #' . $return->reference_no,
                        notifyRestock:  false,
                        allowOverdraft: false,
                        syncAggregates: false,
                        unitCost:       $revCost,
                        movementDate:   (string)($return->date ?? now())
                    );
                    $touchedProductIds[] = (int)$product->id;
                    if ($variantId) {
                        $touchedVariantIds[] = $variantId;
                    }
                }
            }

            if (!empty($touchedProductIds) || !empty($touchedVariantIds)) {
                $unitService->syncAggregatesForProducts($touchedProductIds, $touchedVariantIds);
            }
        }

        $fifoService->rollbackSaleReturnFifo($return);

        if ($return->customer_id) {
            $cust = Customer::where('id', $return->customer_id)->lockForUpdate()->first();
            if ($cust) {
                $cust->update([
                    'total_spent' => round((float)$cust->total_spent + (float)$return->grand_total, 2),
                ]);
            }
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference_no'               => 'nullable|string|max:100|unique:sale_returns,reference_no',
            'sale_id'                    => 'nullable|integer',
            'sale_reference_no'          => 'required_without:sale_id|nullable|string|max:100',
            'customer_id'                => 'nullable|integer',
            'customer_name'              => 'nullable|string|max:255',
            'warehouse_id'               => 'nullable|integer',
            'warehouse_name'             => 'nullable|string|max:255',
            'cash_register_id'           => 'nullable|integer',
            'date'                       => 'required|string',
            'tax_rate'                   => 'nullable|numeric|min:0',
            'tax_amount'                 => 'nullable|numeric|min:0',
            'discount'                   => 'nullable|numeric|min:0',
            'shipping'                   => 'nullable|numeric|min:0',
            'subtotal'                   => 'nullable|numeric|min:0',
            'grand_total'                => 'nullable|numeric|min:0',
            'paid_amount'                => 'nullable|numeric|min:0',
            'due_amount'                 => 'nullable|numeric|min:0',
            'payment_status'             => 'nullable|string|in:Paid,Partial,Unpaid,Pending',
            'payment_type'               => 'nullable|string|max:100',
            'status'                     => 'nullable|string|in:Completed,Received,Pending',
            'notes'                      => 'nullable|string',
            'return_reason'              => 'nullable|string|max:255',
            'refund_tender'              => 'nullable|string|max:100',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.variant_name'       => 'nullable|string|max:255',
            'items.*.product_code'       => 'nullable|string|max:100',
            'items.*.product_name'       => 'nullable|string|max:255',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|gt:0',
            'items.*.quantity'           => 'required|numeric|gt:0',
            'items.*.unit_price'         => 'nullable|numeric|min:0',
            'items.*.discount'           => 'nullable|numeric|min:0',
            'items.*.tax'                => 'nullable|numeric|min:0',
            'payments'                   => 'nullable|array',
            'payments.*.amount'          => 'nullable|numeric|gt:0',
            'payments.*.payment_type'    => 'nullable|string|max:100',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount'   => 'nullable|numeric|min:0',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.cash_register_id'=> 'nullable|integer',
            'payments.*.notes'           => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:100',
        ]);

        DB::beginTransaction();
        try {
            $unitService = app(ProductUnitConversionService::class);
            $fifoService = app(FifoInventoryService::class);
            $accountingService = app(PurchaseAccountingService::class);

            $linkedSale = $this->resolveLinkedSale(
                isset($validated['sale_id']) ? (int)$validated['sale_id'] : null,
                $validated['sale_reference_no'] ?? null,
                true
            );
            if (!$linkedSale) {
                $this->throwValidationError('sale_reference_no', 'Original Sale invoice not found. Every Sale Return must link to an existing Completed Sale.');
            }
            $accountingService->assertOutletAccess($request, $linkedSale->warehouse_id, $linkedSale->warehouse_name);
            if ($linkedSale->status !== 'Completed') {
                $this->throwValidationError('sale_reference_no', "Only Completed sales can be returned. Sale #{$linkedSale->reference_no} is currently '{$linkedSale->status}'.");
            }

            if (!empty($linkedSale->date)) {
                $saleDate = \Carbon\Carbon::parse($linkedSale->date);
                $returnWindowDays = (int)Setting::get('return_window_days', 30);
                if ($returnWindowDays > 0 && $saleDate->diffInDays(now()) > $returnWindowDays) {
                    $this->throwValidationError(
                        'date',
                        "Return window exceeded. Returns are only accepted within {$returnWindowDays} days of the sale date (Sale date: {$saleDate->toDateString()})."
                    );
                }
            }

            if (!empty($validated['customer_id']) && $linkedSale->customer_id && (int)$validated['customer_id'] !== (int)$linkedSale->customer_id) {
                $this->throwValidationError('customer_id', "Selected customer does not match the linked Sale #{$linkedSale->reference_no} customer.");
            }
            $customerId = $validated['customer_id'] ?? $linkedSale->customer_id;
            $customerName = !empty($validated['customer_name']) ? $validated['customer_name'] : ($linkedSale->customer_name ?: 'Walk-in Customer');

            $warehouseId = $unitService->warehouseId(
                $validated['warehouse_id'] ?? $linkedSale->warehouse_id,
                $validated['warehouse_name'] ?? $linkedSale->warehouse_name
            );
            if ($warehouseId === null && Outlet::query()->exists()) {
                $this->throwValidationError('warehouse_id', 'Selected warehouse / outlet does not exist.');
            }
            if ($linkedSale->warehouse_id && $warehouseId && (int)$warehouseId !== (int)$linkedSale->warehouse_id) {
                $this->throwValidationError('warehouse_id', "Return warehouse must match linked Sale #{$linkedSale->reference_no} warehouse ({$linkedSale->warehouse_name}).");
            }
            $warehouseName = !empty($validated['warehouse_name']) ? $validated['warehouse_name'] : ($linkedSale->warehouse_name ?: 'Main Warehouse');

            $targetStatus = $this->normalizeStatus($validated['status'] ?? 'Completed');
            if ($targetStatus === 'Voided') {
                $this->throwValidationError('status', 'A new sale return cannot be created directly with Voided status.');
            }

            $preparedData = $this->validateAndPrepareReturnItems($validated['items'], $linkedSale, null, $unitService);
            $totals = $this->computeReturnMonetaryTotals($validated, $preparedData, $linkedSale, $targetStatus);

            $txDate = trim($validated['date']);
            if (!str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $authUser = User::resolveFromRequest($request) ?? $request->user();
            if ($authUser !== null
                && !(method_exists($authUser, 'isSuperAdmin') && $authUser->isSuperAdmin())
                && $totals['paid_amount'] > 0.001
                && !$authUser->hasPermission('sale_returns.pay')
                && !$authUser->hasPermission('sales.refund')
                && !$accountingService->verifyManagerPin($request->input('manager_pin'))
            ) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Issuing a sale return refund requires sale_returns.pay, sales.refund, or Manager PIN.',
                ], 403);
            }
            $userId = $validated['user_id'] ?? $authUser?->id;
            $userName = !empty($validated['user_name']) ? $validated['user_name'] : ($authUser?->name ?? 'Super Admin');
            $userRole = !empty($validated['user_role']) ? $validated['user_role'] : ($authUser?->role ?? 'Super Admin');
            $createdBy = !empty($validated['created_by']) ? $validated['created_by'] : ($userName . ' (' . $userRole . ')');

            $refundTender = $validated['refund_tender'] ?? $validated['payment_type'] ?? 'Cash';
            $cashRegisterId = $this->resolveActiveCashRegisterId(
                !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null,
                $warehouseId,
                $warehouseName,
                $userId,
                $userName
            );

            $refNo = $this->acquireUniqueReference('sale_return', $validated['reference_no'] ?? null);
            $cleanRef = preg_replace('/^#\s*/', '', trim((string)$refNo));

            $return = SaleReturn::create([
                'reference_no'          => $refNo,
                'sale_id'               => $linkedSale->id,
                'sale_reference_no'     => $linkedSale->reference_no,
                'customer_id'           => $customerId,
                'customer_name'         => $customerName,
                'warehouse_id'          => $warehouseId,
                'warehouse_name'        => $warehouseName,
                'cash_register_id'      => $cashRegisterId,
                'date'                  => $txDate,
                'status'                => $targetStatus,
                'tax_rate'              => $totals['tax_rate'],
                'tax_amount'            => $totals['tax_amount'],
                'discount'              => $totals['discount'],
                'shipping'              => $totals['shipping'],
                'subtotal'              => $totals['subtotal'],
                'grand_total'           => $totals['grand_total'],
                'paid_amount'           => $totals['paid_amount'],
                'due_amount'            => $totals['due_amount'],
                'applied_credit_amount' => $totals['applied_credit_amount'],
                'payment_status'        => $totals['payment_status'],
                'payment_type'          => $validated['payment_type'] ?? $refundTender,
                'notes'                 => $validated['notes'] ?? null,
                'return_reason'         => $validated['return_reason'] ?? null,
                'refund_tender'         => $refundTender,
                'created_by'            => $createdBy,
                'user_id'               => $userId,
                'user_name'             => $userName,
                'user_role'             => $userRole,
            ]);

            if ($targetStatus === 'Completed' && !empty($validated['payments'])) {
                $this->validatePaymentLegRegisters($validated['payments'], (int)$warehouseId, $warehouseName, $userId, $userName);
            }

            $paymentsMeta = $this->buildReturnPaymentsPayload(
                $targetStatus === 'Completed' ? ($validated['payments'] ?? null) : [],
                $totals['paid_amount'],
                $refundTender,
                $cleanRef,
                (int)$return->id,
                $cashRegisterId,
                $txDate,
                $userName,
                $userRole,
                $validated['notes'] ?? null
            );

            $cleanItems = array_map(static function ($it) {
                unset($it['_product']);
                return $it;
            }, $preparedData['items']);

            $returnCostTotal = 0.0;
            $costVariance = 0.0;

            if ($targetStatus === 'Completed') {
                if (!$warehouseId) {
                    $this->throwValidationError('warehouse_id', 'A valid warehouse is required to complete a sale return.');
                }
                $stockEffects = $this->applyCompletedReturnEffects(
                    $return,
                    $linkedSale,
                    $preparedData['items'],
                    (int)$warehouseId,
                    $fifoService,
                    $unitService
                );
                $cleanItems = $stockEffects['items'];
                $returnCostTotal = $stockEffects['return_cost_total'];
                $costVariance = $stockEffects['cost_variance'];

                $return->update([
                    'items_payload'     => json_encode($cleanItems),
                    'payments_payload'  => json_encode($paymentsMeta['payments']),
                    'payment_type'      => $paymentsMeta['payment_type'],
                    'return_cost_total' => $returnCostTotal,
                    'cost_variance'     => $costVariance,
                ]);

                $this->syncLinkedSaleReturnCredit($linkedSale);

                $cashByReg = $accountingService->extractDocumentCashByRegister($return, ['Completed']);
                foreach ($cashByReg as $regId => $cashAmt) {
                    $accountingService->adjustOpenRegisterExpectedCash((int)$regId, -round((float)$cashAmt, 2));
                }
            } else {
                $return->update([
                    'items_payload'    => json_encode($cleanItems),
                    'payments_payload' => json_encode($paymentsMeta['payments']),
                    'payment_type'     => $paymentsMeta['payment_type'],
                ]);
            }

            DB::commit();
            $this->clearSaleReturnCaches();

            $fresh = $return->fresh();
            $this->maskSaleReturnCostFields($fresh, $accountingService->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Sale return recorded successfully',
                'data'    => $fresh,
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            $code = (str_contains($e->getMessage(), 'Insufficient stock') || str_contains($e->getMessage(), 'Cannot')) ? 422 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function update(Request $request, $id)
    {
        $returnPre = is_numeric($id)
            ? SaleReturn::find($id)
            : SaleReturn::where('reference_no', $id)->first();

        if (!$returnPre) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        app(PurchaseAccountingService::class)->assertOutletAccess($request, $returnPre->warehouse_id, $returnPre->warehouse_name);

        $validated = $request->validate([
            'reference_no'               => ['nullable', 'string', 'max:100', Rule::unique('sale_returns', 'reference_no')->ignore($returnPre->id)],
            'sale_id'                    => 'nullable|integer',
            'sale_reference_no'          => 'nullable|string|max:100',
            'customer_id'                => 'nullable|integer',
            'customer_name'              => 'nullable|string|max:255',
            'warehouse_id'               => 'nullable|integer',
            'warehouse_name'             => 'nullable|string|max:255',
            'cash_register_id'           => 'nullable|integer',
            'date'                       => 'nullable|string',
            'tax_rate'                   => 'nullable|numeric|min:0',
            'tax_amount'                 => 'nullable|numeric|min:0',
            'discount'                   => 'nullable|numeric|min:0',
            'shipping'                   => 'nullable|numeric|min:0',
            'subtotal'                   => 'nullable|numeric|min:0',
            'grand_total'                => 'nullable|numeric|min:0',
            'paid_amount'                => 'nullable|numeric|min:0',
            'due_amount'                 => 'nullable|numeric|min:0',
            'payment_status'             => 'nullable|string|in:Paid,Partial,Unpaid,Pending',
            'payment_type'               => 'nullable|string|max:100',
            'status'                     => 'nullable|string|in:Completed,Received,Pending,Voided',
            'notes'                      => 'nullable|string',
            'return_reason'              => 'nullable|string|max:255',
            'refund_tender'              => 'nullable|string|max:100',
            'items'                      => 'nullable|array|min:1',
            'items.*.product_id'         => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.variant_name'       => 'nullable|string|max:255',
            'items.*.product_code'       => 'nullable|string|max:100',
            'items.*.product_name'       => 'nullable|string|max:255',
            'items.*.unit_id'            => 'nullable|integer',
            'items.*.unit_name'          => 'nullable|string|max:100',
            'items.*.conversion_rate'    => 'nullable|numeric|gt:0',
            'items.*.quantity'           => 'required_with:items|numeric|gt:0',
            'items.*.unit_price'         => 'nullable|numeric|min:0',
            'items.*.discount'           => 'nullable|numeric|min:0',
            'items.*.tax'                => 'nullable|numeric|min:0',
            'payments'                   => 'nullable|array',
            'payments.*.amount'          => 'nullable|numeric|gt:0',
            'payments.*.payment_type'    => 'nullable|string|max:100',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount'   => 'nullable|numeric|min:0',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.cash_register_id'=> 'nullable|integer',
            'payments.*.notes'           => 'nullable|string',
            'created_by'                 => 'nullable|string|max:255',
            'user_id'                    => 'nullable|integer',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:100',
        ]);

        DB::beginTransaction();
        try {
            $return = SaleReturn::lockForUpdate()->find($returnPre->id);
            if (!$return) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
            }
            if ($return->status === 'Voided') {
                $this->throwValidationError('status', 'Voided sale returns cannot be edited.');
            }

            $unitService = app(ProductUnitConversionService::class);
            $fifoService = app(FifoInventoryService::class);
            $accountingService = app(PurchaseAccountingService::class);

            $prevStatus = $this->normalizeStatus($return->status);
            $targetStatus = isset($validated['status']) ? $this->normalizeStatus($validated['status']) : $prevStatus;
            $prevWarehouseId = $return->warehouse_id ?: $unitService->warehouseId(null, $return->warehouse_name);

            $beforeCashByReg = $prevStatus === 'Completed'
                ? $accountingService->extractDocumentCashByRegister($return, ['Completed'])
                : [];

            // Linked Sale resolution
            $linkedSale = $this->resolveLinkedSale(
                isset($validated['sale_id']) ? (int)$validated['sale_id'] : ($return->sale_id ? (int)$return->sale_id : null),
                $validated['sale_reference_no'] ?? $return->sale_reference_no,
                true
            );
            if (!$linkedSale) {
                $this->throwValidationError('sale_reference_no', 'Original Sale invoice not found.');
            }
            if ($linkedSale->status !== 'Completed') {
                $this->throwValidationError('sale_reference_no', "Linked Sale #{$linkedSale->reference_no} is '{$linkedSale->status}' and cannot have active returns.");
            }

            // Reversing previous completed inventory & customer effects cleanly before recalculation
            if ($prevStatus === 'Completed') {
                $this->reverseCompletedReturnEffects($return, $prevWarehouseId, $fifoService, $unitService, 'sale_return_edit_reverse');
            }

            $warehouseId = $unitService->warehouseId(
                $validated['warehouse_id'] ?? $return->warehouse_id ?? $linkedSale->warehouse_id,
                $validated['warehouse_name'] ?? $return->warehouse_name ?? $linkedSale->warehouse_name
            );
            if ($warehouseId === null && Outlet::query()->exists()) {
                $this->throwValidationError('warehouse_id', 'Selected warehouse / outlet does not exist.');
            }
            if ($linkedSale->warehouse_id && $warehouseId && (int)$warehouseId !== (int)$linkedSale->warehouse_id) {
                $this->throwValidationError('warehouse_id', "Return warehouse must match linked Sale #{$linkedSale->reference_no} warehouse.");
            }
            $warehouseName = $validated['warehouse_name'] ?? $return->warehouse_name;

            $rawItems = isset($validated['items']) ? $validated['items'] : $return->items;
            if (empty($rawItems) || !is_array($rawItems)) {
                $this->throwValidationError('items', 'Sale return must include at least one item.');
            }

            if ($targetStatus === 'Voided') {
                $afterCashByReg = [];
                if ($errMsg = $accountingService->assertCashRegisterDeltaMutable($beforeCashByReg, $afterCashByReg)) {
                    $this->throwValidationError('cash_register_id', $errMsg);
                }

                $return->update([
                    'status'                => 'Voided',
                    'paid_amount'           => 0.0,
                    'due_amount'            => 0.0,
                    'applied_credit_amount' => 0.0,
                    'return_cost_total'     => 0.0,
                    'cost_variance'         => 0.0,
                    'notes'                 => $validated['notes'] ?? $return->notes,
                ]);
                $this->syncLinkedSaleReturnCredit($linkedSale);

                foreach ($beforeCashByReg as $regId => $oldCash) {
                    $accountingService->adjustOpenRegisterExpectedCash((int)$regId, round((float)$oldCash, 2));
                }

                DB::commit();
                $this->clearSaleReturnCaches();
                $fresh = $return->fresh();
                $this->maskSaleReturnCostFields($fresh, $accountingService->canViewCost($request));
                return response()->json(['status' => 'success', 'message' => 'Sale return voided successfully', 'data' => $fresh]);
            }

            // Prepare & compute monetary totals
            $preparedData = $this->validateAndPrepareReturnItems($rawItems, $linkedSale, (int)$return->id, $unitService);
            $effectiveInput = array_merge([
                'discount'       => $return->discount,
                'shipping'       => $return->shipping,
                'tax_rate'       => $return->tax_rate,
                'tax_amount'     => $return->tax_amount,
                'paid_amount'    => $return->paid_amount,
                'payment_status' => $return->payment_status,
            ], $validated);
            if (isset($validated['items']) && !array_key_exists('discount', $validated)) {
                unset($effectiveInput['discount']);
            }
            $totals = $this->computeReturnMonetaryTotals($effectiveInput, $preparedData, $linkedSale, $targetStatus, $return);

            $authUser = User::resolveFromRequest($request) ?? $request->user();
            $isIncreasingRefund = ($totals['paid_amount'] > (float)$return->paid_amount + 0.001)
                || (array_key_exists('payments', $validated) && !empty($validated['payments']) && $totals['paid_amount'] > 0.001);
            if ($isIncreasingRefund
                && $authUser !== null
                && !(method_exists($authUser, 'isSuperAdmin') && $authUser->isSuperAdmin())
                && !$authUser->hasPermission('sale_returns.pay')
                && !$authUser->hasPermission('sales.refund')
                && !$accountingService->verifyManagerPin($request->input('manager_pin'))
            ) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Issuing or modifying sale return refunds requires sale_returns.pay, sales.refund, or Manager PIN.',
                ], 403);
            }

            $txDate = trim((string)($validated['date'] ?? $return->date));
            if ($txDate !== '' && !str_contains($txDate, ':')) {
                $txDate .= ' ' . now()->format('H:i:s');
            }

            $refundTender = $validated['refund_tender'] ?? ($validated['payment_type'] ?? $return->refund_tender ?: 'Cash');
            $targetRegId = $this->resolveActiveCashRegisterId(
                isset($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : $return->cash_register_id,
                $warehouseId,
                $warehouseName,
                $return->user_id,
                $return->user_name,
                false
            );

            $cleanRef = preg_replace('/^#\s*/', '', trim((string)($validated['reference_no'] ?? $return->reference_no)));
            if ($targetStatus === 'Completed' && array_key_exists('payments', $validated) && !empty($validated['payments'])) {
                $this->validatePaymentLegRegisters($validated['payments'], (int)$warehouseId, $warehouseName, $return->user_id, $return->user_name);
            }
            $rawPayArg = $targetStatus === 'Completed'
                ? (array_key_exists('payments', $validated) ? $validated['payments'] : $return->payments)
                : [];
            $paymentsMeta = $this->buildReturnPaymentsPayload(
                $rawPayArg,
                $totals['paid_amount'],
                $refundTender,
                $cleanRef,
                (int)$return->id,
                $targetRegId,
                $txDate,
                $return->user_name ?: ($validated['user_name'] ?? 'Super Admin'),
                $return->user_role ?: ($validated['user_role'] ?? 'Super Admin'),
                $validated['notes'] ?? $return->notes
            );

            $cleanItems = array_map(static function ($it) {
                unset($it['_product']);
                return $it;
            }, $preparedData['items']);

            $return->update([
                'reference_no'          => $validated['reference_no'] ?? $return->reference_no,
                'sale_id'               => $linkedSale->id,
                'sale_reference_no'     => $linkedSale->reference_no,
                'customer_id'           => $validated['customer_id'] ?? ($linkedSale->customer_id ?? $return->customer_id),
                'customer_name'         => $validated['customer_name'] ?? $return->customer_name,
                'warehouse_id'          => $warehouseId,
                'warehouse_name'        => $warehouseName,
                'cash_register_id'      => $targetRegId,
                'date'                  => $txDate,
                'status'                => $targetStatus,
                'tax_rate'              => $totals['tax_rate'],
                'tax_amount'            => $totals['tax_amount'],
                'discount'              => $totals['discount'],
                'shipping'              => $totals['shipping'],
                'subtotal'              => $totals['subtotal'],
                'grand_total'           => $totals['grand_total'],
                'paid_amount'           => $totals['paid_amount'],
                'due_amount'            => $totals['due_amount'],
                'applied_credit_amount' => $totals['applied_credit_amount'],
                'payment_status'        => $totals['payment_status'],
                'payment_type'          => $paymentsMeta['payment_type'],
                'refund_tender'         => $refundTender,
                'notes'                 => $validated['notes'] ?? $return->notes,
                'return_reason'         => $validated['return_reason'] ?? $return->return_reason,
                'items_payload'         => json_encode($cleanItems),
                'payments_payload'      => json_encode($paymentsMeta['payments']),
            ]);

            if ($targetStatus === 'Completed') {
                if (!$warehouseId) {
                    $this->throwValidationError('warehouse_id', 'A valid warehouse is required to complete a sale return.');
                }
                $stockEffects = $this->applyCompletedReturnEffects(
                    $return,
                    $linkedSale,
                    $preparedData['items'],
                    (int)$warehouseId,
                    $fifoService,
                    $unitService
                );
                $return->update([
                    'items_payload'     => json_encode($stockEffects['items']),
                    'return_cost_total' => $stockEffects['return_cost_total'],
                    'cost_variance'     => $stockEffects['cost_variance'],
                ]);
            } else {
                $return->update([
                    'return_cost_total' => 0.0,
                    'cost_variance'     => 0.0,
                ]);
            }

            $this->syncLinkedSaleReturnCredit($linkedSale);

            $afterCashByReg = $targetStatus === 'Completed'
                ? $accountingService->extractDocumentCashByRegister($return->fresh(), ['Completed'])
                : [];
            if ($errMsg = $accountingService->assertCashRegisterDeltaMutable($beforeCashByReg, $afterCashByReg)) {
                $this->throwValidationError('cash_register_id', $errMsg);
            }

            $allRegIds = array_unique(array_merge(array_keys($beforeCashByReg), array_keys($afterCashByReg)));
            foreach ($allRegIds as $rId) {
                $beforeAmt = (float)($beforeCashByReg[$rId] ?? 0.0);
                $afterAmt = (float)($afterCashByReg[$rId] ?? 0.0);
                $cashRefundDiff = round($afterAmt - $beforeAmt, 2);
                if (abs($cashRefundDiff) > 0.001) {
                    // Refund increases drawer outflow (negative expected cash)
                    $accountingService->adjustOpenRegisterExpectedCash((int)$rId, -$cashRefundDiff);
                }
            }

            DB::commit();
            $this->clearSaleReturnCaches();

            $fresh = $return->fresh();
            $this->maskSaleReturnCostFields($fresh, $accountingService->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Sale return updated successfully',
                'data'    => $fresh,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            $code = (str_contains($e->getMessage(), 'Insufficient stock') || str_contains($e->getMessage(), 'Cannot')) ? 422 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    public function destroy(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $return = is_numeric($id)
                ? SaleReturn::lockForUpdate()->find($id)
                : SaleReturn::where('reference_no', $id)->lockForUpdate()->first();

            if (!$return) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
            }

            $unitService = app(ProductUnitConversionService::class);
            $fifoService = app(FifoInventoryService::class);
            $accountingService = app(PurchaseAccountingService::class);
            $accountingService->assertOutletAccess($request, $return->warehouse_id, $return->warehouse_name);

            $linkedSale = $this->resolveLinkedSale($return->sale_id ? (int)$return->sale_id : null, $return->sale_reference_no, true);
            $beforeCashByReg = $return->status === 'Completed'
                ? $accountingService->extractDocumentCashByRegister($return, ['Completed'])
                : [];

            if ($errMsg = $accountingService->assertCashRegisterDeltaMutable($beforeCashByReg, [])) {
                $this->throwValidationError('cash_register_id', $errMsg);
            }

            if ($return->status === 'Completed') {
                $this->reverseCompletedReturnEffects($return, $return->warehouse_id, $fifoService, $unitService, 'sale_return_void');
                foreach ($beforeCashByReg as $regId => $cashOut) {
                    $accountingService->adjustOpenRegisterExpectedCash((int)$regId, round((float)$cashOut, 2));
                }
            }

            $return->delete();
            if ($linkedSale) {
                $this->syncLinkedSaleReturnCredit($linkedSale);
            }

            DB::commit();
            $this->clearSaleReturnCaches();

            return response()->json(['status' => 'success', 'message' => 'Sale return deleted']);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            $code = (str_contains($e->getMessage(), 'Insufficient stock') || str_contains($e->getMessage(), 'Cannot')) ? 422 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }
    }

    /**
     * Add a refund payment to a sale return
     */
    public function addPayment(Request $request, $id)
    {
        $validated = $request->validate([
            'amount'                     => 'required_without:payments|nullable|numeric|min:0.01',
            'payment_type'               => 'required_without:payments|nullable|string',
            'payment_date'               => 'nullable|string',
            'cash_register_id'           => 'nullable|integer',
            'notes'                      => 'nullable|string',
            'payments'                   => 'nullable|array|min:1',
            'payments.*.amount'          => 'required|numeric|min:0.01',
            'payments.*.payment_type'    => 'required|string',
            'payments.*.received_amount' => 'nullable|numeric|min:0',
            'payments.*.change_amount'   => 'nullable|numeric|min:0',
            'payments.*.payment_date'    => 'nullable|string',
            'payments.*.cash_register_id'=> 'nullable|integer',
            'payments.*.notes'           => 'nullable|string',
            'user_name'                  => 'nullable|string|max:255',
            'user_role'                  => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $return = is_numeric($id)
                ? SaleReturn::lockForUpdate()->find($id)
                : SaleReturn::where('reference_no', $id)->lockForUpdate()->first();
            if (!$return) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Sale return record not found'], 404);
            }

            $accountingService = app(PurchaseAccountingService::class);
            $accountingService->assertOutletAccess($request, $return->warehouse_id, $return->warehouse_name);

            if ($return->status !== 'Completed') {
                $this->throwValidationError('status', 'Refund payments can only be added to Completed sale returns.');
            }
            $netRefundCeiling = max(0.0, round((float)$return->grand_total - (float)($return->applied_credit_amount ?? 0.0), 2));
            $currentPaid = round((float)$return->paid_amount, 2);

            $existingPayments = $return->payments;
            $updatedPayments = array_values($existingPayments);
            $authUser = User::resolveFromRequest($request) ?? $request->user();
            $globalUserName = $validated['user_name'] ?? ($authUser?->name ?? ($return->user_name ?: 'Super Admin'));
            $globalUserRole = $validated['user_role'] ?? ($authUser?->role ?? ($return->user_role ?: 'Super Admin'));

            $defaultRegId = $this->resolveActiveCashRegisterId(
                !empty($validated['cash_register_id']) ? (int)$validated['cash_register_id'] : null,
                $return->warehouse_id,
                $return->warehouse_name,
                $authUser?->id ?: $return->user_id,
                $globalUserName
            );

            $newTenders = !empty($validated['payments']) && is_array($validated['payments'])
                ? $validated['payments']
                : [[
                    'amount'           => (float)$validated['amount'],
                    'payment_type'     => $validated['payment_type'] ?? 'Cash',
                    'payment_date'     => $validated['payment_date'] ?? null,
                    'cash_register_id' => $validated['cash_register_id'] ?? null,
                    'notes'            => $validated['notes'] ?? '',
                ]];

            $incomingSum = round(array_sum(array_map(fn($t) => (float)($t['amount'] ?? 0), $newTenders)), 2);
            if ($currentPaid + $incomingSum > $netRefundCeiling + 0.01) {
                $remainingAllowed = max(0.0, round($netRefundCeiling - $currentPaid, 2));
                $this->throwValidationError('amount', "Refund payment exceeds remaining due balance ({$remainingAllowed}).");
            }

            $cashDeltaByReg = [];
            $maxExistingId = !empty($updatedPayments) ? max(array_map(fn($p) => (int)($p['id'] ?? 0), $updatedPayments)) : 0;
            $cleanRef = preg_replace('/^#\s*/', '', trim((string)$return->reference_no));

            foreach ($newTenders as $tender) {
                $pAmt = round((float)($tender['amount'] ?? 0), 2);
                if ($pAmt <= 0) continue;
                $maxExistingId++;
                $pType = trim((string)($tender['payment_type'] ?? 'Cash'));
                $legRegId = !empty($tender['cash_register_id'])
                    ? $this->resolveActiveCashRegisterId((int)$tender['cash_register_id'], $return->warehouse_id, $return->warehouse_name, $authUser?->id, $globalUserName)
                    : $defaultRegId;
                if ($accountingService->isCashPaymentType($pType) && $legRegId) {
                    $cashDeltaByReg[$legRegId] = round(($cashDeltaByReg[$legRegId] ?? 0.0) + $pAmt, 2);
                }
                $updatedPayments[] = [
                    'id'               => $maxExistingId,
                    'reference_no'     => 'PAY-' . ($cleanRef ?: $return->id) . '-' . date('YmdHis') . '-' . Str::upper(Str::random(4)) . '-' . $maxExistingId,
                    'payment_date'     => !empty($tender['payment_date']) ? $tender['payment_date'] : (!empty($validated['payment_date']) ? $validated['payment_date'] : now()->toDateTimeString()),
                    'payment_type'     => $pType,
                    'amount'           => $pAmt,
                    'received_amount'  => !empty($tender['received_amount']) ? (float)$tender['received_amount'] : $pAmt,
                    'change_amount'    => !empty($tender['change_amount']) ? (float)$tender['change_amount'] : 0.0,
                    'cash_register_id' => $legRegId,
                    'user_name'        => $tender['user_name'] ?? $globalUserName,
                    'user_role'        => $tender['user_role'] ?? $globalUserRole,
                    'notes'            => $tender['notes'] ?? ($validated['notes'] ?? ''),
                ];
            }

            $newPaid = round((float)collect($updatedPayments)->sum('amount'), 2);
            $newDue = max(0.0, round($netRefundCeiling - $newPaid, 2));
            $newStatus = $newDue <= 0.001 ? 'Paid' : ($newPaid > 0.001 ? 'Partial' : 'Unpaid');

            $uniqueTypes = collect($updatedPayments)->pluck('payment_type')->filter()->unique();
            $newPaymentType = $uniqueTypes->count() > 1
                ? 'Split Payment'
                : ($uniqueTypes->count() === 1 ? $uniqueTypes->first() : ($return->payment_type ?: 'Cash'));

            $return->update([
                'payments_payload' => json_encode($updatedPayments),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ]);

            if ($return->status === 'Completed') {
                foreach ($cashDeltaByReg as $regId => $amt) {
                    $accountingService->adjustOpenRegisterExpectedCash((int)$regId, -$amt);
                }
            }

            DB::commit();
            $this->clearSaleReturnCaches();

            $fresh = $return->fresh();
            $this->maskSaleReturnCostFields($fresh, $accountingService->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment added successfully',
                'data'    => $fresh,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a refund payment transaction from a sale return (exact ID or PAY- reference match).
     */
    public function deletePayment(Request $request, $returnId, $paymentId = null)
    {
        DB::beginTransaction();
        try {
            $return = is_numeric($returnId)
                ? SaleReturn::lockForUpdate()->find($returnId)
                : SaleReturn::where('reference_no', $returnId)->lockForUpdate()->first();

            if (!$return) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Sale return record not found'], 404);
            }
            app(PurchaseAccountingService::class)->assertOutletAccess($request, $return->warehouse_id, $return->warehouse_name);
            if (in_array($return->status, ['Voided', 'Cancelled'], true)) {
                $this->throwValidationError('status', 'Cannot delete refund payments on a Voided or Cancelled sale return.');
            }

            $targetIdentifier = trim((string)($paymentId ?? ''));
            if ($targetIdentifier === '') {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Payment identifier is required.'], 422);
            }

            $existingPayments = $return->payments;
            $remainingPayments = [];
            $deletedPayment = null;

            foreach ($existingPayments as $p) {
                $pId = isset($p['id']) ? trim((string)$p['id']) : '';
                $pRef = isset($p['reference_no']) ? trim((string)$p['reference_no']) : '';

                $exactIdMatch = ($pId !== '' && $pId === $targetIdentifier);
                $exactRefMatch = ($pRef !== '' && $pRef === $targetIdentifier);

                if ($deletedPayment === null && ($exactIdMatch || $exactRefMatch)) {
                    $deletedPayment = $p;
                    continue;
                }
                $remainingPayments[] = $p;
            }

            if ($deletedPayment === null) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Refund payment record not found on this sale return.'], 404);
            }

            $accountingService = app(PurchaseAccountingService::class);
            $legRegId = isset($deletedPayment['cash_register_id']) ? (int)$deletedPayment['cash_register_id'] : null;
            if ($return->status === 'Completed') {
                if ($errMsg = $accountingService->assertPaymentLegRegisterMutable(
                    $legRegId,
                    $return->cash_register_id ? (int)$return->cash_register_id : null,
                    $deletedPayment['payment_type'] ?? null
                )) {
                    $this->throwValidationError('cash_register_id', $errMsg);
                }
            }

            $netRefundCeiling = max(0.0, round((float)$return->grand_total - (float)($return->applied_credit_amount ?? 0.0), 2));
            $newPaid = round((float)collect($remainingPayments)->sum('amount'), 2);
            $newDue = max(0.0, round($netRefundCeiling - $newPaid, 2));
            $newStatus = $newPaid <= 0.001 ? ($netRefundCeiling <= 0.001 ? 'Paid' : 'Unpaid') : ($newDue <= 0.001 ? 'Paid' : 'Partial');

            $uniqueTypes = collect($remainingPayments)->pluck('payment_type')->filter()->unique();
            $newPaymentType = $uniqueTypes->count() > 1
                ? 'Split Payment'
                : ($uniqueTypes->count() === 1 ? $uniqueTypes->first() : ($return->refund_tender ?: 'Cash'));

            $return->update([
                'payments_payload' => json_encode(array_values($remainingPayments)),
                'paid_amount'      => $newPaid,
                'due_amount'       => $newDue,
                'payment_status'   => $newStatus,
                'payment_type'     => $newPaymentType,
            ]);

            if ($return->status === 'Completed' && $accountingService->isCashPaymentType($deletedPayment['payment_type'] ?? null)) {
                $delAmount = round((float)($deletedPayment['amount'] ?? 0), 2);
                $targetRegId = $legRegId ?: ($return->cash_register_id ? (int)$return->cash_register_id : null);
                if ($delAmount > 0 && $targetRegId) {
                    $accountingService->adjustOpenRegisterExpectedCash($targetRegId, $delAmount);
                }
            }

            DB::commit();
            $this->clearSaleReturnCaches();

            $fresh = $return->fresh();
            $this->maskSaleReturnCostFields($fresh, $accountingService->canViewCost($request));

            return response()->json([
                'status'  => 'success',
                'message' => 'Payment deleted successfully. Due balance restored.',
                'data'    => $fresh,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to delete payment: ' . $e->getMessage()], 500);
        }
    }

    protected function clearSaleReturnCaches(): void
    {
        // Zero-cache mode: sale returns and reports always query live DB.
    }
}
