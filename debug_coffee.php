<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PosOrderItem;
use App\Models\MenuItem;

$item = MenuItem::with('recipe.ingredients')->where('name', 'like', '%Coffee%')->first();
if (!$item) {
    echo "Coffee item not found\n";
    exit;
}

echo "Item: " . $item->name . " (ID: " . $item->id . ")\n";
if (!$item->recipe) {
    echo "No recipe found\n";
} else {
    echo "Recipe ID: " . $item->recipe->id . "\n";
    echo "Requires Production: " . ($item->recipe->requires_production ? 'Yes' : 'No') . "\n";
    echo "Yield: " . $item->recipe->yield_quantity . "\n";
    foreach ($item->recipe->ingredients as $ing) {
        echo "- Ingredient: " . $ing->inventory_item_id . " Name: " . ($ing->inventoryItem?->name ?? 'N/A') . " Qty: " . $ing->quantity . " Yield%: " . $ing->yield_percentage . "\n";
    }
}

$sales = PosOrderItem::where('menu_item_id', $item->id)->get();
echo "Sales Count: " . $sales->count() . "\n";
foreach ($sales as $s) {
    echo "- Sale Qty: " . $s->quantity . " Order Status: " . ($s->order?->status ?? 'N/A') . " Closed At: " . ($s->order?->closed_at ?? 'N/A') . "\n";
}
