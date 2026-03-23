<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\InventoryReportController;
use Illuminate\Http\Request;

$ctrl = new InventoryReportController();
$req = Request::create('/api/inventory/reports/consumption', 'GET', [
    'start_date' => '2026-03-14',
    'end_date' => '2026-03-21', // Matches the screenshot audit period
]);

$response = $ctrl->consumption($req);
$content = json_decode($response->getContent(), true);

foreach($content['data'] as $row) {
    if ($row['variance'] != 0) {
        echo "Item: {$row['item_name']} | Theo: {$row['theoretical_usage']} | Act: {$row['actual_usage']} | Var: {$row['variance']} | VarVal: {$row['variance_value']}\n";
    }
}
echo "\nJSON Output of first item with variance:\n";
foreach($content['data'] as $row) {
    if ($row['variance'] != 0) {
        echo json_encode($row) . "\n";
        break;
    }
}
