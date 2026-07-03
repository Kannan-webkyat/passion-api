<?php

use App\Models\ChartOfAccount;
use App\Models\GRN;
use App\Models\GrnItem;
use App\Models\InventoryLocation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseOrderItem;
use App\Models\RestaurantMaster;
use App\Services\Accounting\AccountCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        InventoryLocation::query()
            ->where('name', 'Bar Store')
            ->update(['type' => 'bar_store']);

        $barStoreId = InventoryLocation::query()
            ->where('name', 'Bar Store')
            ->value('id');

        if ($barStoreId) {
            RestaurantMaster::query()
                ->where('name', 'OTTAAL')
                ->whereNull('bar_location_id')
                ->update(['bar_location_id' => $barStoreId]);
        }

        $this->fixGrnOneInputVatJournal();
    }

    public function down(): void
    {
        InventoryLocation::query()
            ->where('name', 'Bar Store')
            ->update(['type' => 'sub_store']);
    }

    private function fixGrnOneInputVatJournal(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('grn_items')) {
            return;
        }

        $grn = GRN::query()->find(1);
        if (! $grn || $grn->status !== GRN::STATUS_APPROVED) {
            return;
        }

        $grnItem = GrnItem::query()->where('grn_id', $grn->id)->first();
        if (! $grnItem) {
            return;
        }

        $poItem = PurchaseOrderItem::query()->find($grnItem->purchase_order_item_id);
        if (! $poItem) {
            return;
        }

        $accepted = (float) $grnItem->quantity_accepted;
        $ordered = max(0.000001, (float) $poItem->quantity_ordered);
        $expectedTax = round((float) ($poItem->tax_amount ?? 0) * ($accepted / $ordered), 2);
        $storedTax = round((float) $grnItem->line_tax_accepted, 2);

        if (abs($expectedTax - $storedTax) < 0.01) {
            return;
        }

        DB::transaction(function () use ($grn, $grnItem, $expectedTax, $storedTax) {
            $grnItem->update(['line_tax_accepted' => $expectedTax]);

            $entry = JournalEntry::query()
                ->where('source_type', 'grn_approve')
                ->where('source_id', $grn->id)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->first();

            if (! $entry) {
                return;
            }

            $inputVatAccountId = ChartOfAccount::query()
                ->where('code', AccountCodes::INPUT_VAT)
                ->value('id');
            $grniAccountId = ChartOfAccount::query()
                ->where('code', AccountCodes::GRNI)
                ->value('id');

            if (! $inputVatAccountId || ! $grniAccountId) {
                return;
            }

            $taxDelta = round($expectedTax - $storedTax, 2);
            if (abs($taxDelta) < 0.01) {
                return;
            }

            $vatLine = JournalLine::query()
                ->where('journal_entry_id', $entry->id)
                ->where('account_id', $inputVatAccountId)
                ->first();

            if ($vatLine) {
                $vatLine->update([
                    'debit' => round((float) $vatLine->debit + $taxDelta, 2),
                ]);
            }

            $grniLine = JournalLine::query()
                ->where('journal_entry_id', $entry->id)
                ->where('account_id', $grniAccountId)
                ->first();

            if ($grniLine) {
                $grniLine->update([
                    'credit' => round((float) $grniLine->credit + $taxDelta, 2),
                ]);
            }
        });
    }
};
