<?php

namespace App\Traits;

use App\Models\ReferenceCounter;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait GeneratesSequentialCode
{
    /**
     * Map transaction types to Eloquent models.
     */
    protected function getModelForType(string $type): string
    {
        return match ($type) {
            'sale' => \App\Models\Sale::class,
            'sale_return' => \App\Models\SaleReturn::class,
            'purchase' => \App\Models\Purchase::class,
            'purchase_return' => \App\Models\PurchaseReturn::class,
            'quotation' => \App\Models\Quotation::class,
            'adjustment' => \App\Models\StockAdjustment::class,
            'transfer' => \App\Models\StockTransfer::class,
            'expense' => \App\Models\Expense::class,
            default => throw new \InvalidArgumentException("Unknown transaction type: {$type}"),
        };
    }

    /**
     * Map transaction types to Setting prefix keys.
     */
    protected function getPrefixSettingKey(string $type): string
    {
        return match ($type) {
            'sale' => 'sale_prefix',
            'sale_return' => 'sale_return_prefix',
            'purchase' => 'purchase_prefix',
            'purchase_return' => 'purchase_return_prefix',
            'quotation' => 'quotation_prefix',
            'adjustment' => 'adjustment_prefix',
            'transfer' => 'transfer_prefix',
            'expense' => 'expense_prefix',
            default => "{$type}_prefix",
        };
    }

    /**
     * Default prefix fallback if not configured in settings.
     */
    protected function getDefaultPrefix(string $type): string
    {
        return match ($type) {
            'sale' => 'SA_',
            'sale_return' => 'SR_',
            'purchase' => 'PO_',
            'purchase_return' => 'PR_',
            'quotation' => 'QUO_',
            'adjustment' => 'Ak_',
            'transfer' => 'TK_',
            'expense' => 'EXP_',
            default => strtoupper(substr($type, 0, 3)) . '_',
        };
    }

    /**
     * Resolve effective prefix from settings or custom parameter.
     */
    public function resolvePrefix(string $type, ?string $customPrefix = null): string
    {
        if (!empty($customPrefix)) {
            return trim($customPrefix);
        }

        $settingKey = $this->getPrefixSettingKey($type);
        $prefix = Setting::get($settingKey);

        if (empty($prefix)) {
            $prefix = $this->getDefaultPrefix($type);
        }

        return trim($prefix);
    }

    /**
     * Find highest numeric sequence currently used in the database for this type.
     */
    public function findMaxExistingSeq(string $type, string $prefix): int
    {
        $modelClass = $this->getModelForType($type);

        // 1. Check max('id') as baseline
        $maxId = (int)$modelClass::max('id');

        // 2. Fetch recent records with this prefix or general pattern to extract maximum numeric sequence
        $latestRecords = $modelClass::where('reference_no', 'like', $prefix . '%')
            ->orWhere('reference_no', 'like', '%' . substr($prefix, 0, 2) . '%')
            ->orderBy('id', 'desc')
            ->take(500)
            ->pluck('reference_no');

        $maxSeq = max($maxId, 10000);

        foreach ($latestRecords as $ref) {
            if (empty($ref)) {
                continue;
            }
            // Extract trailing sequence digits
            if (preg_match('/(\d+)$/', (string)$ref, $m)) {
                $num = (int)$m[1];
                if ($num > $maxSeq && $num < 99999999) {
                    $maxSeq = $num;
                }
            }
        }

        return $maxSeq;
    }

    /**
     * Peek next sequential code for UI display without burning the sequence.
     */
    public function peekNextSequentialCode(string $type, ?int $outletId = 0, ?string $customPrefix = null): array
    {
        $prefix = $this->resolvePrefix($type, $customPrefix);
        $outletId = $outletId ?: 0;
        $modelClass = $this->getModelForType($type);

        $counter = ReferenceCounter::where('type', $type)
            ->where('prefix', $prefix)
            ->where('outlet_id', $outletId)
            ->first();

        if (!$counter) {
            $maxSeq = $this->findMaxExistingSeq($type, $prefix);
            $nextNum = $maxSeq + 1;
        } else {
            $nextNum = $counter->last_seq + 1;
        }

        $formattedCode = $prefix . sprintf('%07d', $nextNum);

        // Advance if by chance a record already exists with this formatted code
        while ($modelClass::where('reference_no', $formattedCode)->exists()) {
            $nextNum++;
            $formattedCode = $prefix . sprintf('%07d', $nextNum);
        }

        return [
            'status'         => 'success',
            'code'           => $formattedCode,
            'numeric_code'   => (string)$nextNum,
            'reference_code' => $formattedCode,
            'reference_no'   => $formattedCode,
            'next_number'    => $nextNum,
            'data'           => [
                'code'         => (string)$nextNum,
                'reference_no' => $formattedCode,
                'next_number'  => $nextNum,
            ],
        ];
    }

    /**
     * Atomically generate and increment the next sequential code in InnoDB.
     */
    public function generateNextSequentialCode(string $type, ?int $outletId = 0, ?string $customPrefix = null): string
    {
        $prefix = $this->resolvePrefix($type, $customPrefix);
        $outletId = $outletId ?: 0;
        $modelClass = $this->getModelForType($type);

        return DB::transaction(function () use ($type, $prefix, $outletId, $modelClass) {
            $counter = ReferenceCounter::where('type', $type)
                ->where('prefix', $prefix)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->first();

            if (!$counter) {
                $maxSeq = $this->findMaxExistingSeq($type, $prefix);
                $counter = ReferenceCounter::create([
                    'type'      => $type,
                    'prefix'    => $prefix,
                    'outlet_id' => $outletId,
                    'last_seq'  => $maxSeq,
                ]);
                $counter = ReferenceCounter::where('id', $counter->id)->lockForUpdate()->first();
            }

            $nextNum = $counter->last_seq + 1;
            $formattedCode = $prefix . sprintf('%07d', $nextNum);

            // Ensure no collision with manually inserted or legacy records
            while ($modelClass::where('reference_no', $formattedCode)->exists()) {
                $nextNum++;
                $formattedCode = $prefix . sprintf('%07d', $nextNum);
            }

            $counter->last_seq = $nextNum;
            $counter->save();

            return $formattedCode;
        });
    }

    /**
     * Acquire a guaranteed unique, sequential reference number upon creation.
     */
    public function acquireUniqueReference(string $type, ?string $requestedRef, ?int $outletId = 0, ?string $customPrefix = null): string
    {
        $modelClass = $this->getModelForType($type);
        $cleanRef = $requestedRef ? trim($requestedRef) : null;

        if (!empty($cleanRef)) {
            $exists = $modelClass::where('reference_no', $cleanRef)->exists();
            if (!$exists) {
                // If it's formatted with the current prefix, advance counter to prevent future collision
                $prefix = $this->resolvePrefix($type, $customPrefix);
                if (str_starts_with($cleanRef, $prefix)) {
                    $digits = preg_replace('/\D/', '', substr($cleanRef, strlen($prefix)));
                    if (!empty($digits)) {
                        $num = (int)$digits;
                        $counter = ReferenceCounter::where('type', $type)
                            ->where('prefix', $prefix)
                            ->where('outlet_id', $outletId ?: 0)
                            ->lockForUpdate()
                            ->first();
                        if ($counter && $num > $counter->last_seq) {
                            $counter->last_seq = $num;
                            $counter->save();
                        }
                    }
                }
                return $cleanRef;
            }
        }

        // Empty or duplicate reference: atomically generate the next sequential code
        return $this->generateNextSequentialCode($type, $outletId, $customPrefix);
    }
}
