<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Quotation;
use Carbon\Carbon;

class AutoExpireQuotationsCommand extends Command
{
    protected $signature = 'quotations:expire';
    protected $description = 'Automatically update status of past-due quotations to Expired to maintain pipeline AR integrity';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();

        $expiredCount = Quotation::whereNotNull('expiry_date')
            ->where('expiry_date', '<', $today)
            ->whereNotIn('status', ['Ordered', 'Expired'])
            ->update([
                'status' => 'Expired',
                'updated_at' => now(),
            ]);

        $this->info("Quotation Expiry Audit Completed: {$expiredCount} overdue quotation(s) marked as Expired.");
        return Command::SUCCESS;
    }
}
