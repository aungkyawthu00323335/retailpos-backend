<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'purchase_reference_no',
        'supplier_id',
        'supplier_name',
        'warehouse_id',
        'warehouse_name',
        'outlet_id',
        'date',
        'status', // Completed, Pending, Received
        'grand_total',
        'paid_amount',
        'due_amount',
        'payment_status',
        'payment_type',     // New: how supplier refunds (Cash, Bank Transfer, Credit Note, Cheque)
        'subtotal',         // New: line items subtotal before tax/discount
        'tax_rate',         // New: tax rate %
        'tax_amount',       // New: calculated tax amount
        'discount',         // New: order-level discount
        'shipping',         // New: shipping/handling fee
        'notes',
        'return_reason',    // New: why goods are returned to supplier
        'items_payload',    // New: JSON encoded returned items detail
        'payments_payload', // New: JSON encoded refund payment transactions
        'created_by',
        'user_id',
        'user_name',
        'user_role',
    ];

    protected $casts = [
        'grand_total'  => 'float',
        'paid_amount'  => 'float',
        'due_amount'   => 'float',
        'subtotal'     => 'float',
        'tax_rate'     => 'float',
        'tax_amount'   => 'float',
        'discount'     => 'float',
        'shipping'     => 'float',
        'warehouse_id' => 'integer',
        'outlet_id'    => 'integer',
        'supplier_id'  => 'integer',
        'user_id'      => 'integer',
    ];

    protected $appends = ['items', 'payments'];

    /**
     * Decode stored payments_payload JSON into array.
     * If payments_payload is not set but paid_amount > 0 and payment_status is not Unpaid,
     * synthesize the default payment record for full backward compatibility.
     * If paid_amount <= 0 or payment_status == 'Unpaid', return empty array.
     */
    public function getPaymentsAttribute(): array
    {
        if ($this->payments_payload !== null && $this->payments_payload !== '') {
            $decoded = json_decode($this->payments_payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Backward compatibility: If no payments_payload stored yet, but paid_amount > 0 and not Unpaid
        if ((float)$this->paid_amount > 0.05 && strtolower(trim((string)$this->payment_status)) !== 'unpaid') {
            return [
                [
                    'id'              => 1,
                    'reference_no'    => !empty($this->reference_no) ? 'REF-' . $this->reference_no : 'REF-PR-001',
                    'payment_date'    => $this->date ?: now()->toDateTimeString(),
                    'payment_type'    => $this->payment_type ?: 'Cash',
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

    /**
     * Decode stored items_payload JSON into array.
     * Returns empty array if no items were recorded.
     */
    public function getItemsAttribute(): array
    {
        if (!empty($this->items_payload)) {
            $decoded = json_decode($this->items_payload, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        // Try to load items from linked purchase if available
        if (!empty($this->purchase_reference_no)) {
            $clean = trim($this->purchase_reference_no);
            $linkedPurchase = Purchase::where('reference_no', $clean)
                ->orWhere('reference_no', str_replace('-', '_', $clean))
                ->orWhere('reference_no', str_replace('_', '-', $clean))
                ->with('items')
                ->first();

            if ($linkedPurchase && $linkedPurchase->items && $linkedPurchase->items->isNotEmpty()) {
                return $linkedPurchase->items->map(function ($it) {
                    return [
                        'id'                 => $it->id,
                        'product_id'         => $it->product_id,
                        'product_variant_id' => $it->product_variant_id ?? null,
                        'product_name'       => $it->product_name,
                        'product_code'       => $it->product_code ?? '',
                        'unit_name'          => $it->unit_name ?? 'Pcs',
                        'unit_price'         => (float)($it->unit_price ?? $it->purchase_price ?? 0),
                        'quantity'           => (float)($it->quantity ?? 0),
                        'discount'           => (float)($it->discount ?? 0),
                        'tax'                => (float)($it->tax ?? 0),
                        'subtotal'           => (float)($it->subtotal ?? 0),
                        'unit_cost'          => (float)($it->unit_price ?? $it->purchase_price ?? 0),
                    ];
                })->values()->all();
            }
        }

        return [];
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_reference_no', 'reference_no');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
