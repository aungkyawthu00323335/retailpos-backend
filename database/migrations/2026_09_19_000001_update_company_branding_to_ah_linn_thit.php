<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $branding = [
            'company_name' => 'အလင်းသစ် (Ah Linn Thit)',
            'company_email' => 'support@ahlinnthit.com',
            'default_email' => 'support@ahlinnthit.com',
            'receipt_header' => 'Welcome to အလင်းသစ် (Ah Linn Thit)!',
            'receipt_footer' => 'All rights reserved by အလင်းသစ် (Ah Linn Thit)',
            'footer' => 'All rights reserved by အလင်းသစ် (Ah Linn Thit)',
            'developed_by' => 'Ah Linn Thit',
        ];

        foreach ($branding as $key => $val) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $val]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $legacy = [
            'company_name' => 'Infy Retail POS',
            'company_email' => 'support@infypos.com',
            'default_email' => 'support@infypos.com',
            'receipt_header' => 'Welcome to Infy Retail POS!',
            'receipt_footer' => 'All rights reserved by InfyOm Technologies',
            'footer' => 'All rights reserved by InfyOm Technologies',
            'developed_by' => 'infyom',
        ];

        foreach ($legacy as $key => $val) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $val]
            );
        }
    }
};
