<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_no',
        'supplier_id',
        'supplier_name',
        'warehouse_id',
        'warehouse_name',
        'date',
        'status', // Received, Pending, Ordered
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'paid_amount',
        'due_amount',
        'payment_status', // Paid, Unpaid, Partial
        'payment_type', // Cash, Card, Bank Transfer, Cheque, etc.
        'notes',
        'payments_payload',
        'created_by',
        'user_id',
        'user_name',
        'user_role',
    ];

    protected $casts = [
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'discount' => 'float',
        'shipping' => 'float',
        'grand_total' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
    ];

    protected $appends = ['payments'];

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

        if ((float)$this->paid_amount > 0.05 && strtolower(trim((string)$this->payment_status)) !== 'unpaid') {
            return [
                [
                    'id'              => 1,
                    'reference_no'    => !empty($this->reference_no) ? 'PAY-' . $this->reference_no : 'PAY-PU-001',
                    'payment_date'    => $this->date ?: now()->toDateTimeString(),
                    'payment_type'    => $this->payment_type ?: 'Cash',
                    'amount'          => (float)$this->paid_amount,
                    'received_amount' => (float)$this->paid_amount,
                    'change_amount'   => 0.0,
                    'user_name'       => $this->user_name ?: ($this->created_by ?: 'Super Admin'),
                    'user_role'       => $this->user_role ?: 'Super Admin',
                    'notes'           => $this->notes ?: '',
                ]
            ];
        }

        return [];
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class, 'purchase_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Generate next numeric reference number (e.g. 1001, 1002, 1003...)
     */
    public static function generateNextNumericReference(): string
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $last = static::query()
                ->whereRaw("reference_no GLOB '[0-9]*'")
                ->orderByRaw("CAST(reference_no AS INTEGER) DESC")
                ->first();
        } else {
            $last = static::query()
                ->whereRaw("reference_no REGEXP '^[0-9]+$'")
                ->orderByRaw("CAST(reference_no AS UNSIGNED) DESC")
                ->first();
        }

        if ($last && is_numeric($last->reference_no)) {
            $next = (int)$last->reference_no + 1;
        } else {
            $maxId = (int)static::max('id');
            $next = max(1001, $maxId + 1000);
        }

        while (static::where('reference_no', (string)$next)->exists()) {
            $next++;
        }

        return (string)$next;
    }
}
