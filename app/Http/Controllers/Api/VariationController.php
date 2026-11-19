<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\ProductVariantValue;
use App\Models\Variation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VariationController extends Controller
{
    private const CACHE_KEY = 'api_variations_list';
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Display a listing of variations.
     */
    public function index(Request $request)
    {
        $hasSearch = $request->filled('search');
        $hasStatus = $request->filled('status') && $request->input('status') !== 'All';
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = strtolower((string) $request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $isDefaultSort = $sortBy === 'id' && $sortOrder === 'desc';

        if (! $hasSearch && ! $hasStatus && $isDefaultSort) {
            $variations = $this->formatListingRows(
                $this->buildListingQuery('id', 'desc')->get()
            );

            return response()->json([
                'status' => 'success',
                'data' => $variations,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        $query = $this->buildListingQuery($sortBy, $sortOrder);

        if ($hasSearch) {
            $search = trim((string) $request->input('search'));
            $query->where('v.name', 'like', "%{$search}%");
        }

        if ($hasStatus) {
            $query->where('v.status', $request->input('status'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatListingRows($query->get()),
        ]);
    }

    private function buildListingQuery(string $sortBy, string $sortOrder)
    {
        $productCountsSub = DB::table('product_variant_values as pvv')
            ->join('product_variants as pv', 'pvv.product_variant_id', '=', 'pv.id')
            ->selectRaw('pvv.attribute_name, COUNT(DISTINCT pv.product_id) as products_count')
            ->whereNull('pv.deleted_at')
            ->groupBy('pvv.attribute_name');

        $query = DB::table('variations as v')
            ->leftJoinSub($productCountsSub, 'var_counts', function ($join) {
                $join->on('v.name', '=', 'var_counts.attribute_name');
            })
            ->select([
                'v.id',
                'v.name',
                'v.variation_types',
                'v.status',
                'v.created_at',
                'v.updated_at',
                DB::raw('COALESCE(var_counts.products_count, 0) as products_count'),
            ]);

        if (in_array($sortBy, ['id', 'name', 'status', 'created_at'], true)) {
            $query->orderBy("v.{$sortBy}", $sortOrder);
        } else {
            $query->orderByDesc('v.id');
        }

        return $query;
    }

    private function formatListingRows($rows): array
    {
        return collect($rows)->map(static function ($row) {
            $types = is_array($row->variation_types)
                ? $row->variation_types
                : (json_decode((string) ($row->variation_types ?? '[]'), true) ?: []);

            return [
                'id' => (int) $row->id,
                'name' => $row->name,
                'variation_types' => array_values($types),
                'status' => $row->status,
                'products_count' => (int) ($row->products_count ?? 0),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        })->all();
    }

    private function clearListCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Store a newly created variation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:variations,name',
            'variation_types' => 'required|array|min:1',
            'variation_types.*' => 'required|string|max:100',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $variation = Variation::create([
            'name' => trim($validated['name']),
            'variation_types' => array_values(array_unique(array_map('trim', $validated['variation_types']))),
            'status' => $validated['status'] ?? 'Active',
        ]);

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Variation created successfully',
            'data' => $variation,
        ], 201);
    }

    /**
     * Display the specified variation.
     */
    public function show($id)
    {
        $variation = Variation::find($id);

        if (!$variation) {
            return response()->json(['status' => 'error', 'message' => 'Variation not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $variation,
        ]);
    }

    /**
     * Update the specified variation.
     */
    public function update(Request $request, $id)
    {
        $variation = Variation::find($id);

        if (!$variation) {
            return response()->json(['status' => 'error', 'message' => 'Variation not found'], 404);
        }

        $oldName = $variation->name;
        $oldTypes = is_array($variation->variation_types) ? $variation->variation_types : [];

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:variations,name,' . $id,
            'variation_types' => 'sometimes|required|array|min:1',
            'variation_types.*' => 'required|string|max:100',
            'type_renames' => 'nullable|array',
            'status' => 'nullable|string|in:Active,Inactive',
        ]);

        $newName = isset($validated['name']) ? trim($validated['name']) : $oldName;
        $newTypes = isset($validated['variation_types'])
            ? array_values(array_unique(array_map('trim', $validated['variation_types'])))
            : $oldTypes;

        $validated['name'] = $newName;
        $validated['variation_types'] = $newTypes;

        // 1. Detect / Collect Type Renames
        $renames = [];
        if ($request->filled('type_renames') && is_array($request->input('type_renames'))) {
            foreach ($request->input('type_renames') as $k => $v) {
                if (!empty($k) && !empty($v) && $k !== $v) {
                    $renames[trim($k)] = trim($v);
                }
            }
        }

        // Auto-detect positional renames if same count
        if (empty($renames) && count($oldTypes) === count($newTypes)) {
            for ($i = 0; $i < count($oldTypes); $i++) {
                if ($oldTypes[$i] !== $newTypes[$i]) {
                    $renames[$oldTypes[$i]] = $newTypes[$i];
                }
            }
        }

        // Auto-detect single replacement if 1 removed and 1 added
        if (empty($renames)) {
            $removed = array_values(array_diff($oldTypes, $newTypes));
            $added = array_values(array_diff($newTypes, $oldTypes));
            if (count($removed) === 1 && count($added) === 1) {
                $renames[$removed[0]] = $added[0];
            }
        }

        \DB::transaction(function () use ($variation, $validated, $oldName, $newName, $renames) {
            // Update variation record
            $variation->update([
                'name' => $validated['name'],
                'variation_types' => $validated['variation_types'],
                'status' => $validated['status'] ?? $variation->status,
            ]);

            // Synchronize ProductVariantValue and ProductVariant records across all combinations
            foreach ($renames as $oldVal => $newVal) {
                // 1. Find all variant value records matching this old value
                $variantValues = \App\Models\ProductVariantValue::where(function ($q) use ($oldName, $newName) {
                    $q->where('attribute_name', $oldName)
                      ->orWhere('attribute_name', $newName);
                })
                ->where('attribute_value', (string)$oldVal)
                ->get();

                $variantIds = $variantValues->pluck('product_variant_id')->unique()->filter()->toArray();

                // 2. Also find any ProductVariant having this value in name across any color/prefix/suffix
                $directVariants = \App\Models\ProductVariant::where('name', (string)$oldVal)
                    ->orWhere('name', 'like', "% - {$oldVal}")
                    ->orWhere('name', 'like', "{$oldVal} - %")
                    ->orWhere('name', 'like', "% - {$oldVal} - %")
                    ->orWhere('name', 'like', "% / {$oldVal}%")
                    ->orWhere('name', 'like', "%{$oldVal} / %")
                    ->get();

                $allVariantIds = array_unique(array_merge($variantIds, $directVariants->pluck('id')->toArray()));

                $variants = \App\Models\ProductVariant::whereIn('id', $allVariantIds)->get();

                foreach ($variants as $variant) {
                    $originalName = $variant->name;
                    $parts = explode(' - ', $originalName);
                    $modified = false;
                    for ($p = 0; $p < count($parts); $p++) {
                        if (trim($parts[$p]) === (string)$oldVal) {
                            $parts[$p] = (string)$newVal;
                            $modified = true;
                        }
                    }
                    if ($modified) {
                        $variant->update(['name' => implode(' - ', $parts)]);
                    } else if ($originalName === (string)$oldVal) {
                        $variant->update(['name' => (string)$newVal]);
                    } else if (str_contains($originalName, (string)$oldVal)) {
                        $variant->update(['name' => str_replace((string)$oldVal, (string)$newVal, $originalName)]);
                    }
                }

                // 3. Update the variant values records
                \App\Models\ProductVariantValue::where(function ($q) use ($oldName, $newName) {
                    $q->where('attribute_name', $oldName)
                      ->orWhere('attribute_name', $newName);
                })
                ->where('attribute_value', (string)$oldVal)
                ->update(['attribute_value' => (string)$newVal]);
            }

            // If variation name itself changed (e.g. blue -> navy_blue, or Size -> Shoe Size)
            if ($oldName !== $newName) {
                \App\Models\ProductVariantValue::where('attribute_name', $oldName)
                    ->update(['attribute_name' => $newName]);

                $variantsWithNamePrefix = \App\Models\ProductVariant::where('name', 'like', "{$oldName} - %")->get();
                foreach ($variantsWithNamePrefix as $variant) {
                    $rest = substr($variant->name, strlen("{$oldName} - "));
                    $variant->update(['name' => "{$newName} - {$rest}"]);
                }
            }
        });

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Variation and connected product variants updated successfully',
            'data' => $variation->fresh(),
        ]);
    }

    /**
     * Toggle the status of the specified variation.
     */
    public function toggleStatus($id)
    {
        $variation = Variation::find($id);
        if (!$variation) {
            return response()->json(['status' => 'error', 'message' => 'Variation not found'], 404);
        }

        $variation->status = ($variation->status === 'Active') ? 'Inactive' : 'Active';
        $variation->save();

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Variation status toggled successfully',
            'data' => $variation,
        ]);
    }

    /**
     * Remove the specified variation.
     */
    public function destroy($id)
    {
        $variation = Variation::find($id);

        if (!$variation) {
            return response()->json(['status' => 'error', 'message' => 'Variation not found'], 404);
        }

        $linkedCount = \App\Models\ProductVariantValue::where('attribute_name', $variation->name)->count();
        if ($linkedCount > 0) {
            return response()->json([
                'status' => 'error',
                'message' => "Cannot delete variation '{$variation->name}' because it is currently assigned to active product variant(s). Please reassign or remove these variants first to preserve accounting and inventory integrity.",
            ], 422);
        }

        $variation->delete();

        $this->clearListCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Variation deleted successfully',
        ]);
    }
}