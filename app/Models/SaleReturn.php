<?php

namespace App\Models;

use App\Models\Concerns\TracksRegisterSplitFlag;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    use HasFactory;
    use TracksRegisterSplitFlag;

    protected static function booted(): void
    {
        static::saving(function (self $ret): void {
            if (empty($ret->sale_id) && !empty($ret->sale_reference_no)) {
                $clean = trim((string)$ret->sale_reference_no);
                if ($clean !== '') {
                    $ret->sale_id = Sale::whereIn('reference_no', [
                        $clean,
                        str_replace('-', '_', $clean),
                        str_replace('_', '-', $clean),
                    ])->value('id');
                }
            }
        });
    }

    protected $fillable = [
        'reference_no',
        'sale_id',
        'sale_reference_no',
        'customer_id',
        'customer_name',
        'warehouse_id',       // Fixed: was missing from $fillable — critical bug
        'warehouse_name',
        'date',
        'status', // Completed, Pending, Voided
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'subtotal',
        'grand_total',
        'paid_amount',
        'due_amount',
        'applied_credit_amount',
        'return_cost_total',
        'cost_variance',
        'payment_status',
        'payment_type',
        'cash_register_id',
        'notes',
        'return_reason',      // New: reason for the return
        'refund_tender',      // New: how refund is given (Cash, KBZPay, Store Credit, etc.)
        'items_payload',
        'payments_payload',
        'created_by',
        'user_id',
        'user_name',
        'user_role',
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
        'applied_credit_amount' => 'float',
        'return_cost_total' => 'float',
        'cost_variance' => 'float',
        'sale_id'      => 'integer',
        'warehouse_id' => 'integer',
        'cash_register_id' => 'integer',
        'user_id'      => 'integer',
    ];

    protected $appends = ['items', 'payments'];

    public function getPaymentsAttribute(): array
    {
        if ($this->payments_payload !== null && $this->payments_payload !== '') {
            $decoded = json_decode($this->payments_payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if ((float)$this->paid_amount > 0.05 && strtolower(trim((string)$this->payment_status)) !== 'unpaid') {
            return [
                [
                    'id'              => 1,
                    'reference_no'    => !empty($this->reference_no) ? 'REF-' . $this->reference_no : 'REF-SR-001',
                    'payment_date'    => $this->date ?: now()->toDateTimeString(),
                    'payment_type'    => $this->refund_tender ?: ($this->payment_type ?: 'Cash'),
                    'amount'          => (float)$this->paid_amount,
                    'received_amount' => (float)$this->paid_amount,
                    'change_amount'   => 0.0,
                    'user_name'       => $this->user_name ?: ($this->created_by ?: 'Super Admin'),
                    'user_role'       => $this->user_role ?: 'Super Admin',
                    'notes'           => $this->notes ?: $this->return_reason,
                ]
            ];
        }

        return [];
    }

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

        return [];
    }

    public function sale()
    {
        if (!empty($this->sale_id)) {
            return $this->belongsTo(Sale::class, 'sale_id');
        }
        return $this->belongsTo(Sale::class, 'sale_reference_no', 'reference_no');
    }

    public function costLayers()
    {
        return $this->hasMany(SaleReturnCostLayer::class, 'sale_return_id');
    }
}
