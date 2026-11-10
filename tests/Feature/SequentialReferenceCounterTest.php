<?php

namespace Tests\Feature;

use App\Models\ReferenceCounter;
use App\Models\Sale;
use App\Models\Setting;
use App\Traits\GeneratesSequentialCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequentialReferenceCounterTest extends TestCase
{
    use RefreshDatabase;
    use GeneratesSequentialCode;

    public function test_peek_does_not_advance_counter(): void
    {
        $type = 'sale';
        $prefix = $this->resolvePrefix($type);

        $counterBefore = ReferenceCounter::where('type', $type)->where('prefix', $prefix)->first();
        $lastSeqBefore = $counterBefore ? $counterBefore->last_seq : 0;

        $peek1 = $this->peekNextSequentialCode($type);
        $peek2 = $this->peekNextSequentialCode($type);

        $this->assertEquals($peek1['code'], $peek2['code']);

        $counterAfter = ReferenceCounter::where('type', $type)->where('prefix', $prefix)->first();
        $lastSeqAfter = $counterAfter ? $counterAfter->last_seq : 0;

        $this->assertEquals($lastSeqBefore, $lastSeqAfter, 'Peeking should not advance the persistent counter');
    }

    public function test_atomic_generation_increments_sequentially(): void
    {
        $type = 'purchase';
        $prefix = $this->resolvePrefix($type);

        $code1 = $this->generateNextSequentialCode($type);
        $code2 = $this->generateNextSequentialCode($type);
        $code3 = $this->generateNextSequentialCode($type);

        $this->assertNotEquals($code1, $code2);
        $this->assertNotEquals($code2, $code3);

        preg_match('/(\d+)$/', $code1, $m1);
        preg_match('/(\d+)$/', $code2, $m2);
        preg_match('/(\d+)$/', $code3, $m3);

        $num1 = (int)$m1[1];
        $num2 = (int)$m2[1];
        $num3 = (int)$m3[1];

        $this->assertEquals($num1 + 1, $num2, 'Code 2 must be exactly code 1 + 1');
        $this->assertEquals($num2 + 1, $num3, 'Code 3 must be exactly code 2 + 1');
    }

    public function test_acquire_unique_reference_generates_on_empty_or_duplicate(): void
    {
        $type = 'quotation';
        $prefix = $this->resolvePrefix($type);

        // 1. Empty reference -> generates sequential
        $code = $this->acquireUniqueReference($type, '');
        $this->assertStringStartsWith($prefix, $code);

        // Create an existing record with this code so it is taken
        \App\Models\Quotation::create([
            'reference_no' => $code,
            'customer_name' => 'Test Customer',
            'warehouse_name' => 'Main Warehouse',
            'date' => now()->toDateString(),
            'grand_total' => 100,
        ]);

        // 2. Duplicate reference -> reallocates next sequential
        $dupCode = $this->acquireUniqueReference($type, $code);
        $this->assertNotEquals($code, $dupCode, 'Duplicate requested code must be reallocated to a new sequential code');
    }

    public function test_all_eight_types_generate_valid_codes(): void
    {
        $types = ['sale', 'sale_return', 'purchase', 'purchase_return', 'quotation', 'adjustment', 'transfer', 'expense'];

        foreach ($types as $type) {
            $prefix = $this->resolvePrefix($type);
            $peek = $this->peekNextSequentialCode($type);

            $this->assertEquals('success', $peek['status']);
            $this->assertStringStartsWith($prefix, $peek['code']);
            $this->assertNotEmpty($peek['numeric_code']);
        }
    }
}
