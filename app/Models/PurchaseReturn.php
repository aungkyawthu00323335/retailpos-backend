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

    protected $appends = ['items'];

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
