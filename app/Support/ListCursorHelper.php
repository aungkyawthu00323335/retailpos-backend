<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Shared keyset cursor pagination helper for all 9 list endpoints.
 *
 * Uses composite `(sortColumn, id)` tuple predicates so deep pagination pages
 * perform O(log N) B-Tree index seeks instead of linear OFFSET table scans.
 */
final class ListCursorHelper
{
    public static function encode(
        object $row,
        string $sortColumn,
        string $sortOrder,
        array $allowedColumns,
        string $idColumn = 'id',
        ?string $sortAlias = null
    ): ?string {
        $key = $sortAlias ?? $sortColumn;
        $rawCol = str_contains($key, '.') ? substr($key, strpos($key, '.') + 1) : $key;
        $rawIdCol = str_contains($idColumn, '.') ? substr($idColumn, strpos($idColumn, '.') + 1) : $idColumn;

        if (!in_array($sortColumn, $allowedColumns, true) && !in_array($rawCol, $allowedColumns, true)) {
            return null;
        }

        $id = isset($row->{$rawIdCol}) ? (int)$row->{$rawIdCol} : 0;
        $val = $row instanceof \Illuminate\Database\Eloquent\Model
            ? ($row->getRawOriginal($rawCol) ?? $row->getAttribute($rawCol))
            : ($row->{$rawCol} ?? null);
        if ($val instanceof \DateTimeInterface) {
            $val = $val->format('Y-m-d H:i:s');
        }
        if ($id <= 0 || $val === null) {
            return null;
        }

        $json = json_encode([
            's' => $rawCol,
            'o' => strtolower($sortOrder) === 'asc' ? 'asc' : 'desc',
            'v' => $val,
            'id' => $id,
        ]);
        if ($json === false) {
            return null;
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(
        ?string $raw,
        string $sortColumn,
        string $sortOrder,
        array $allowedColumns,
        array $intColumns = ['id'],
        array $floatColumns = []
    ): ?array {
        $rawSort = str_contains($sortColumn, '.') ? substr($sortColumn, strpos($sortColumn, '.') + 1) : $sortColumn;
        if (
            $raw === null
            || $raw === ''
            || (!in_array($sortColumn, $allowedColumns, true) && !in_array($rawSort, $allowedColumns, true))
        ) {
            return null;
        }

        $padded = str_pad(strtr($raw, '-_', '+/'), strlen($raw) + (4 - strlen($raw) % 4) % 4, '=');
        $decodedJson = base64_decode($padded, true);
        if ($decodedJson === false) {
            return null;
        }

        $data = json_decode($decodedJson, true);
        $expectedOrder = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';
        if (!is_array($data) || ($data['s'] ?? null) !== $rawSort || ($data['o'] ?? null) !== $expectedOrder) {
            return null;
        }

        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0 || !array_key_exists('v', $data) || $data['v'] === null) {
            return null;
        }

        $val = $data['v'];
        if (in_array($rawSort, $intColumns, true) || in_array($sortColumn, $intColumns, true)) {
            $val = (int)$val;
        } elseif (in_array($rawSort, $floatColumns, true) || in_array($sortColumn, $floatColumns, true)) {
            $val = (float)$val;
        } else {
            $val = (string)$val;
        }

        return ['v' => $val, 'id' => $id];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|Builder  $query
     */
    public static function apply($query, string $sortColumn, string $sortOrder, array $cursor, string $idColumn = 'id'): void
    {
        $op = strtolower($sortOrder) === 'desc' ? '<' : '>';
        $boundOp = strtolower($sortOrder) === 'desc' ? '<=' : '>=';
        $cid = (int)$cursor['id'];
        $rawSort = str_contains($sortColumn, '.') ? substr($sortColumn, strpos($sortColumn, '.') + 1) : $sortColumn;
        $rawId = str_contains($idColumn, '.') ? substr($idColumn, strpos($idColumn, '.') + 1) : $idColumn;

        if ($rawSort === $rawId) {
            $query->where($idColumn, $op, $cid);
            return;
        }

        $val = $cursor['v'];
        $query->where($sortColumn, $boundOp, $val)
            ->where(function ($sub) use ($sortColumn, $idColumn, $op, $val, $cid) {
                $sub->where($sortColumn, $op, $val)
                    ->orWhere(function ($tie) use ($sortColumn, $idColumn, $op, $val, $cid) {
                        $tie->where($sortColumn, '=', $val)->where($idColumn, $op, $cid);
                    });
            });
    }
}
