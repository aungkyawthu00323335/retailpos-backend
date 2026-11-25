<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop dependent tables first in foreign-key safe order
        Schema::dropIfExists('processing_item_cost_layers');
        Schema::dropIfExists('processing_items');
        Schema::dropIfExists('processings');

        // Clean legacy processings.* / processing.* permission strings from stored roles
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'permissions')) {
            $roles = DB::table('roles')->select('id', 'permissions')->get();
            foreach ($roles as $role) {
                $decoded = is_string($role->permissions)
                    ? json_decode($role->permissions, true)
                    : $role->permissions;
                if (!is_array($decoded)) {
                    continue;
                }
                $filtered = array_values(array_filter(
                    $decoded,
                    static fn ($p) => !is_string($p) || (!str_starts_with($p, 'processings.') && !str_starts_with($p, 'processing.'))
                ));
                if (count($filtered) !== count($decoded)) {
                    DB::table('roles')
                        ->where('id', $role->id)
                        ->update(['permissions' => json_encode($filtered)]);
                }
            }
        }
    }

    public function down(): void
    {
        // Module intentionally removed.
    }
};
