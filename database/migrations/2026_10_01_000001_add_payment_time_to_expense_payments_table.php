<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('expense_payments')) {
            Schema::table('expense_payments', function (Blueprint $table) {
                if (!Schema::hasColumn('expense_payments', 'payment_time')) {
                    $table->string('payment_time')->nullable()->after('payment_date');
                }
            });

            // Backfill existing rows with 12-hour formatted time (e.g. "04:33 AM")
            $timezone = Setting::get('timezone', config('app.timezone', 'Asia/Yangon'));
            $payments = DB::table('expense_payments')->get();
            foreach ($payments as $p) {
                $time12 = null;

                // 1. If payment_date contains a time component (e.g. "2026-10-01 05:06:24")
                if (!empty($p->payment_date) && str_contains($p->payment_date, ':')) {
                    try {
                        $time12 = Carbon::parse($p->payment_date)->timezone($timezone)->format('h:i A');
                    } catch (\Throwable $e) {}
                }

                // 2. Otherwise use created_at timestamp
                if (empty($time12) && !empty($p->created_at)) {
                    try {
                        $time12 = Carbon::parse($p->created_at)->timezone($timezone)->format('h:i A');
                    } catch (\Throwable $e) {}
                }

                // 3. Fallback to current time in configured timezone if still empty
                if (empty($time12)) {
                    $time12 = Carbon::now()->timezone($timezone)->format('h:i A');
                }

                // Clean up payment_date to strictly contain Y-m-d if it contained datetime
                $cleanDate = $p->payment_date;
                if (!empty($cleanDate) && str_contains($cleanDate, ' ')) {
                    $cleanDate = explode(' ', $cleanDate)[0];
                }

                DB::table('expense_payments')
                    ->where('id', $p->id)
                    ->update([
                        'payment_time' => $time12,
                        'payment_date' => $cleanDate ?: Carbon::now()->timezone($timezone)->format('Y-m-d'),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('expense_payments')) {
            Schema::table('expense_payments', function (Blueprint $table) {
                if (Schema::hasColumn('expense_payments', 'payment_time')) {
                    $table->dropColumn('payment_time');
                }
            });
        }
    }
};
