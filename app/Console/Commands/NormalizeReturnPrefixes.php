<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use App\Models\PurchaseReturn;
use App\Models\SaleReturn;

class NormalizeReturnPrefixes extends Command
{
    protected $signature = 'pos:normalize-returns';
    protected $description = 'Normalizes overlong or duplicated return reference numbers in purchase_returns and sale_returns';

    public function handle()
    {
        $prPrefix = Setting::where('key', 'purchase_return_prefix')->value('value') ?: 'PR-';
        $srPrefix = Setting::where('key', 'sale_return_prefix')->value('value') ?: 'SR-';

        $prCount = 0;
        foreach (PurchaseReturn::all() as $pr) {
            $ref = (string) ($pr->reference_no ?? '');
            if (!empty($ref) && preg_match('/(\d.*)$/', $ref, $matches)) {
                $cleanRef = $prPrefix . $matches[1];
                if ($cleanRef !== $ref) {
                    $pr->reference_no = $cleanRef;
                    $pr->save();
                    $prCount++;
                }
            }
        }

        $srCount = 0;
        foreach (SaleReturn::all() as $sr) {
            $ref = (string) ($sr->reference_no ?? '');
            if (!empty($ref) && preg_match('/(\d.*)$/', $ref, $matches)) {
                $cleanRef = $srPrefix . $matches[1];
                if ($cleanRef !== $ref) {
                    $sr->reference_no = $cleanRef;
                    $sr->save();
                    $srCount++;
                }
            }
        }

        $this->info("Successfully normalized {$prCount} Purchase Returns and {$srCount} Sale Returns.");
        $this->info("PR Sample: " . json_encode(PurchaseReturn::take(5)->pluck('reference_no')));
        $this->info("SR Sample: " . json_encode(SaleReturn::take(5)->pluck('reference_no')));
        return Command::SUCCESS;
    }
}
