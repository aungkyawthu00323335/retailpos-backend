<?php

namespace App\Models\Concerns;

/**
 * Maintains an indexed `has_register_split` boolean on models that store split legs inside `payments_payload`,
 * avoiding leading-wildcard LIKE scans across transaction tables.
 */
trait TracksRegisterSplitFlag
{
    public static function payloadHasRegisterSplit(?string $rawPayload): bool
    {
        if ($rawPayload === null || $rawPayload === '' || !str_contains($rawPayload, 'cash_register_id')) {
            return false;
        }
        $lines = json_decode($rawPayload, true);
        if (!is_array($lines)) {
            return false;
        }
        foreach ($lines as $line) {
            if (is_array($line) && !empty($line['cash_register_id'])) {
                return true;
            }
        }
        return false;
    }

    private static array $columnCache = [];

    public static function bootTracksRegisterSplitFlag(): void
    {
        static::saving(function ($model): void {
            $table = $model->getTable();
            if (!array_key_exists($table, self::$columnCache)) {
                self::$columnCache[$table] = \Illuminate\Support\Facades\Schema::hasColumn($table, 'has_register_split');
            }
            if (self::$columnCache[$table]) {
                $model->has_register_split = static::payloadHasRegisterSplit(
                    is_string($model->payments_payload) ? $model->payments_payload : null
                );
            }
        });
    }
}
