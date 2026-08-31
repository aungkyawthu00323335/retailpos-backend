<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_category_id')->nullable();
            $table->string('parent_category_name')->default('General');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'sub_category_name')) {
                    $table->string('sub_category_name')->nullable()->after('category_name');
                }
                if (!Schema::hasColumn('products', 'sub_category_id')) {
                    $table->unsignedBigInteger('sub_category_id')->nullable()->after('sub_category_name');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_categories');

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'sub_category_name')) {
                    $table->dropColumn('sub_category_name');
                }
                if (Schema::hasColumn('products', 'sub_category_id')) {
                    $table->dropColumn('sub_category_id');
                }
            });
        }
    }
};
