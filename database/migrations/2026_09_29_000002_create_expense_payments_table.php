<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add paid_amount, due_amount, payment_status to expenses table if missing
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (!Schema::hasColumn('expenses', 'paid_amount')) {
                    $table->decimal('paid_amount', 15, 2)->default(0)->after('amount');
                }
                if (!Schema::hasColumn('expenses', 'due_amount')) {
                    $table->decimal('due_amount', 15, 2)->default(0)->after('paid_amount');
                }
                if (!Schema::hasColumn('expenses', 'payment_status')) {
                    $table->string('payment_status')->default('Paid')->after('due_amount');
                    $table->index('payment_status');
                }
            });
        }

        // 2. Create expense_payments table
        if (!Schema::hasTable('expense_payments')) {
            Schema::create('expense_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('expense_id')->constrained('expenses')->onDelete('cascade');
                $table->string('reference_no')->index();
                $table->string('payment_date');
                $table->string('payment_type');
                $table->decimal('amount', 15, 2)->default(0);
                $table->decimal('received_amount', 15, 2)->default(0);
                $table->decimal('change_amount', 15, 2)->default(0);
                $table->text('notes')->nullable();
                $table->string('created_by')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_role')->nullable();
                $table->timestamps();
            });
        }

        // 3. Backfill initial payment records for existing expenses
        $expenses = DB::table('expenses')->get();
        foreach ($expenses as $expense) {
            $hasPayment = DB::table('expense_payments')->where('expense_id', $expense->id)->exists();
            if (!$hasPayment && (float)$expense->amount > 0) {
                $ref = !empty($expense->reference_no) ? $expense->reference_no : ('EXP-' . $expense->id);
                $paymentRef = 'PAY-' . $ref;

                DB::table('expense_payments')->insert([
                    'expense_id'      => $expense->id,
                    'reference_no'    => $paymentRef,
                    'payment_date'    => $expense->date ?? now()->toDateTimeString(),
                    'payment_type'    => $expense->payment_method ?? 'Cash',
                    'amount'          => $expense->amount,
                    'received_amount' => $expense->amount,
                    'change_amount'   => 0,
                    'notes'           => $expense->details ?? 'Initial disbursement',
                    'created_by'      => $expense->created_by,
                    'user_id'         => $expense->user_id,
                    'user_name'       => $expense->user_name ?? $expense->created_by,
                    'user_role'       => $expense->user_role ?? 'Super Admin',
                    'created_at'      => $expense->created_at ?? now(),
                    'updated_at'      => $expense->updated_at ?? now(),
                ]);

                DB::table('expenses')->where('id', $expense->id)->update([
                    'paid_amount'    => $expense->amount,
                    'due_amount'     => 0.00,
                    'payment_status' => 'Paid',
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_payments');

        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (Schema::hasColumn('expenses', 'payment_status')) {
                    $table->dropIndex(['payment_status']);
                    $table->dropColumn('payment_status');
                }
                if (Schema::hasColumn('expenses', 'due_amount')) {
                    $table->dropColumn('due_amount');
                }
                if (Schema::hasColumn('expenses', 'paid_amount')) {
                    $table->dropColumn('paid_amount');
                }
            });
        }
    }
};
