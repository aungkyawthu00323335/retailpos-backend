<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Purchase;
use App\Models\PurchaseReturn;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchases')) {
            $purchases = Purchase::orderBy('id', 'asc')->get();
            $seq = 1001;
            foreach ($purchases as $purchase) {
                $oldRef = $purchase->reference_no;
                $newRef = (string)($seq++);
                $purchase->reference_no = $newRef;
                $purchase->save();

                // Update any corresponding purchase return reference
                if (Schema::hasTable('purchase_returns')) {
                    PurchaseReturn::where('purchase_reference_no', $oldRef)
                        ->update(['purchase_reference_no' => $newRef]);
                }
            }
        }
    }

    public function down(): void
    {
    }
};
