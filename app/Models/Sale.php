<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'customer_id',
        'customer_name',
        'warehouse_id',
        'warehouse_name',
        'date',
        'status', // Completed, Pending, Draft
        'tax_rate',
        'tax_amount',
        'discount',
        'redeemed_points',
        'points_discount',
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
        'payment_slip_hash',
        'payment_slip_phash',
        'stock_reserved',
        'stock_reserved_at',
        'stock_released_at',
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
        'redeemed_points' => 'integer',
        'points_discount' => 'float',
        'shipping' => 'float',
        'subtotal' => 'float',
        'grand_total' => 'float',
        'total_cogs' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
        'change_amount' => 'float',
        'pos_flag' => 'boolean',
        'cash_register_id' => 'integer',
        'stock_reserved' => 'boolean',
        'stock_reserved_at' => 'datetime',
        'stock_released_at' => 'datetime',
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

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }

    /**
     * Normalize payment slip URL with correct host and port
     */
    public function getPaymentSlipUrlAttribute($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // If stored as http://localhost/ or without port 8000, rewrite to port 8000
        if (preg_match('#^https?://(localhost|127\.0\.0\.1)(/storage/.*)$#', $value, $matches)) {
            return "http://127.0.0.1:8000{$matches[2]}";
        }

        if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://')) {
            return "http://127.0.0.1:8000/storage/" . ltrim($value, '/');
        }

        return $value;
    }

    public function getIsDuplicateSlipAttribute(): bool
    {
        if (empty($this->payment_slip_hash) && empty($this->payment_slip_phash)) {
            return false;
        }
        return static::where(function ($q) {
            if (!empty($this->payment_slip_hash)) {
                $q->where('payment_slip_hash', $this->payment_slip_hash);
            }
            if (!empty($this->payment_slip_phash)) {
                $q->orWhere('payment_slip_phash', $this->payment_slip_phash);
            }
        })
        ->where('id', '!=', $this->id)
        ->exists();
    }

    public function getDuplicateWarningAttribute(): ?string
    {
        if (empty($this->payment_slip_hash) && empty($this->payment_slip_phash)) {
            return null;
        }
        $dup = static::where(function ($q) {
            if (!empty($this->payment_slip_hash)) {
                $q->where('payment_slip_hash', $this->payment_slip_hash);
            }
            if (!empty($this->payment_slip_phash)) {
                $q->orWhere('payment_slip_phash', $this->payment_slip_phash);
            }
        })
        ->where('id', '!=', $this->id)
        ->first();

        return $dup ? "Duplicate slip reused from order {$dup->reference_no}" : null;
    }
}
