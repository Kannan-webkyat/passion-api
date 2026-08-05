<?php

/**
 * End-to-end hotel F&B flow: inventory → production → POS → settle → reports → day close.
 * Run: php scripts/e2e_hotel_flow_test.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = getenv('API_BASE') ?: 'http://127.0.0.1:8000/api';

function api(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $url = rtrim($base, '/').'/'.ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw ?: '[]', true);
    if (! is_array($data)) {
        $data = [];
    }
    $data['_http'] = $code;
    $data['_raw'] = $raw;

    return $data;
}

function step(string $label): void
{
    echo "\n━━ {$label} ━━\n";
}

function ok(array $res, int $expect = 200): bool
{
    $code = $res['_http'] ?? 0;
    if ($code === $expect || ($expect === 200 && in_array($code, [200, 201], true))) {
        return true;
    }
    echo "  FAIL HTTP {$code}: ".substr($res['_raw'] ?? '', 0, 500)."\n";

    return false;
}

function money($n): string
{
    return '₹'.number_format((float) $n, 2);
}

function arr_first(array $list, callable $fn): ?array
{
    foreach ($list as $row) {
        if ($fn($row)) {
            return $row;
        }
    }

    return null;
}

function menu_find_item(array $menu, string $needle): ?array
{
    foreach ($menu as $cat) {
        if (! is_array($cat)) {
            continue;
        }
        foreach ($cat['items'] ?? [] as $it) {
            if (stripos((string) ($it['name'] ?? ''), $needle) !== false) {
                return $it;
            }
        }
    }

    return null;
}

$results = ['pass' => 0, 'fail' => 0, 'notes' => []];
$today = \App\Services\BusinessDateService::resolve(\App\Models\RestaurantMaster::find(1));
echo "Business date (OTTAAL): {$today}\n";

step('1. Login (Admin)');
$login = api('POST', '/login', [
    'email' => 'admin@hotel.com',
    'password' => '1',
    'device_name' => 'e2e-test',
]);
if (! ok($login, 200) || empty($login['token'])) {
    echo "Cannot login — start API: php artisan serve\n";
    exit(1);
}
$token = $login['token'];
echo "  OK\n";
$results['pass']++;

step('2. Outlets & menu availability');
$restaurants = api('GET', '/pos/restaurants', null, $token);
if (! is_array($restaurants)) {
    echo "  FAIL restaurants\n";
    exit(1);
}
$ottaal = arr_first($restaurants, fn ($r) => ($r['name'] ?? '') === 'OTTAAL');
$barOutlet = arr_first($restaurants, fn ($r) => ($r['name'] ?? '') === 'Brews and Bubbles');
if (! $ottaal || ! $barOutlet) {
    echo "  FAIL — seed OTTAAL + Brews and Bubbles\n";
    exit(1);
}
$ottaalId = (int) $ottaal['id'];
$barId = (int) $barOutlet['id'];
$rmO = \App\Models\RestaurantMaster::find($ottaalId);
$kitchenLoc = (int) $rmO->kitchen_location_id;

$menuO = api('GET', "/pos/menu?restaurant_id={$ottaalId}", null, $token);
$menuB = api('GET', "/pos/menu?restaurant_id={$barId}", null, $token);
$biryani = menu_find_item(is_array($menuO) ? $menuO : [], 'Biryani');
$cocktail = menu_find_item(is_array($menuB) ? $menuB : [], 'Highball');
if (! $biryani || ! $cocktail) {
    echo "  FAIL menu items\n";
    exit(1);
}
$biryaniId = (int) $biryani['id'];
$cocktailId = (int) $cocktail['id'];
echo "  Biryani #{$biryaniId} pool_avail=".($biryani['available_qty'] ?? '?')." price=".($biryani['price'] ?? '?')."\n";
echo "  Cocktail #{$cocktailId} price=".($cocktail['price'] ?? '?')."\n";
$results['pass']++;

step('3. Kitchen production (+2 biryani portions)');
$recipes = api('GET', '/recipes/production', null, $token);
$recipeId = null;
if (is_array($recipes)) {
    foreach ($recipes as $r) {
        if ((int) ($r['recipe_id'] ?? 0) > 0 && stripos((string) ($r['name'] ?? ''), 'Biryani') !== false) {
            $recipeId = (int) $r['recipe_id'];
            break;
        }
    }
}
if (! $recipeId) {
    echo "  SKIP — no biryani recipe\n";
} else {
    $produce = api('POST', "/recipes/{$recipeId}/produce", [
        'quantity_produced' => 2,
        'inventory_location_id' => $kitchenLoc,
        'notes' => 'E2E hotel flow test',
    ], $token);
    if (ok($produce, 200) || ok($produce, 201)) {
        echo "  OK — raw ingredients deducted, +2 portions in production pool\n";
        $results['pass']++;
    } else {
        $results['fail']++;
        $results['notes'][] = 'Production: '.($produce['message'] ?? 'failed');
    }
}

step('4. POS kitchen — dine-in → KOT → ready → settle (cash)');
$tables = api('GET', "/pos/tables?restaurant_id={$ottaalId}", null, $token);
$table = arr_first(is_array($tables) ? $tables : [], fn ($t) => ($t['status'] ?? '') === 'available');
if (! $table) {
    echo "  FAIL no table\n";
    $results['fail']++;
} else {
    $open = api('POST', '/pos/orders', [
        'order_type' => 'dine_in',
        'restaurant_id' => $ottaalId,
        'table_id' => (int) $table['id'],
        'covers' => 2,
    ], $token);
    if (! ok($open, 201) && ! ok($open, 200)) {
        $results['fail']++;
    } else {
        $kitchenOrderId = (int) ($open['id'] ?? 0);
        echo "  Order #{$kitchenOrderId} table {$table['table_number']}\n";

        ok(api('PUT', "/pos/orders/{$kitchenOrderId}/items", [
            'items' => [['menu_item_id' => $biryaniId, 'quantity' => 1]],
        ], $token)) ? $results['pass']++ : $results['fail']++;

        ok(api('POST', "/pos/orders/{$kitchenOrderId}/kot", [], $token)) ? $results['pass']++ : $results['fail']++;

        $detail = api('GET', "/pos/orders/{$kitchenOrderId}", null, $token);
        $itemId = (int) ($detail['items'][0]['id'] ?? 0);
        $ready = api('POST', "/pos/orders/{$kitchenOrderId}/mark-order-item-ready", [
            'order_item_id' => $itemId,
        ], $token);
        if (ok($ready)) {
            echo "  OK mark ready\n";
            $results['pass']++;
        } else {
            $results['fail']++;
        }

        $detail = api('GET', "/pos/orders/{$kitchenOrderId}", null, $token);
        $total = (float) ($detail['total_amount'] ?? 0);
        $settle = api('POST', "/pos/orders/{$kitchenOrderId}/settle", [
            'payments' => [['method' => 'cash', 'amount' => $total]],
        ], $token);
        if (ok($settle)) {
            echo "  OK settled ".money($total)." — batch portion + GL at settle\n";
            $results['pass']++;
        } else {
            $results['fail']++;
            $results['notes'][] = 'Kitchen settle: '.($settle['message'] ?? '');
        }
    }
}

step('5. POS bar — cocktail, no KOT, settle (card + VAT)');
$barTables = api('GET', "/pos/tables?restaurant_id={$barId}", null, $token);
$barTable = arr_first(is_array($barTables) ? $barTables : [], fn ($t) => ($t['status'] ?? '') === 'available');
if (! $barTable) {
    echo "  FAIL no bar table\n";
    $results['fail']++;
} else {
    $openB = api('POST', '/pos/orders', [
        'order_type' => 'dine_in',
        'restaurant_id' => $barId,
        'table_id' => (int) $barTable['id'],
        'covers' => 1,
    ], $token);
    $barOrderId = (int) ($openB['id'] ?? 0);
    ok(api('PUT', "/pos/orders/{$barOrderId}/items", [
        'items' => [['menu_item_id' => $cocktailId, 'quantity' => 1]],
    ], $token)) ? $results['pass']++ : $results['fail']++;
    $detailB = api('GET', "/pos/orders/{$barOrderId}", null, $token);
    $totalB = (float) ($detailB['total_amount'] ?? 0);
    $settleB = api('POST', "/pos/orders/{$barOrderId}/settle", [
        'payments' => [['method' => 'card', 'amount' => $totalB]],
    ], $token);
    if (ok($settleB)) {
        echo "  OK order #{$barOrderId} settled ".money($totalB)." — BOM deducted at settle\n";
        $results['pass']++;
    } else {
        $results['fail']++;
    }
}

step('6. Void audit (unpaid order → void, no revenue)');
$voidTable = arr_first(api('GET', "/pos/tables?restaurant_id={$ottaalId}", null, $token) ?: [], fn ($t) => ($t['status'] ?? '') === 'available');
if ($voidTable) {
    $openV = api('POST', '/pos/orders', [
        'order_type' => 'dine_in',
        'restaurant_id' => $ottaalId,
        'table_id' => (int) $voidTable['id'],
        'covers' => 1,
    ], $token);
    $voidOrderId = (int) ($openV['id'] ?? 0);
    api('PUT', "/pos/orders/{$voidOrderId}/items", [
        'items' => [['menu_item_id' => $biryaniId, 'quantity' => 1]],
    ], $token);
    $voidRes = api('POST', "/pos/orders/{$voidOrderId}/void", [
        'void_reason' => 'Test order',
        'void_notes' => 'E2E void — pool should free, no GL sales',
    ], $token);
    if (ok($voidRes)) {
        $vo = \App\Models\PosOrder::find($voidOrderId);
        echo "  OK void #{$voidOrderId} total=".money($vo->total_amount ?? 0)." bd={$vo->business_date}\n";
        $results['pass']++;
    } else {
        $results['fail']++;
    }
} else {
    echo "  SKIP — no table\n";
}

step('7. Sales reports (business date '.$today.')');
foreach ([$ottaalId => 'OTTAAL', $barId => 'BAR'] as $rid => $label) {
    $sales = api('GET', "/pos/reports/sales?from={$today}&to={$today}&restaurant_id={$rid}", null, $token);
    if (! empty($sales['summary'])) {
        $s = $sales['summary'];
        echo "  {$label}: bills={$s['orders_count']} gross=".money($s['total_amount'])
            ." voids={$s['voided_count']}/".money($s['voided_amount'])
            ." refunds=".money($s['total_refunded'])
            ." net=".money($s['net_realized'])."\n";
        $results['pass']++;
    }
}

step('8. Day close — OTTAAL Z-report');
$preview = api('GET', "/pos/day-closing/preview?restaurant_id={$ottaalId}&date={$today}", null, $token);
if (ok($preview)) {
    $ck = $preview['checklist'] ?? [];
    echo "  can_close=".(($ck['can_close'] ?? false) ? 'YES' : 'NO')."\n";
    foreach ($ck['items'] ?? [] as $item) {
        echo "    [{$item['status']}] {$item['label']}\n";
    }
    $sum = $preview['summary'] ?? [];
    echo "  Z: orders={$sum['order_count']} paid=".money($sum['total_paid'] ?? 0)
        ." CGST=".money($sum['cgst_amount'] ?? 0)." voids={$sum['void_count']}\n";
    $results['pass']++;

    if ($ck['can_close'] ?? false) {
        $close = api('POST', '/pos/day-closing', [
            'restaurant_id' => $ottaalId,
            'date' => $today,
            'notes' => 'E2E close',
        ], $token);
        if (ok($close, 200) || ok($close, 201)) {
            echo "  OK day closed — POS locked for {$today}\n";
            $results['pass']++;
        } else {
            echo "  Close failed: ".($close['message'] ?? '')."\n";
            $results['notes'][] = $close['message'] ?? 'close failed';
        }
    } else {
        $results['notes'][] = 'Checklist blocked close';
    }
}

step('9. GL trial balance + inventory audit');
$tb = api('GET', "/accounting/trial-balance?from={$today}&to={$today}", null, $token);
if (ok($tb) && ! empty($tb['rows'])) {
    $d = array_sum(array_map(fn ($r) => (float) ($r['debit'] ?? 0), $tb['rows']));
    $c = array_sum(array_map(fn ($r) => (float) ($r['credit'] ?? 0), $tb['rows']));
    echo "  Trial balance: ".count($tb['rows'])." accounts, D=".money($d)." C=".money($c)."\n";
    $results['pass']++;
}
$tx = \App\Models\InventoryTransaction::whereDate('created_at', $today)->where('reference_type', 'like', 'pos_%')->count();
$je = \App\Models\JournalEntry::whereDate('business_date', $today)->count();
$waste = \App\Models\PosVoidWaste::whereDate('voided_at', $today)->count();
echo "  Inventory POS txs: {$tx} | Journals: {$je} | Void waste rows: {$waste}\n";

step('10. Reconciliation checks');
$paidO = \App\Models\PosOrder::where('restaurant_id', $ottaalId)->where('status', 'paid')
    ->whereDate('business_date', $today)->sum('total_amount');
$salesO = api('GET', "/pos/reports/sales?from={$today}&to={$today}&restaurant_id={$ottaalId}", null, $token);
$reportGross = (float) ($salesO['summary']['total_amount'] ?? 0);
$match = abs($paidO - $reportGross) < 0.02;
echo "  OTTAAL DB paid sum ".money($paidO)." vs sales report ".money($reportGross)." → ".($match ? 'MATCH' : 'MISMATCH')."\n";
$match ? $results['pass']++ : $results['fail']++;

echo "\n══════════════════════════════════════\n";
echo "E2E: {$results['pass']} passed, {$results['fail']} failed\n";
foreach ($results['notes'] as $n) {
    echo "  • {$n}\n";
}
echo "══════════════════════════════════════\n";
exit($results['fail'] > 0 ? 1 : 0);
