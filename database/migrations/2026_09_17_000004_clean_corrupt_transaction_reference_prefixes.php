<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;
use App\Models\PurchaseReturn;
use App\Models\SaleReturn;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Expense;
use App\Models\Quotation;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Clean Purchase Returns (PR-RET-RET-... -> PR-2026-00001)
        $prPrefix = Setting::where('key', 'purchase_return_prefix')->value('value') ?: 'PR-';
        foreach (PurchaseReturn::all() as $pr) {
            $ref = (string) ($pr->reference_no ?? '');
            if (!empty($ref) && preg_match('/(\d.*)$/', $ref, $matches)) {
                $cleanRef = $prPrefix . $matches[1];
                if ($cleanRef !== $ref) {
                    $pr->reference_no = $cleanRef;
                    $pr->save();
                }
            }
        }

        // 2. Clean Sale Returns (SR-INV-INV-... -> SR-2026-00001)
        $srPrefix = Setting::where('key', 'sale_return_prefix')->value('value') ?: 'SR-';
        foreach (SaleReturn::all() as $sr) {
            $ref = (string) ($sr->reference_no ?? '');
            if (!empty($ref) && preg_match('/(\d.*)$/', $ref, $matches)) {
                $cleanRef = $srPrefix . $matches[1];
                if ($cleanRef !== $ref) {
                    $sr->reference_no = $cleanRef;
                    $sr->save();
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
