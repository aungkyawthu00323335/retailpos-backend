<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Expense;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashRegisterController extends Controller
{
    private function resolveAuthUser(Request $request)
    {
        $token = $request->bearerToken();
        if ($token) {
            $user = User::where('api_token', $token)->first();
            if ($user) return $user;
        }
        if ($request->filled('user_id')) {
            $user = User::find($request->input('user_id'));
            if ($user) return $user;
        }
        return $request->user();
    }

    public function status(Request $request)
    {
        $user = $this->resolveAuthUser($request);
        $userId = $user?->id;
        $userName = $request->input('user_name', $user?->name);
        $warehouse = $request->input('warehouse', $request->input('warehouse_name'));

        $query = CashRegister::where('status', 'open');

        if ($userId) {
            $query->where(function ($q) use ($userId, $userName) {
                $q->where('user_id', $userId);
                if ($userName) {
                    $q->orWhere('user_name', $userName);
                }
            });
        } elseif ($userName) {
            $query->where('user_name', $userName);
        }

        if ($warehouse && $warehouse !== 'All') {
            $query->where('warehouse_name', $warehouse);
        }

        $register = $query->orderBy('id', 'desc')->first();

        if (!$register) {
            return response()->json([
                'status' => 'success',
                'has_open_register' => false,
                'register' => null,
            ]);
        }

        $formatted = $this->buildRegisterDetailsPayload($register);

        return response()->json([
            'status' => 'success',
            'has_open_register' => true,
            'register' => $formatted,
        ]);
    }

    public function open(Request $request)
    {
        $validated = $request->validate([
            'opening_cash' => 'required|numeric|min:0',
            'warehouse_id' => 'nullable|integer',
            'warehouse_name' => 'nullable|string|max:255',
            'register_name' => 'nullable|string|max:100',
            'opening_notes' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'user_name' => 'nullable|string|max:255',
        ]);

        $user = $this->resolveAuthUser($request);
        $userId = $validated['user_id'] ?? $user?->id;
        $userName = $validated['user_name'] ?? ($user?->name ?? 'Super Admin');

        $warehouseName = $validated['warehouse_name'] ?? 'Main Branch Outlet';
        $warehouseId = $validated['warehouse_id'] ?? null;
        if (!$warehouseId && $warehouseName) {
            $outlet = Outlet::where('name', $warehouseName)->first();
            $warehouseId = $outlet?->id ?? 1;
        }

        // Check if there is already an open register for this user & warehouse
        $existing = CashRegister::where('status', 'open')
            ->where(function ($q) use ($userId, $userName) {
                if ($userId) $q->where('user_id', $userId);
                if ($userName) $q->orWhere('user_name', $userName);
            })
            ->where('warehouse_name', $warehouseName)
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'success',
                'message' => 'An active cash register is already open for this cashier.',
                'has_open_register' => true,
                'register' => $this->buildRegisterDetailsPayload($existing),
            ]);
        }

        $refNo = 'REG-' . date('Ymd') . '-' . str_pad((string)rand(100, 9999), 4, '0', STR_PAD_LEFT);

        $register = CashRegister::create([
            'reference_no' => $refNo,
            'register_name' => $validated['register_name'] ?? 'Register 01',
            'user_id' => $userId,
            'user_name' => $userName,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseName,
            'opening_cash' => (float)$validated['opening_cash'],
            'status' => 'open',
            'opened_at' => now(),
            'opening_notes' => $validated['opening_notes'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Cash register opened successfully',
            'has_open_register' => true,
            'register' => $this->buildRegisterDetailsPayload($register),
        ]);
    }

    public function details(Request $request, $id = null)
    {
        if ($id) {
            $register = CashRegister::find($id);
        } else {
            $user = $this->resolveAuthUser($request);
            $userId = $user?->id;
            $userName = $request->input('user_name', $user?->name);

            $query = CashRegister::where('status', 'open');
            if ($userId) {
                $query->where(function ($q) use ($userId, $userName) {
                    $q->where('user_id', $userId);
                    if ($userName) $q->orWhere('user_name', $userName);
                });
            } elseif ($userName) {
                $query->where('user_name', $userName);
            }
            $register = $query->orderBy('id', 'desc')->first();
        }

        if (!$register) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active or specified cash register found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'register' => $this->buildRegisterDetailsPayload($register),
        ]);
    }

    public function close(Request $request, $id = null)
    {
        $validated = $request->validate([
            'closing_cash' => 'required|numeric|min:0',
            'closing_notes' => 'nullable|string',
            'denominations' => 'nullable|array',
        ]);

        if ($id) {
            $register = CashRegister::find($id);
        } else {
            $user = $this->resolveAuthUser($request);
            $userId = $user?->id;
            $userName = $request->input('user_name', $user?->name);

            $query = CashRegister::where('status', 'open');
            if ($userId) {
                $query->where(function ($q) use ($userId, $userName) {
                    $q->where('user_id', $userId);
                    if ($userName) $q->orWhere('user_name', $userName);
                });
            } elseif ($userName) {
                $query->where('user_name', $userName);
            }
            $register = $query->orderBy('id', 'desc')->first();
        }

        if (!$register) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active open cash register found to close.',
            ], 404);
        }

        $payload = $this->buildRegisterDetailsPayload($register);
        $expectedCash = (float)$payload['expected_cash'];
        $closingCash = (float)$validated['closing_cash'];
        $difference = $closingCash - $expectedCash;

        $register->update([
            'closing_cash' => $closingCash,
            'expected_cash' => $expectedCash,
            'cash_difference' => $difference,
            'status' => 'closed',
            'closed_at' => now(),
            'closing_notes' => $validated['closing_notes'] ?? null,
            'denominations' => $validated['denominations'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Cash register closed successfully',
            'register' => $this->buildRegisterDetailsPayload($register),
        ]);
    }

    private function buildRegisterDetailsPayload(CashRegister $register): array
    {
        $openedAt = $register->opened_at;
        $closedAt = $register->closed_at ?? now();

        // 1. Query sales associated with this register
        $salesQuery = DB::table('sales')
            ->where(function ($q) use ($register, $openedAt, $closedAt) {
                $q->where('cash_register_id', $register->id)
                  ->orWhere(function ($sub) use ($register, $openedAt, $closedAt) {
                      $sub->whereNull('cash_register_id')
                          ->where('warehouse_name', $register->warehouse_name)
                          ->whereBetween('date', [$openedAt, $closedAt]);
                      if ($register->user_id) {
                          $sub->where('user_id', $register->user_id);
                      }
                  });
            });

        $sales = $salesQuery->get(['id', 'reference_no', 'customer_name', 'grand_total', 'paid_amount', 'due_amount', 'payment_status', 'payment_type', 'date', 'created_at']);

        $totalSales = 0.0;
        $cashSales = 0.0;
        $cardSales = 0.0;
        $digitalSales = 0.0;
        $otherSales = 0.0;
        $txCount = $sales->count();

        $saleIds = $sales->pluck('id')->toArray();

        // 2. Query SalePayments for accurate tender breakdown (especially for Split Payments)
        $payments = !empty($saleIds)
            ? DB::table('sale_payments')->whereIn('sale_id', $saleIds)->get()
            : collect([]);

        if ($payments->isNotEmpty()) {
            foreach ($payments as $p) {
                $pType = strtolower(trim((string)$p->payment_type));
                $amount = (float)$p->amount;
                $change = (float)($p->change_amount ?? 0);
                if ($pType === 'cash') {
                    // Net cash retained in till drawer = amount collected minus change given back to customer
                    $cashSales += max(0, $amount - $change);
                } elseif (in_array($pType, ['card', 'credit card', 'pos card', 'visa', 'mastercard'])) {
                    $cardSales += $amount;
                } elseif (in_array($pType, ['kbzpay', 'wavepay', 'kpay', 'mobile money', 'digital', 'bank transfer'])) {
                    $digitalSales += $amount;
                } else {
                    $otherSales += $amount;
                }
            }
            $totalSales = (float)$sales->sum('grand_total');
        } else {
            foreach ($sales as $s) {
                $totalSales += (float)$s->grand_total;
                $pType = strtolower(trim((string)$s->payment_type));
                $paid = (float)$s->paid_amount;
                if ($pType === 'cash') {
                    $cashSales += $paid;
                } elseif (in_array($pType, ['card', 'credit card', 'pos card', 'visa', 'mastercard'])) {
                    $cardSales += $paid;
                } elseif (in_array($pType, ['kbzpay', 'wavepay', 'kpay', 'mobile money', 'digital', 'bank transfer'])) {
                    $digitalSales += $paid;
                } else {
                    $otherSales += $paid;
                }
            }
        }

        // 3. Query refunds/returns during this register session
        // Only CASH refunds that were actually disbursed from the till drawer reduce expected cash
        $returns = DB::table('sale_returns')
            ->where('warehouse_name', $register->warehouse_name)
            ->whereBetween('created_at', [$openedAt, $closedAt])
            ->get();

        $totalRefunds = 0.0;
        $cashRefunds = 0.0;
        foreach ($returns as $r) {
            $retTotal = (float)($r->grand_total ?? 0);
            $totalRefunds += $retTotal;
            $tender = strtolower(trim((string)($r->refund_tender ?? $r->payment_type ?? 'cash')));
            $paidOut = (float)($r->paid_amount ?? $retTotal);
            if ($tender === 'cash' || str_contains($tender, 'cash')) {
                $cashRefunds += $paidOut;
            }
        }

        // 4. Query expenses during this shift
        // Only CASH expenses paid from the register drawer reduce expected cash
        $expenseRecords = DB::table('expenses')
            ->where('warehouse_name', $register->warehouse_name)
            ->whereBetween('created_at', [$openedAt, $closedAt])
            ->get();

        $totalExpenses = 0.0;
        $cashExpenses = 0.0;
        $digitalExpenses = 0.0;
        foreach ($expenseRecords as $exp) {
            $amt = (float)($exp->amount ?? 0);
            $totalExpenses += $amt;
            $pMethod = strtolower(trim((string)($exp->payment_method ?? 'cash')));
            if ($pMethod === 'cash') {
                $cashExpenses += $amt;
            } else {
                $digitalExpenses += $amt;
            }
        }

        $openingCash = (float)$register->opening_cash;
        // Accurate GAAP/IFRS Till Reconciliation Formula:
        // Expected Cash = Opening Cash + Net Cash Sales - Cash Refunds - Cash Expenses
        $expectedCash = max(0, $openingCash + $cashSales - $cashRefunds - $cashExpenses);
        $closingCash = $register->closing_cash !== null ? (float)$register->closing_cash : $expectedCash;
        $difference = $register->cash_difference !== null ? (float)$register->cash_difference : ($closingCash - $expectedCash);

        $paymentBreakdown = [
            [
                'method' => 'Cash',
                'amount' => round($cashSales, 2),
                'count' => $sales->filter(fn($s) => strtolower($s->payment_type) === 'cash')->count(),
            ],
            [
                'method' => 'Card',
                'amount' => round($cardSales, 2),
                'count' => $sales->filter(fn($s) => in_array(strtolower($s->payment_type), ['card', 'credit card', 'pos card']))->count(),
            ],
            [
                'method' => 'Digital / Mobile',
                'amount' => round($digitalSales, 2),
                'count' => $sales->filter(fn($s) => in_array(strtolower($s->payment_type), ['kbzpay', 'wavepay', 'kpay', 'mobile money', 'digital', 'bank transfer']))->count(),
            ],
            [
                'method' => 'Other / Split',
                'amount' => round($otherSales, 2),
                'count' => $sales->filter(fn($s) => !in_array(strtolower($s->payment_type), ['cash', 'card', 'credit card', 'pos card', 'kbzpay', 'wavepay', 'kpay', 'mobile money', 'digital', 'bank transfer']))->count(),
            ],
        ];

        return [
            'id' => (int)$register->id,
            'reference_no' => $register->reference_no,
            'register_id' => $register->register_name ?? 'Register 01',
            'warehouse_id' => (int)($register->warehouse_id ?? 1),
            'warehouse_name' => $register->warehouse_name ?? 'Main Branch Outlet',
            'date' => substr((string)$openedAt, 0, 10),
            'opened_at' => (string)$openedAt,
            'closed_at' => $register->closed_at ? (string)$register->closed_at : '',
            'total_transactions' => $txCount,
            'opening_cash' => $openingCash,
            'total_sales' => round($totalSales, 2),
            'cash_sales' => round($cashSales, 2),
            'card_sales' => round($cardSales, 2),
            'digital_sales' => round($digitalSales, 2),
            'other_sales' => round($otherSales, 2),
            'total_refunds' => round($totalRefunds, 2),
            'cash_refunds' => round($cashRefunds, 2),
            'total_expenses' => round($totalExpenses, 2),
            'cash_expenses' => round($cashExpenses, 2),
            'digital_expenses' => round($digitalExpenses, 2),
            'expected_cash' => round($expectedCash, 2),
            'closing_cash' => round($closingCash, 2),
            'difference' => round($difference, 2),
            'status' => ucfirst($register->status),
            'primary_payment_type' => 'Cash',
            'notes' => (string)($register->opening_notes ?? $register->closing_notes ?? ''),
            'created_by' => $register->user_name ?? 'Super Admin',
            'cashier_name' => $register->user_name ?? 'Super Admin',
            'user_name' => $register->user_name ?? 'Super Admin',
            'user_role' => 'Lead Cashier',
            'payment_breakdown' => $paymentBreakdown,
            'recent_transactions' => $sales->take(20)->values(),
            'denominations' => $register->denominations,
        ];
    }
}
