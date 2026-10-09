<?php

namespace Database\Seeders;

use App\Models\DeliveryRegion;
use App\Models\DeliveryTownship;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeliveryRegionAndTownshipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::beginTransaction();
        try {
            // 1. Yangon Region
            $yangon = DeliveryRegion::updateOrCreate(
                ['name' => 'Yangon'],
                [
                    'name_mm' => 'ရန်ကုန်တိုင်းဒေသကြီး',
                    'code' => 'YGN',
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );

            $yangonTownships = [
                ['name' => 'Kamayut', 'name_mm' => 'ကမာရွတ်', 'code' => 'KMY', 'delivery_fee' => 2000, 'free_delivery_threshold' => 50000, 'estimated_days' => 'Same Day / 1 Day', 'sort_order' => 1],
                ['name' => 'Sanchaung', 'name_mm' => 'စမ်းချောင်း', 'code' => 'SCH', 'delivery_fee' => 2000, 'free_delivery_threshold' => 50000, 'estimated_days' => 'Same Day / 1 Day', 'sort_order' => 2],
                ['name' => 'Bahan', 'name_mm' => 'ဗဟန်း', 'code' => 'BHN', 'delivery_fee' => 2000, 'free_delivery_threshold' => 50000, 'estimated_days' => 'Same Day / 1 Day', 'sort_order' => 3],
                ['name' => 'Kyauktada', 'name_mm' => 'ကျောက်တံတား', 'code' => 'KTD', 'delivery_fee' => 2000, 'free_delivery_threshold' => 50000, 'estimated_days' => 'Same Day / 1 Day', 'sort_order' => 4],
                ['name' => 'Pabedan', 'name_mm' => 'ပန်းဘဲတန်း', 'code' => 'PBD', 'delivery_fee' => 2000, 'free_delivery_threshold' => 50000, 'estimated_days' => 'Same Day / 1 Day', 'sort_order' => 5],
                ['name' => 'Latha', 'name_mm' => 'လသာ', 'code' => 'LTH', 'delivery_fee' => 2000, 'free_delivery_threshold' => 50000, 'estimated_days' => 'Same Day / 1 Day', 'sort_order' => 6],
                ['name' => 'Lanmadaw', 'name_mm' => 'လမ်းမတော်', 'code' => 'LMD', 'delivery_fee' => 2000, 'free_delivery_threshold' => 50000, 'estimated_days' => 'Same Day / 1 Day', 'sort_order' => 7],
                ['name' => 'Dagon', 'name_mm' => 'ဒဂုံ', 'code' => 'DGN', 'delivery_fee' => 2000, 'free_delivery_threshold' => 50000, 'estimated_days' => 'Same Day / 1 Day', 'sort_order' => 8],
                ['name' => 'Yankin', 'name_mm' => 'ရန်ကင်း', 'code' => 'YKN', 'delivery_fee' => 2500, 'free_delivery_threshold' => 50000, 'estimated_days' => '1 Day', 'sort_order' => 9],
                ['name' => 'Mayangone', 'name_mm' => 'မရမ်းကုန်း', 'code' => 'MYG', 'delivery_fee' => 2500, 'free_delivery_threshold' => 50000, 'estimated_days' => '1 Day', 'sort_order' => 10],
                ['name' => 'Hlaing', 'name_mm' => 'လှိုင်', 'code' => 'HLG', 'delivery_fee' => 2500, 'free_delivery_threshold' => 50000, 'estimated_days' => '1 Day', 'sort_order' => 11],
                ['name' => 'Tamwe', 'name_mm' => 'တာမွေ', 'code' => 'TMW', 'delivery_fee' => 2500, 'free_delivery_threshold' => 50000, 'estimated_days' => '1 Day', 'sort_order' => 12],
                ['name' => 'South Okkalapa', 'name_mm' => 'တောင်ဥက္ကလာပ', 'code' => 'SOK', 'delivery_fee' => 2500, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 13],
                ['name' => 'North Okkalapa', 'name_mm' => 'မြောက်ဥက္ကလာပ', 'code' => 'NOK', 'delivery_fee' => 3000, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 14],
                ['name' => 'Thingangyun', 'name_mm' => 'သင်္ဃန်းကျွန်း', 'code' => 'TGK', 'delivery_fee' => 2500, 'free_delivery_threshold' => 50000, 'estimated_days' => '1-2 Days', 'sort_order' => 15],
                ['name' => 'Thaketa', 'name_mm' => 'သာကေတ', 'code' => 'TKT', 'delivery_fee' => 3000, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 16],
                ['name' => 'Dawbon', 'name_mm' => 'ဒေါပုံ', 'code' => 'DWB', 'delivery_fee' => 3000, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 17],
                ['name' => 'Insein', 'name_mm' => 'အင်းစိန်', 'code' => 'ISN', 'delivery_fee' => 3000, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 18],
                ['name' => 'North Dagon', 'name_mm' => 'မြောက်ဒဂုံ', 'code' => 'NDG', 'delivery_fee' => 3500, 'free_delivery_threshold' => 70000, 'estimated_days' => '1-2 Days', 'sort_order' => 19],
                ['name' => 'South Dagon', 'name_mm' => 'တောင်ဒဂုံ', 'code' => 'SDG', 'delivery_fee' => 4000, 'free_delivery_threshold' => 70000, 'estimated_days' => '1-2 Days', 'sort_order' => 20],
                ['name' => 'Hlaingtharyar', 'name_mm' => 'လှိုင်သာယာ', 'code' => 'HTY', 'delivery_fee' => 4500, 'free_delivery_threshold' => 80000, 'estimated_days' => '1-2 Days', 'sort_order' => 21],
                ['name' => 'Shwepyitha', 'name_mm' => 'ရွှေပြည်သာ', 'code' => 'SPT', 'delivery_fee' => 4500, 'free_delivery_threshold' => 80000, 'estimated_days' => '1-2 Days', 'sort_order' => 22],
            ];

            foreach ($yangonTownships as $tsp) {
                DeliveryTownship::updateOrCreate(
                    [
                        'delivery_region_id' => $yangon->id,
                        'name' => $tsp['name'],
                    ],
                    array_merge($tsp, [
                        'is_cod_available' => true,
                        'is_active' => true,
                    ])
                );
            }

            // 2. Mandalay Region
            $mandalay = DeliveryRegion::updateOrCreate(
                ['name' => 'Mandalay'],
                [
                    'name_mm' => 'မန္တလေးတိုင်းဒေသကြီး',
                    'code' => 'MDY',
                    'is_active' => true,
                    'sort_order' => 2,
                ]
            );

            $mandalayTownships = [
                ['name' => 'Chanayethazan', 'name_mm' => 'ချမ်းအေးသာစံ', 'code' => 'CAT', 'delivery_fee' => 2500, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 1],
                ['name' => 'Mahaaungmye', 'name_mm' => 'မဟာအောင်မြေ', 'code' => 'MAM', 'delivery_fee' => 2500, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 2],
                ['name' => 'Chanmyathazi', 'name_mm' => 'ချမ်းမြသာစည်', 'code' => 'CMT', 'delivery_fee' => 2500, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 3],
                ['name' => 'Aungmyethazan', 'name_mm' => 'အောင်မြေသာစံ', 'code' => 'AMT', 'delivery_fee' => 3000, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 4],
                ['name' => 'Pyigyidagun', 'name_mm' => 'ပြည်ကြီးတံခွန်', 'code' => 'PGD', 'delivery_fee' => 3000, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 5],
                ['name' => 'Amarapura', 'name_mm' => 'အမရပူရ', 'code' => 'AMP', 'delivery_fee' => 4000, 'free_delivery_threshold' => 80000, 'estimated_days' => '2-3 Days', 'sort_order' => 6],
            ];

            foreach ($mandalayTownships as $tsp) {
                DeliveryTownship::updateOrCreate(
                    [
                        'delivery_region_id' => $mandalay->id,
                        'name' => $tsp['name'],
                    ],
                    array_merge($tsp, [
                        'is_cod_available' => true,
                        'is_active' => true,
                    ])
                );
            }

            // 3. Naypyidaw Union Territory
            $naypyidaw = DeliveryRegion::updateOrCreate(
                ['name' => 'Naypyidaw'],
                [
                    'name_mm' => 'နေပြည်တော်',
                    'code' => 'NPT',
                    'is_active' => true,
                    'sort_order' => 3,
                ]
            );

            $nptTownships = [
                ['name' => 'Zabuthiri', 'name_mm' => 'ဇမ္ဗူသီရိ', 'code' => 'ZBT', 'delivery_fee' => 3000, 'free_delivery_threshold' => 60000, 'estimated_days' => '1-2 Days', 'sort_order' => 1],
                ['name' => 'Ottarathiri', 'name_mm' => 'ဥတ္တရသီရိ', 'code' => 'OTT', 'delivery_fee' => 3500, 'free_delivery_threshold' => 70000, 'estimated_days' => '1-2 Days', 'sort_order' => 2],
                ['name' => 'Dekkhinathiri', 'name_mm' => 'ဒက္ခိဏသီရိ', 'code' => 'DKT', 'delivery_fee' => 3500, 'free_delivery_threshold' => 70000, 'estimated_days' => '1-2 Days', 'sort_order' => 3],
                ['name' => 'Pyinmana', 'name_mm' => 'ပျဉ်းမနား', 'code' => 'PMN', 'delivery_fee' => 3500, 'free_delivery_threshold' => 70000, 'estimated_days' => '1-2 Days', 'sort_order' => 4],
            ];

            foreach ($nptTownships as $tsp) {
                DeliveryTownship::updateOrCreate(
                    [
                        'delivery_region_id' => $naypyidaw->id,
                        'name' => $tsp['name'],
                    ],
                    array_merge($tsp, [
                        'is_cod_available' => true,
                        'is_active' => true,
                    ])
                );
            }

            // 4. Bago Region
            $bago = DeliveryRegion::updateOrCreate(
                ['name' => 'Bago'],
                [
                    'name_mm' => 'ပဲခူးတိုင်းဒေသကြီး',
                    'code' => 'BGO',
                    'is_active' => true,
                    'sort_order' => 4,
                ]
            );

            DeliveryTownship::updateOrCreate(
                [
                    'delivery_region_id' => $bago->id,
                    'name' => 'Bago Town',
                ],
                [
                    'name_mm' => 'ပဲခူးမြို့',
                    'code' => 'BGO-T',
                    'delivery_fee' => 4000,
                    'free_delivery_threshold' => 80000,
                    'estimated_days' => '2-3 Days',
                    'is_cod_available' => true,
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
