<?php

/**
 * Throwaway verification for the SPX booking CSV export.
 * Picks the first synced orders (all sharing the dropoff phone so the A/B/C
 * letters get exercised), runs the service, prints the CSV and the persisted
 * meta. Run twice to see reference reuse (idempotence).
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\SpxBookingCsvExport;
use Fleetbase\FleetOps\Models\Order;

session(['company' => 'bfe65457-edfa-4ae2-b3ca-22f95014bfb5']);

$selections = Order::where('company_uuid', session('company'))
    ->orderBy('created_at')
    ->limit(3)
    ->pluck('uuid')
    ->all();

echo 'selections: ' . json_encode($selections) . PHP_EOL . PHP_EOL;

$csv = (new SpxBookingCsvExport())->exportForSelections($selections);

echo $csv . PHP_EOL . PHP_EOL;

echo '--- persisted meta ---' . PHP_EOL;
Order::whereIn('uuid', $selections)
    ->orderBy('created_at')
    ->get(['uuid', 'meta'])
    ->each(function (Order $order) {
        echo $order->uuid . ' => ' . var_export(data_get($order->meta, 'customer_reference'), true) . PHP_EOL;
    });

echo PHP_EOL . '--- controller contract (direct invoke) ---' . PHP_EOL;
$controller = new App\Http\Controllers\SpxBookingExportController();

$empty = $controller(new Illuminate\Http\Request([], [], [], [], [], [], json_encode([])), app(SpxBookingCsvExport::class));
echo 'empty selections: ' . $empty->getStatusCode() . ' ' . $empty->getContent() . PHP_EOL;

$oversized = $controller(
    Illuminate\Http\Request::create('/', 'POST', ['selections' => array_fill(0, 1001, 'x')]),
    app(SpxBookingCsvExport::class)
);
echo 'oversized selections: ' . $oversized->getStatusCode() . ' ' . $oversized->getContent() . PHP_EOL;

$noMatch = $controller(
    Illuminate\Http\Request::create('/', 'POST', ['selections' => ['00000000-0000-0000-0000-000000000000']]),
    app(SpxBookingCsvExport::class)
);
echo 'unknown orders: ' . $noMatch->getStatusCode() . ' ' . $noMatch->getContent() . PHP_EOL;

$ok = $controller(
    Illuminate\Http\Request::create('/', 'POST', ['selections' => [$selections[0]]]),
    app(SpxBookingCsvExport::class)
);
echo 'valid export: ' . $ok->getStatusCode() . PHP_EOL;
echo 'Content-Type: ' . $ok->headers->get('Content-Type') . PHP_EOL;
echo 'Content-Disposition: ' . $ok->headers->get('Content-Disposition') . PHP_EOL;
echo 'bytes: ' . strlen($ok->getContent()) . ', ends with LF: ' . var_export(str_ends_with($ok->getContent(), "\n"), true) . ', BOM: ' . var_export(str_starts_with($ok->getContent(), "\xEF\xBB\xBF"), true) . PHP_EOL;
