<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Expense;
use App\Models\Outlet;
use App\Models\User;
use App\Services\PurchaseAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashRegisterController extends Controller
{
    private function noStoreJson(array $payload, int $status = 200)
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }

    private function resolveAuthUser(Request $request)
    {
        $resolved = User::resolveFromRequest($request);
        if ($resolved) {
            return $resolved->loadMissing('roleModel');
        }
        if ($request->filled('user_id')) {
            $user = User::with('roleModel')->find($request->input('user_id'));
            if ($user) {
                User::attachResolvedToRequest($request, $user);
                return $user;
            }
        }
        if ($request->user()) {
            return $request->user()->loadMissing('roleModel');
        }
        if (app()->environment('testing')) {
            return User::with('roleModel')->where('role', 'Super Admin')->first()
                ?: User::with('roleModel')->first();
        }
        return null;
    }

    public function status(Request $request)
    {
        $user = $this->resolveAuthUser($request);
        $userId = $user?->id;
        $userName = $request->input('user_name', $user?->name);
        $warehouse = $request->input('warehouse', $request->input('warehouse_name'));

        $query = CashRegister::whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened']);

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
            return $this->noStoreJson([
                'status' => 'success',
                'has_open_register' => false,
                'register' => null,
            ]);
        }

        $formatted = $this->buildRegisterDetailsPayload($register, $user);

        return $this->noStoreJson([
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
        $existing = CashRegister::whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened'])
            ->where(function ($q) use ($userId, $userName) {
                if ($userId) $q->where('user_id', $userId);
                if ($userName) $q->orWhere('user_name', $userName);
            })
            ->where('warehouse_name', $warehouseName)
            ->first();

        if ($existing) {
            return $this->noStoreJson([
                'status' => 'success',
                'message' => 'An active cash register is already open for this cashier.',
                'has_open_register' => true,
                'register' => $this->buildRegisterDetailsPayload($existing, $user),
            ]);
        }

        $refNo = 'REG-' . date('Ymd') . '-' . str_pad((string)rand(100, 9999), 4, '0', STR_PAD_LEFT);
        $openingCash = round((float)$validated['opening_cash'], 2);

        $register = CashRegister::create([
            'reference_no' => $refNo,
            'register_name' => $validated['register_name'] ?? 'Register 01',
            'user_id' => $userId,
            'user_name' => $userName,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseName,
            'opening_cash' => $openingCash,
            'expected_cash' => $openingCash,
            'status' => 'open',
            'opened_at' => now(),
            'opening_notes' => $validated['opening_notes'] ?? null,
        ]);

        return $this->noStoreJson([
            'status' => 'success',
            'message' => 'Cash register opened successfully',
            'has_open_register' => true,
            'register' => $this->buildInitialRegisterPayload($register, $user),
        ]);
    }

    public function details(Request $request, $id = null)
    {
        $user = $this->resolveAuthUser($request);

        if ($id) {
            $register = CashRegister::find($id);
        } else {
            $userId = $user?->id;
            $userName = $request->input('user_name', $user?->name);

            $query = CashRegister::whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened']);
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
            return $this->noStoreJson([
                'status' => 'error',
                'message' => 'No active or specified cash register found.',
            ], 404);
        }

        return $this->noStoreJson([
            'status' => 'success',
            'register' => $this->buildRegisterDetailsPayload($register, $user),
        ]);
    }

    public function close(Request $request, $id = null)
    {
        $validated = $request->validate([
            'closing_cash' => 'nullable|numeric|min:0',
            'closing_notes' => 'nullable|string',
            'denominations' => 'nullable|array',
            'pending_offline_count' => 'nullable|integer|min:0',
            'has_pending_offline_sales' => 'nullable|boolean',
        ]);

        if ((int)($validated['pending_offline_count'] ?? 0) > 0 || !empty($validated['has_pending_offline_sales'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot close cash register while unsynced offline sales remain. Please sync all offline sales first.',
            ], 422);
        }

        $user = $this->resolveAuthUser($request);

        return DB::transaction(function () use ($request, $validated, $id, $user) {
            if ($id) {
                $register = CashRegister::lockForUpdate()->find($id);
            } else {
                $userId = $user?->id;
                $userName = $request->input('user_name', $user?->name);

                $query = CashRegister::whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened']);
                if ($userId) {
                    $query->where(function ($q) use ($userId, $userName) {
                        $q->where('user_id', $userId);
                        if ($userName) $q->orWhere('user_name', $userName);
                    });
                } elseif ($userName) {
                    $query->where('user_name', $userName);
                }
                $register = $query->orderBy('id', 'desc')->lockForUpdate()->first();
            }

            if (!$register) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No active open cash register found to close.',
                ], 404);
            }

            if (!in_array(strtolower(trim((string)$register->status)), ['open', 'opened'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This cash register session is already closed.',
                ], 422);
            }

            $closedAt = now();
            $register->closed_at = $closedAt;
            $payload = $this->buildRegisterDetailsPayload($register, $user, true);
            $expectedCash = (float)$payload['expected_cash'];
            $closingCash = isset($validated['closing_cash']) ? round((float)$validated['closing_cash'], 2) : $expectedCash;
            $difference = round($closingCash - $expectedCash, 2);

            $closingNotes = $validated['closing_notes'] ?? null;
            $denominations = $validated['denominations'] ?? null;

            $register->update([
                'closing_cash' => $closingCash,
                'expected_cash' => $expectedCash,
                'cash_difference' => $difference,
                'status' => 'closed',
                'closed_at' => $closedAt,
                'closing_notes' => $closingNotes,
                'denominations' => $denominations,
            ]);

            $canViewExpected = $user ? $user->hasPermission('cash_register.view_expected') : false;
            $payload['closing_cash'] = round($closingCash, 2);
            $payload['expected_cash'] = $canViewExpected ? round($expectedCash, 2) : null;
            $payload['difference'] = $canViewExpected ? round($difference, 2) : null;
            $payload['status'] = 'Closed';
            $payload['closed_at'] = (string)$closedAt;
            $payload['closing_notes'] = (string)($closingNotes ?? '');
            $payload['notes'] = (string)($closingNotes ?? $payload['notes']);
            $payload['denominations'] = $denominations;

            return $this->noStoreJson([
                'status' => 'success',
                'message' => 'Cash register closed successfully',
                'register' => $payload,
            ]);
        });
    }

    public function recordTransaction(Request $request)
    {
        $validated = $request->validate([
            'cash_register_id' => 'nullable|integer',
            'type' => 'required|string|in:drop,float_in',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|min:3|max:255',
            'manager_pin' => 'required|string',
        ]);

        if (!app(PurchaseAccountingService::class)->verifyManagerPin($validated['manager_pin'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Manager PIN. Authorization failed.',
            ], 403);
        }

        $user = $this->resolveAuthUser($request);
        $authorizerName = $user?->name ?? 'Store Manager';

        return DB::transaction(function () use ($request, $validated, $user, $authorizerName) {
            $register = null;
            if (!empty($validated['cash_register_id'])) {
                $register = CashRegister::lockForUpdate()->find($validated['cash_register_id']);
            }

            if (!$register) {
                $userId = $user?->id;
                $userName = $request->input('user_name', $user?->name);

                $query = CashRegister::whereRaw('LOWER(status) IN (?, ?)', ['open', 'opened']);
                if ($userId) {
                    $query->where(function ($q) use ($userId, $userName) {
                        $q->where('user_id', $userId);
                        if ($userName) $q->orWhere('user_name', $userName);
                    });
                } elseif ($userName) {
                    $query->where('user_name', $userName);
                }
                $register = $query->orderBy('id', 'desc')->lockForUpdate()->first();
            }

            if (!$register || !in_array(strtolower(trim((string)$register->status)), ['open', 'opened'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Active open cash register not found.',
                ], 404);
            }

            // If Safe Drop, ensure drawer has enough cash
            $currentPayload = $this->buildRegisterDetailsPayload($register, $user, true);
            $currentExpectedCash = (float)($currentPayload['expected_cash'] ?? 0);

            if ($validated['type'] === 'drop' && (float)$validated['amount'] > $currentExpectedCash + 0.005) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Safe drop amount (' . number_format($validated['amount']) . ' Ks) cannot exceed current drawer cash (' . number_format($currentExpectedCash) . ' Ks).',
                ], 422);
            }

            $tx = CashRegisterTransaction::create([
                'cash_register_id' => $register->id,
                'type' => $validated['type'],
                'amount' => round((float)$validated['amount'], 2),
                'reason' => $validated['reason'],
                'authorized_by' => $authorizerName,
                'manager_pin_verified' => true,
            ]);

            $delta = $validated['type'] === 'float_in'
                ? round((float)$validated['amount'], 2)
                : -round((float)$validated['amount'], 2);
            $register->update(['expected_cash' => round($currentExpectedCash + $delta, 2)]);

            $updatedPayload = $this->buildRegisterDetailsPayload($register->fresh(), $user, false);

            return $this->noStoreJson([
                'status' => 'success',
                'message' => ($validated['type'] === 'drop' ? 'Safe drop' : 'Float addition') . ' recorded successfully',
                'transaction' => $tx,
                'register' => $updatedPayload,
            ]);
        });
    }

    /**
     * Normalize payment method names to eliminate generic "Digital / Mobile"
     * and accurately show individual tender types (KBZPay, WavePay, KPay, Card, Cash, etc.)
     */
    private function normalizePaymentMethodName(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return 'Other';
        }
        $lower = strtolower($trimmed);
        if ($lower === 'cash') return 'Cash';
        if (in_array($lower, ['kbzpay', 'kbz pay', 'kbz_pay'])) return 'KBZPay';
        if (in_array($lower, ['kpay', 'k pay', 'k-pay'])) return 'KPay';
        if (in_array($lower, ['wave', 'wavepay', 'wave pay', 'wave_pay'])) return 'WavePay';
        if (in_array($lower, ['aya pay', 'ayapay'])) return 'AYA Pay';
        if (in_array($lower, ['cb pay', 'cbpay'])) return 'CB Pay';
        if (in_array($lower, ['one pay', 'onepay'])) return 'OnePay';
        if (in_array($lower, ['uab pay', 'uabpay'])) return 'UABPay';
        if (in_array($lower, ['card', 'credit card', 'pos card', 'credit_card'])) return 'Credit Card';
        if (in_array($lower, ['debit card', 'debit_card'])) return 'Debit Card';
        if (in_array($lower, ['visa'])) return 'Visa';
        if (in_array($lower, ['mastercard'])) return 'MasterCard';
        if (in_array($lower, ['bank transfer', 'bank_transfer', 'wire', 'bank'])) return 'Bank Transfer';
        if (in_array($lower, ['cheque', 'check'])) return 'Cheque';
        if (in_array($lower, ['digital', 'digital / mobile', 'mobile money', 'wallet'])) {
            return 'Mobile Wallet';
        }
        return ucwords($trimmed);
    }

    private function buildInitialRegisterPayload(CashRegister $register, ?User $user = null): array
    {
        $openedAt = (string)$register->opened_at;
        $userName = $register->user_name ?? ($user?->name ?? 'Super Admin');
        $userRole = $user?->role ?? ($user?->roleModel?->name ?? 'Lead Cashier');
        $openingCash = (float)$register->opening_cash;

        return [
            'id' => (int)$register->id,
            'reference_no' => $register->reference_no,
            'register_id' => $register->register_name ?? 'Register 01',
            'warehouse_id' => (int)($register->warehouse_id ?? 1),
            'warehouse_name' => $register->warehouse_name ?? 'Main Branch Outlet',
            'date' => substr($openedAt, 0, 10),
            'opened_at' => $openedAt,
            'closed_at' => '',
            'total_transactions' => 0,
            'opening_cash' => $openingCash,
            'total_sales' => 0.0,
            'cash_sales' => 0.0,
            'cheque_sales' => 0.0,
            'bank_transfer_sales' => 0.0,
            'card_sales' => 0.0,
            'digital_sales' => 0.0,
            'other_sales' => 0.0,
            'total_collections' => 0.0,
            'total_payment' => $openingCash,
            'total_refunds' => 0.0,
            'cash_refunds' => 0.0,
            'total_expenses' => 0.0,
            'cash_expenses' => 0.0,
            'digital_expenses' => 0.0,
            'floats_in' => 0.0,
            'cash_drops' => 0.0,
            'safe_drops' => 0.0,
            'expected_cash' => $openingCash,
            'closing_cash' => $openingCash,
            'difference' => 0.0,
            'status' => 'Open',
            'primary_payment_type' => 'Cash',
            'notes' => (string)($register->opening_notes ?? ''),
            'closing_notes' => '',
            'created_by' => $userName,
            'cashier_name' => $userName,
            'user_name' => $userName,
            'user_role' => $userRole,
            'payment_breakdown' => [
                ['method' => 'Cash', 'amount' => 0.0, 'count' => 0],
            ],
            'recent_transactions' => [],
            'denominations' => null,
            'late_offline_reconciliations' => [],
            'register_transactions' => [],
        ];
    }

    private function buildRegisterDetailsPayload(CashRegister $register, ?User $user = null, bool $forInternalCalculation = false): array
    {
        $accounting = app(PurchaseAccountingService::class);
        $openedAt = $register->opened_at;
        $closedAt = $register->closed_at ?? now();

        // 1. Query posted Completed sales associated strictly with this register (excluding unposted & late-offline sales)
        $hasLateOfflineCol = $accounting->schemaHasColumn('sales', 'late_offline_reconciliation');
        $salesQuery = DB::table('sales')
            ->whereNotIn('status', ['Voided', 'Cancelled', 'Draft', 'Pending', 'Ordered'])
            ->when($hasLateOfflineCol, fn ($q) => $q->where(function ($sub) {
                $sub->whereNull('late_offline_reconciliation')->orWhere('late_offline_reconciliation', false);
            }))
            ->where(function ($q) use ($register, $openedAt, $closedAt) {
                $q->where('cash_register_id', $register->id)
                  ->orWhere(function ($sub) use ($register, $openedAt, $closedAt) {
                      $sub->whereNull('cash_register_id')
                          ->where('pos_flag', true)
                          ->where('warehouse_name', $register->warehouse_name)
                          ->whereBetween('created_at', [$openedAt, $closedAt]);
                      if ($register->user_id) {
                          $sub->where('user_id', $register->user_id);
                      }
                  });
            });

        $sales = $salesQuery
            ->orderByDesc('id')
            ->get(['id', 'reference_no', 'customer_name', 'grand_total', 'paid_amount', 'due_amount', 'payment_status', 'payment_type', 'date', 'created_at']);

        $totalSales = (float)$sales->sum('grand_total');
        $txCount = $sales->count();
        $saleIds = $sales->pluck('id')->all();

        // 2. Query SalePayments collected directly during this register session (chunked SQLite-safe & zero N+1)
        $hasSpRegCol = $accounting->schemaHasColumn('sale_payments', 'cash_register_id');
        $payments = collect();
        if ($hasSpRegCol) {
            if (!empty($saleIds)) {
                $seenPaymentIds = [];
                foreach (array_chunk($saleIds, 400) as $chunkIdx => $idChunk) {
                    $pq = DB::table('sale_payments')
                        ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                        ->whereNotIn('sales.status', ['Voided', 'Cancelled', 'Draft', 'Pending', 'Ordered'])
                        ->when($hasLateOfflineCol, fn ($q) => $q->where(function ($sub) {
                            $sub->whereNull('sales.late_offline_reconciliation')->orWhere('sales.late_offline_reconciliation', false);
                        }))
                        ->where(function ($q) use ($register, $idChunk, $openedAt, $closedAt, $chunkIdx) {
                            if ($chunkIdx === 0) {
                                $q->where('sale_payments.cash_register_id', $register->id)
                                  ->orWhere(function ($sub) use ($register, $idChunk, $openedAt, $closedAt) {
                                      $sub->whereNull('sale_payments.cash_register_id')
                                          ->whereIn('sale_payments.sale_id', $idChunk)
                                          ->whereBetween('sale_payments.created_at', [$openedAt, $closedAt]);
                                      if ($register->user_id) {
                                          $sub->where('sale_payments.user_id', $register->user_id);
                                      }
                                  });
                            } else {
                                $q->whereNull('sale_payments.cash_register_id')
                                  ->whereIn('sale_payments.sale_id', $idChunk)
                                  ->whereBetween('sale_payments.created_at', [$openedAt, $closedAt]);
                                if ($register->user_id) {
                                    $q->where('sale_payments.user_id', $register->user_id);
                                }
                            }
                        })
                        ->get(['sale_payments.*']);
                    foreach ($pq as $pRow) {
                        if (!isset($seenPaymentIds[$pRow->id])) {
                            $seenPaymentIds[$pRow->id] = true;
                            $payments->push($pRow);
                        }
                    }
                }
            } else {
                $payments = DB::table('sale_payments')
                    ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                    ->whereNotIn('sales.status', ['Voided', 'Cancelled', 'Draft', 'Pending', 'Ordered'])
                    ->when($hasLateOfflineCol, fn ($q) => $q->where(function ($sub) {
                        $sub->whereNull('sales.late_offline_reconciliation')->orWhere('sales.late_offline_reconciliation', false);
                    }))
                    ->where('sale_payments.cash_register_id', $register->id)
                    ->get(['sale_payments.*']);
            }
        } elseif (!empty($saleIds)) {
            foreach (array_chunk($saleIds, 400) as $idChunk) {
                $pq = DB::table('sale_payments')
                    ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
                    ->whereNotIn('sales.status', ['Voided', 'Cancelled', 'Draft', 'Pending', 'Ordered'])
                    ->when($hasLateOfflineCol, fn ($q) => $q->where(function ($sub) {
                        $sub->whereNull('sales.late_offline_reconciliation')->orWhere('sales.late_offline_reconciliation', false);
                    }))
                    ->whereIn('sale_payments.sale_id', $idChunk)
                    ->whereBetween('sale_payments.created_at', [$openedAt, $closedAt])
                    ->get(['sale_payments.*']);
                foreach ($pq as $pRow) {
                    $payments->push($pRow);
                }
            }
        }

        $allPaymentSaleIdSet = [];
        if (!empty($saleIds)) {
            foreach (array_chunk($saleIds, 400) as $idChunk) {
                foreach (DB::table('sale_payments')->whereIn('sale_id', $idChunk)->distinct()->pluck('sale_id') as $psId) {
                    $allPaymentSaleIdSet[(int)$psId] = true;
                }
            }
        }

        $tenderTotals = [];
        $tenderCounts = [];

        foreach ($payments as $p) {
            $rawMethod = (string)($p->payment_type ?? 'Cash');
            $methodName = $this->normalizePaymentMethodName($rawMethod);
            $netAmount = max(0.0, (float)$p->amount);

            $tenderTotals[$methodName] = ($tenderTotals[$methodName] ?? 0.0) + $netAmount;
            $tenderCounts[$methodName] = ($tenderCounts[$methodName] ?? 0) + 1;
        }

        // Include any sales in this session that had no sale_payments records at all (O(1) hash check)
        foreach ($sales as $s) {
            if (isset($allPaymentSaleIdSet[(int)$s->id])) {
                continue;
            }
            $rawMethod = (string)($s->payment_type ?? 'Cash');
            $methodName = $this->normalizePaymentMethodName($rawMethod);
            $paid = (float)$s->paid_amount;
            if ($paid > 0) {
                $tenderTotals[$methodName] = ($tenderTotals[$methodName] ?? 0.0) + $paid;
                $tenderCounts[$methodName] = ($tenderCounts[$methodName] ?? 0) + 1;
            }
        }

        // Categorize tenders for backward compatibility with root scalar fields
        $cashSales = (float)($tenderTotals['Cash'] ?? 0.0);
        $chequeSales = (float)($tenderTotals['Cheque'] ?? 0.0);
        $bankTransferSales = (float)($tenderTotals['Bank Transfer'] ?? 0.0);
        $cardSales = 0.0;
        $digitalSales = 0.0;
        $otherSales = 0.0;

        foreach ($tenderTotals as $m => $amt) {
            if ($m === 'Cash' || $m === 'Cheque' || $m === 'Bank Transfer') {
                continue;
            }
            $mLower = strtolower($m);
            if (str_contains($mLower, 'card') || in_array($mLower, ['visa', 'mastercard'])) {
                $cardSales += $amt;
            } elseif (in_array($m, ['KBZPay', 'KPay', 'WavePay', 'AYA Pay', 'CB Pay', 'OnePay', 'UABPay', 'Mobile Wallet']) || str_contains($mLower, 'pay') || str_contains($mLower, 'wallet')) {
                $digitalSales += $amt;
            } else {
                $otherSales += $amt;
            }
        }

        // 3. Query Completed refunds/returns during this register session (register-isolated & split-payment aware)
        $hasSrRegCol = $accounting->schemaHasColumn('sale_returns', 'cash_register_id');
        $hasSrSplitCol = $accounting->schemaHasColumn('sale_returns', 'has_register_split');
        $returnsQuery = DB::table('sale_returns')->where('status', 'Completed');
        if ($hasSrRegCol) {
            $returnsQuery->where(function ($q) use ($register, $openedAt, $closedAt, $hasSrSplitCol) {
                $q->where('cash_register_id', $register->id);
                if ($hasSrSplitCol) {
                    $q->orWhere('has_register_split', true);
                } else {
                    $q->orWhere('payments_payload', 'like', '%"cash_register_id":' . $register->id . '%');
                }
                $q->orWhere(function ($sub) use ($register, $openedAt, $closedAt) {
                      $sub->whereNull('cash_register_id')
                          ->where('warehouse_name', $register->warehouse_name)
                          ->whereBetween('created_at', [$openedAt, $closedAt]);
                      if ($register->user_id) {
                          $sub->where('user_id', $register->user_id);
                      }
                  });
            });
        } else {
            $returnsQuery->where('warehouse_name', $register->warehouse_name)
                ->whereBetween('created_at', [$openedAt, $closedAt])
                ->when($register->user_id, fn($q) => $q->where('user_id', $register->user_id));
        }

        $returns = $returnsQuery->get(['id', 'cash_register_id', 'grand_total', 'paid_amount', 'refund_tender', 'payment_type', 'payments_payload']);

        $totalRefunds = 0.0;
        $cashRefunds = 0.0;
        foreach ($returns as $r) {
            $retTotal = (float)($r->grand_total ?? 0);
            $totalRefunds += $retTotal;
            $payloadLines = !empty($r->payments_payload) ? json_decode((string)$r->payments_payload, true) : null;
            if (is_array($payloadLines) && !empty($payloadLines)) {
                foreach ($payloadLines as $line) {
                    if (!is_array($line)) continue;
                    $legRegId = isset($line['cash_register_id']) && $line['cash_register_id'] !== ''
                        ? (int)$line['cash_register_id']
                        : (!empty($r->cash_register_id) ? (int)$r->cash_register_id : null);
                    if ($legRegId !== null && $legRegId !== (int)$register->id) {
                        continue;
                    }
                    $legType = strtolower(trim((string)($line['payment_type'] ?? '')));
                    if ($legType === 'cash' || str_contains($legType, 'cash')) {
                        $cashRefunds += max(0.0, (float)($line['amount'] ?? 0));
                    }
                }
            } else {
                if (!empty($r->cash_register_id) && (int)$r->cash_register_id !== (int)$register->id) {
                    continue;
                }
                $tender = strtolower(trim((string)($r->refund_tender ?? $r->payment_type ?? 'cash')));
                $paidOut = max(0.0, (float)($r->paid_amount ?? 0));
                if ($paidOut > 0 && ($tender === 'cash' || str_contains($tender, 'cash'))) {
                    $cashRefunds += $paidOut;
                }
            }
        }

        // 4. Query paid expenses during this shift using expense_payments ledger (never deduct Unpaid expenses)
        $hasExpRegCol = $accounting->schemaHasColumn('expenses', 'cash_register_id');
        $hasEpRegCol = $accounting->schemaHasColumn('expense_payments', 'cash_register_id');
        $expensePaymentsQuery = DB::table('expense_payments')
            ->join('expenses', 'expense_payments.expense_id', '=', 'expenses.id');

        if ($hasEpRegCol) {
            $expensePaymentsQuery->where(function ($q) use ($register, $hasExpRegCol, $openedAt, $closedAt) {
                $q->where('expense_payments.cash_register_id', $register->id)
                  ->orWhere(function ($sub) use ($register, $hasExpRegCol, $openedAt, $closedAt) {
                      $sub->whereNull('expense_payments.cash_register_id')
                          ->where(function ($expMatch) use ($register, $hasExpRegCol, $openedAt, $closedAt) {
                              if ($hasExpRegCol) {
                                  $expMatch->where('expenses.cash_register_id', $register->id)
                                      ->orWhere(function ($legacy) use ($register, $openedAt, $closedAt) {
                                          $legacy->whereNull('expenses.cash_register_id')
                                              ->where('expenses.warehouse_name', $register->warehouse_name)
                                              ->whereBetween('expense_payments.created_at', [$openedAt, $closedAt]);
                                          if ($register->user_id) {
                                              $legacy->where('expense_payments.user_id', $register->user_id);
                                          }
                                      });
                              } else {
                                  $expMatch->where('expenses.warehouse_name', $register->warehouse_name)
                                      ->whereBetween('expense_payments.created_at', [$openedAt, $closedAt]);
                                  if ($register->user_id) {
                                      $expMatch->where('expense_payments.user_id', $register->user_id);
                                  }
                              }
                          });
                  });
            });
        } else {
            $expensePaymentsQuery->where('expenses.warehouse_name', $register->warehouse_name)
                ->whereBetween('expense_payments.created_at', [$openedAt, $closedAt])
                ->when($register->user_id, fn($q) => $q->where('expense_payments.user_id', $register->user_id));
        }

        $expensePaymentRows = $expensePaymentsQuery->get(['expense_payments.amount', 'expense_payments.payment_type']);

        $totalExpenses = 0.0;
        $cashExpenses = 0.0;
        $digitalExpenses = 0.0;
        foreach ($expensePaymentRows as $ep) {
            $amt = max(0.0, (float)($ep->amount ?? 0));
            $totalExpenses += $amt;
            $pMethod = strtolower(trim((string)($ep->payment_type ?? 'cash')));
            if ($pMethod === 'cash' || str_contains($pMethod, 'cash')) {
                $cashExpenses += $amt;
            } else {
                $digitalExpenses += $amt;
            }
        }

        // Legacy expenses without expense_payments rows (SQL NOT EXISTS, zero N+1)
        $legacyExpenseQuery = DB::table('expenses')
            ->where('paid_amount', '>', 0)
            ->whereIn('payment_status', ['Paid', 'Partial'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('expense_payments')
                    ->whereColumn('expense_payments.expense_id', 'expenses.id');
            });
        if ($hasExpRegCol) {
            $legacyExpenseQuery->where(function ($q) use ($register, $openedAt, $closedAt) {
                $q->where('cash_register_id', $register->id)
                  ->orWhere(function ($sub) use ($register, $openedAt, $closedAt) {
                      $sub->whereNull('cash_register_id')
                          ->where('warehouse_name', $register->warehouse_name)
                          ->whereBetween('created_at', [$openedAt, $closedAt]);
                      if ($register->user_id) {
                          $sub->where('user_id', $register->user_id);
                      }
                  });
            });
        } else {
            $legacyExpenseQuery->where('warehouse_name', $register->warehouse_name)
                ->whereBetween('created_at', [$openedAt, $closedAt])
                ->when($register->user_id, fn($q) => $q->where('user_id', $register->user_id));
        }
        foreach ($legacyExpenseQuery->get(['id', 'paid_amount', 'payment_method']) as $exp) {
            $paidAmt = max(0.0, (float)($exp->paid_amount ?? 0));
            $totalExpenses += $paidAmt;
            $pMethod = strtolower(trim((string)($exp->payment_method ?? 'cash')));
            if ($pMethod === 'cash' || str_contains($pMethod, 'cash')) {
                $cashExpenses += $paidAmt;
            } else {
                $digitalExpenses += $paidAmt;
            }
        }

        // 5. Query till safe drops & float additions for this register
        $registerTxList = DB::table('cash_register_transactions')
            ->where('cash_register_id', $register->id)
            ->orderBy('id', 'desc')
            ->get();

        $floatsIn = 0.0;
        $cashDrops = 0.0;
        foreach ($registerTxList as $rtx) {
            $amt = (float)($rtx->amount ?? 0);
            if ($rtx->type === 'float_in') {
                $floatsIn += $amt;
            } elseif ($rtx->type === 'drop') {
                $cashDrops += $amt;
            } elseif ($rtx->type === 'void_refund') {
                $cashRefunds += $amt;
                $totalRefunds += $amt;
            }
        }

        // 5b. Query linked Cash Purchases Paid (outflow) & Cash Purchase Return Refunds (inflow) on this register
        $purchaseCashFlow = $accounting->summarizeRegisterPurchaseCashFlow((int)$register->id);
        $cashPurchasesPaid = $purchaseCashFlow['cash_purchases_paid'];
        $cashPurchaseRefunds = $purchaseCashFlow['cash_purchase_refunds'];

        $openingCash = (float)$register->opening_cash;
        // Accurate GAAP/IFRS Till Reconciliation Formula:
        // Expected Cash = Opening Cash + Net Cash Sales + Floats In + Cash Purchase Refunds - Safe Drops - Cash Refunds - Cash Expenses - Cash Purchases Paid
        $isClosedReg = !in_array(strtolower(trim((string)$register->status)), ['open', 'opened'], true);
        $recomputedExpected = round($openingCash + $cashSales + $floatsIn + $cashPurchaseRefunds - $cashDrops - $cashRefunds - $cashExpenses - $cashPurchasesPaid, 2);
        // Closed shift expected_cash stays frozen at closing snapshot (prevents retroactive mutation)
        $expectedCash = ($isClosedReg && $register->expected_cash !== null)
            ? (float)$register->expected_cash
            : $recomputedExpected;
        $closingCash = $register->closing_cash !== null ? (float)$register->closing_cash : $expectedCash;
        $difference = $register->cash_difference !== null ? (float)$register->cash_difference : ($closingCash - $expectedCash);

        $lateOfflineRows = $accounting->schemaHasTable('late_offline_reconciliations')
            ? DB::table('late_offline_reconciliations')
                ->where(function ($q) use ($register) {
                    $q->where('original_cash_register_id', $register->id)
                      ->orWhere('syncing_open_register_id', $register->id);
                })
                ->orderByDesc('id')
                ->get(['id', 'sale_reference_no', 'sale_grand_total', 'cash_amount', 'non_cash_amount', 'sale_date', 'synced_at', 'reason', 'status'])
            : collect();

        // ACC-04: Blind Cash Reconciliation - hide expected cash & variance from cashiers on open shifts
        $canViewExpected = $forInternalCalculation || ($user ? $user->hasPermission('cash_register.view_expected') : false);
        $hideExpected = (strtolower((string)$register->status) === 'open' && !$canViewExpected);

        $paymentBreakdown = [];
        // Cash is always listed first
        $paymentBreakdown[] = [
            'method' => 'Cash',
            'amount' => round($cashSales, 2),
            'count' => (int)($tenderCounts['Cash'] ?? 0),
        ];

        // Add every other tender that has recorded sales
        foreach ($tenderTotals as $m => $amt) {
            if ($m === 'Cash') continue;
            $cnt = (int)($tenderCounts[$m] ?? 0);
            if ($amt > 0 || $cnt > 0) {
                $paymentBreakdown[] = [
                    'method' => $m,
                    'amount' => round($amt, 2),
                    'count' => $cnt,
                ];
            }
        }

        $totalCollections = round(array_sum($tenderTotals), 2);
        // Ultimate POS Standard: Total Payment in Register Summary is Gross Inflow (Cash In Hand + Total Collections)
        $totalPayment = round($openingCash + $totalCollections, 2);

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
            'cheque_sales' => round($chequeSales, 2),
            'bank_transfer_sales' => round($bankTransferSales, 2),
            'card_sales' => round($cardSales, 2),
            'digital_sales' => round($digitalSales, 2),
            'other_sales' => round($otherSales, 2),
            'total_collections' => $totalCollections,
            'total_payment' => $totalPayment,
            'total_refunds' => round($totalRefunds, 2),
            'cash_refunds' => round($cashRefunds, 2),
            'cash_purchases_paid' => round($cashPurchasesPaid, 2),
            'cash_purchase_refunds' => round($cashPurchaseRefunds, 2),
            'total_expenses' => round($totalExpenses, 2),
            'cash_expenses' => round($cashExpenses, 2),
            'digital_expenses' => round($digitalExpenses, 2),
            'floats_in' => round($floatsIn, 2),
            'cash_drops' => round($cashDrops, 2),
            'safe_drops' => round($cashDrops, 2),
            'expected_cash' => $hideExpected ? null : round($expectedCash, 2),
            'closing_cash' => round($closingCash, 2),
            'difference' => $hideExpected ? null : round($difference, 2),
            'status' => ucfirst($register->status),
            'primary_payment_type' => 'Cash',
            'notes' => (string)($register->opening_notes ?? $register->closing_notes ?? ''),
            'closing_notes' => (string)($register->closing_notes ?? ''),
            'created_by' => $register->user_name ?? 'Super Admin',
            'cashier_name' => $register->user_name ?? 'Super Admin',
            'user_name' => $register->user_name ?? 'Super Admin',
            'user_role' => 'Lead Cashier',
            'payment_breakdown' => $paymentBreakdown,
            'recent_transactions' => $sales->take(20)->values(),
            'denominations' => $register->denominations,
            'late_offline_reconciliations' => $lateOfflineRows->map(fn ($r) => [
                'id' => (int)$r->id,
                'sale_reference_no' => (string)$r->sale_reference_no,
                'sale_grand_total' => (float)$r->sale_grand_total,
                'cash_amount' => (float)$r->cash_amount,
                'non_cash_amount' => (float)$r->non_cash_amount,
                'sale_date' => (string)$r->sale_date,
                'synced_at' => (string)$r->synced_at,
                'reason' => (string)$r->reason,
                'status' => (string)$r->status,
            ])->values(),
            'register_transactions' => $registerTxList->map(function ($rtx) {
                return [
                    'id' => (int)$rtx->id,
                    'type' => $rtx->type,
                    'amount' => (float)$rtx->amount,
                    'reason' => $rtx->reason ?? '',
                    'authorized_by' => $rtx->authorized_by ?? '',
                    'manager_pin_verified' => (bool)$rtx->manager_pin_verified,
                    'created_at' => (string)$rtx->created_at,
                ];
            })->values(),
        ];
    }
}
