<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'sale_reference_no',
        'customer_id',
        'customer_name',
        'warehouse_id',       // Fixed: was missing from $fillable — critical bug
        'warehouse_name',
        'date',
        'status', // Completed, Pending
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'subtotal',
        'grand_total',
        'paid_amount',
        'due_amount',
        'payment_status',
        'payment_type',
        'notes',
        'return_reason',      // New: reason for the return
        'refund_tender',      // New: how refund is given (Cash, KBZPay, Store Credit, etc.)
        'items_payload',
        'created_by',
    ];

    protected $casts = [
        'tax_rate'     => 'float',
        'tax_amount'   => 'float',
        'discount'     => 'float',
        'shipping'     => 'float',
        'subtotal'     => 'float',
        'grand_total'  => 'float',
        'paid_amount'  => 'float',
        'due_amount'   => 'float',
        'warehouse_id' => 'integer',
    ];

    protected $appends = ['items'];

    public function getItemsAttribute()
    {
        if (!empty($this->items_payload)) {
            $decoded = json_decode($this->items_payload, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        // Try to load items from linked sale if available
        if (!empty($this->sale_reference_no)) {
            $clean = trim($this->sale_reference_no);
            $variants = [
                $clean,
                str_replace('-', '_', $clean),
                str_replace('_', '-', $clean),
            ];
            $linkedSale = Sale::whereIn('reference_no', $variants)->with('items')->first();
            if ($linkedSale && $linkedSale->items && $linkedSale->items->isNotEmpty()) {
                return $linkedSale->items->map(function ($it) {
                    return [
                        'id'                 => $it->id,
                        'product_id'         => $it->product_id,
                        'product_variant_id' => $it->product_variant_id,
                        'product_name'       => $it->product_name,
                        'product_code'       => $it->product_code,
                        'unit_name'          => $it->unit_name ?: 'Pcs',
                        'unit_price'         => (float)$it->unit_price,
                        'quantity'           => (float)$it->quantity,
                        'discount'           => (float)$it->discount,
                        'tax'                => (float)$it->tax,
                        'subtotal'           => (float)$it->subtotal,
                        'unit_cost'          => (float)($it->unit_cost ?? $it->unit_price),
                    ];
                })->values()->all();
            }
        }

        // Fallback synthetic item representation for legacy returns
        return [
            [
                'id'                 => $this->id ?? 1,
                'product_id'         => 1,
                'product_variant_id' => null,
                'product_name'       => 'Returned Item / Goods (' . ($this->reference_no ?: 'Voucher') . ')',
                'product_code'       => 'RET-' . str_pad($this->id ?? 1, 5, '0', STR_PAD_LEFT),
                'unit_name'          => 'Pcs',
                'unit_price'         => (float)($this->subtotal > 0 ? $this->subtotal : $this->grand_total),
                'quantity'           => 1.0,
                'discount'           => (float)$this->discount,
                'tax'                => (float)$this->tax_amount,
                'subtotal'           => (float)($this->subtotal > 0 ? $this->subtotal : $this->grand_total),
                'unit_cost'          => (float)($this->subtotal > 0 ? $this->subtotal : $this->grand_total),
            ]
        ];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_reference_no', 'reference_no');
    }
}
