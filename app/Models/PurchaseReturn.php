<?php

namespace App\Models;

use App\Models\Concerns\TracksRegisterSplitFlag;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    use HasFactory;
    use TracksRegisterSplitFlag;

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
        'return_cost_total',
        'cost_variance',
        'payment_status',
        'payment_type',     // New: how supplier refunds (Cash, Bank Transfer, Credit Note, Cheque)
        'cash_register_id',
        'subtotal',         // New: line items subtotal before tax/discount
        'tax_rate',         // New: tax rate %
        'tax_amount',       // New: calculated tax amount
        'is_tax_recoverable',
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
        'grand_total'        => 'float',
        'paid_amount'        => 'float',
        'due_amount'         => 'float',
        'return_cost_total'  => 'float',
        'cost_variance'      => 'float',
        'subtotal'           => 'float',
        'tax_rate'           => 'float',
        'tax_amount'         => 'float',
        'is_tax_recoverable' => 'boolean',
        'discount'           => 'float',
        'shipping'           => 'float',
        'warehouse_id'       => 'integer',
        'outlet_id'          => 'integer',
        'supplier_id'        => 'integer',
        'cash_register_id'   => 'integer',
        'user_id'            => 'integer',
    ];

    protected $attributes = [
        'is_tax_recoverable' => false,
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

        return [];
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_reference_no', 'reference_no');
    }

    public function allocations()
    {
        return $this->hasMany(PurchaseReturnAllocation::class, 'purchase_return_id');
    }

    public function costLayers()
    {
        return $this->hasMany(PurchaseReturnCostLayer::class, 'purchase_return_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
