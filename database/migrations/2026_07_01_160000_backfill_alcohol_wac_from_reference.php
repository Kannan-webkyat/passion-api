<?php

use App\Models\InventoryItem;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $reference = InventoryItem::query()
            ->where('is_alcohol', true)
            ->where('cost_price', '>', 0)
            ->orderBy('id')
            ->first();

        if (! $reference) {
            return;
        }

        $refBottleMl = max(1.0, (float) $reference->conversion_factor);

        InventoryItem::query()
            ->where('is_alcohol', true)
            ->where('cost_price', '<=', 0)
            ->each(function (InventoryItem $item) use ($reference, $refBottleMl) {
                $bottleMl = max(1.0, (float) $item->conversion_factor);
                $scaledCost = round((float) $reference->cost_price * ($bottleMl / $refBottleMl), 4);
                $item->update(['cost_price' => $scaledCost]);
            });
    }

    public function down(): void
    {
        // Non-reversible — costs may have been updated by GRNs after migration.
    }
};
