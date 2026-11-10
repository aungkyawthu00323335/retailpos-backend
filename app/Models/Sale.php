<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'idempotency_key',
        'quotation_id',
        'customer_id',
        'customer_name',
        'warehouse_id',
        'warehouse_name',
        'date',
        'status', // Completed, Pending, Draft
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'subtotal',
        'grand_total',
        'paid_amount',
        'due_amount',
        'change_amount',
        'payment_status', // Paid, Unpaid, Partial
        'payment_type', // Cash, Credit Card, Bank Transfer, Cheque, WavePay, KBZPay, etc.
        'notes',
        'pos_flag',
        'order_source',
        'telegram_chat_id',
        'telegram_username',
        'telegram_order_id',
        'bot_approval_status',
        'bot_rejection_reason',
        'payment_slip_url',
        'shipping_address',
        'customer_phone',
        'cash_register_id',
        'created_by',
        'user_id',
        'user_name',
        'user_role',
        'total_cogs',
        'void_reason',
        'voided_by',
        'voided_at',
        'bot_approved_by',
        'bot_approved_at',
        'bot_rejected_by',
        'bot_rejected_at',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'discount' => 'float',
        'shipping' => 'float',
        'subtotal' => 'float',
        'grand_total' => 'float',
        'total_cogs' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
        'change_amount' => 'float',
        'pos_flag' => 'boolean',
        'cash_register_id' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function costLayers()
    {
        return $this->hasMany(SaleItemCostLayer::class, 'sale_id');
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class, 'sale_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }

    /**
     * Normalize payment slip URL dynamically based on environment or request host
     */
    public function getPaymentSlipUrlAttribute($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // If stored as relative path (e.g. 'payment_slips/abc.jpg' or 'storage/payment_slips/abc.jpg')
        if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
            $clean = preg_replace('#^/?storage/#', '', ltrim($value, '/'));
            return url('storage/' . $clean);
        }

        // If stored with obsolete localhost/127.0.0.1, dynamically adapt to current environment URL
        if (preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(/storage/.*)$#', $value, $matches)) {
            return url(ltrim($matches[1], '/'));
        }

        return $value;
    }
}
