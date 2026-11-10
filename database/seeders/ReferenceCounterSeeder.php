<?php

namespace Database\Seeders;

use App\Models\ReferenceCounter;
use App\Traits\GeneratesSequentialCode;
use Illuminate\Database\Seeder;

class ReferenceCounterSeeder extends Seeder
{
    use GeneratesSequentialCode;

    /**
     * Seed initial reference counters from current maximum sequences in database.
     */
    public function run(): void
    {
        $types = [
            'sale',
            'sale_return',
            'purchase',
            'purchase_return',
            'quotation',
            'adjustment',
            'transfer',
            'expense',
        ];

        foreach ($types as $type) {
            $prefix = $this->resolvePrefix($type);
            $maxSeq = $this->findMaxExistingSeq($type, $prefix);

            ReferenceCounter::updateOrCreate(
                [
                    'type'      => $type,
                    'prefix'    => $prefix,
                    'outlet_id' => 0,
                ],
                [
                    'last_seq'  => $maxSeq,
                ]
            );
        }
    }
}
