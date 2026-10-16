<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$endpoints = [
    'SaleController@index' => '/api/sales?all=true',
    'PurchaseController@index' => '/api/purchases?all=true',
    'AdjustmentController@index' => '/api/adjustments?all=true',
    'TransferController@index' => '/api/transfers?all=true',
    'PingEndpoint' => '/api/ping',
];

echo "================================================================" . PHP_EOL;
echo "ENTERPRISE PERFORMANCE VERIFICATION BENCHMARK" . PHP_EOL;
echo "================================================================" . PHP_EOL;

$results = [];

foreach ($endpoints as $label => $uri) {
    // Warm up once
    $warmReq = Request::create($uri, 'GET');
    $kernel->handle($warmReq);

    // Measure benchmark
    DB::flushQueryLog();
    DB::enableQueryLog();

    $start = microtime(true);
    $req = Request::create($uri, 'GET');
    $resp = $kernel->handle($req);
    $durationMs = (microtime(true) - $start) * 1000;

    $queries = DB::getQueryLog();
    $status = $resp->getStatusCode();
    $dataCount = 0;
    try {
        $json = json_decode($resp->getContent(), true);
        if (isset($json['data']) && is_array($json['data'])) {
            $dataCount = count($json['data']);
        }
    } catch (\Throwable $e) {}

    $results[$label] = [
        'uri' => $uri,
        'status' => $status,
        'query_count' => count($queries),
        'duration_ms' => round($durationMs, 2),
        'records_returned' => $dataCount,
    ];

    printf("%-30s | Status: %d | Queries: %3d | Time: %7.2f ms | Records: %4d\n",
        $label, $status, count($queries), $durationMs, $dataCount);
    if ($label === 'SaleController@index' || $label === 'TransferController@index') {
        foreach ($queries as $i => $q) {
            echo "   [$label] Query " . ($i + 1) . " (" . round($q['time'] ?? 0, 2) . "ms): " . substr($q['query'], 0, 120) . "..." . PHP_EOL;
        }
    }
}

echo "================================================================" . PHP_EOL;

$failures = [];
if ($results['SaleController@index']['query_count'] > 5) {
    $failures[] = "SaleController@index queries ({$results['SaleController@index']['query_count']}) > 5";
}
if ($results['PurchaseController@index']['query_count'] > 5) {
    $failures[] = "PurchaseController@index queries ({$results['PurchaseController@index']['query_count']}) > 5";
}
if ($results['AdjustmentController@index']['query_count'] > 5) {
    $failures[] = "AdjustmentController@index queries ({$results['AdjustmentController@index']['query_count']}) > 5";
}
if ($results['TransferController@index']['query_count'] > 5) {
    $failures[] = "TransferController@index queries ({$results['TransferController@index']['query_count']}) > 5";
}

if (empty($failures)) {
    echo "VERIFICATION PASSED: All controller query counts <= 5!\n";
} else {
    echo "VERIFICATION NOTES:\n - " . implode("\n - ", $failures) . "\n";
}
