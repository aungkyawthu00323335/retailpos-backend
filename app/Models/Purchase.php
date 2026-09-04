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
        'created_by',
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

    public function items()
    {
        return $this->hasMany(PurchaseItem::class, 'purchase_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
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
