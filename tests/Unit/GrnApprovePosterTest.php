<?php

namespace Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\GrnItem;
use App\Services\Accounting\AccountCodes;
use App\Services\Accounting\GrnApprovePoster;
use App\Services\Accounting\JournalPostingService;
use App\Services\InventoryCostingConfig;
use Tests\TestCase;

class GrnApprovePosterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! \Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
            $this->markTestSkipped('Accounting migrations not run.');
        }
        foreach ([
            ['1310', 'Inventory Food', 'asset'],
            ['1311', 'Inventory Liquor', 'asset'],
            ['1420', 'Input GST', 'asset'],
            ['2110', 'GRNI', 'liability'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_posting' => true, 'is_active' => true]
            );
        }
        JournalPostingService::flushAccountCache();
    }

    protected function tearDown(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
            \App\Models\JournalEntry::query()->where('source_type', 'grn_approve')->delete();
        }
        parent::tearDown();
    }

    public function test_liquor_purchase_capitalizes_non_recoverable_vat_into_inventory(): void
    {
        $grn = $this->makeGrn(InventoryCostingConfig::MODE_TAX_AWARE);

        $inventoryItem = new \App\Models\InventoryItem(['is_alcohol' => true]);
        $poItem = new PurchaseOrderItemStub('vat');

        $line = new GrnItem([
            'quantity_accepted' => 1,
            'landed_unit_cost' => 650,
            'line_subtotal_accepted' => 500,
            'line_tax_accepted' => 120,
            'line_recoverable_tax_accepted' => 0,
            'line_non_recoverable_tax_accepted' => 120,
            'tax_input_credit_eligible' => false,
            'line_cess_accepted' => 30,
            'line_freight_allocated' => 0,
        ]);
        $line->setRelation('inventoryItem', $inventoryItem);
        $line->setRelation('purchaseOrderItem', $poItem);

        $grn->setRelation('items', collect([$line]));

        $entry = app(GrnApprovePoster::class)->post($grn);
        $codes = $entry->lines->load('account')->pluck('account.code')->all();

        $this->assertEqualsWithDelta(650, (float) $entry->lines->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(650, (float) $entry->lines->sum('credit'), 0.01);
        $this->assertContains(AccountCodes::INVENTORY_LIQUOR, $codes);
        $this->assertNotContains(AccountCodes::INPUT_GST, $codes);
        $this->assertNotContains(AccountCodes::DEFERRED_PROCUREMENT, $codes);
        $this->assertContains(AccountCodes::GRNI, $codes);

        $liquorDebit = (float) $entry->lines->firstWhere('account.code', AccountCodes::INVENTORY_LIQUOR)?->debit;
        $this->assertEqualsWithDelta(650, $liquorDebit, 0.01);
    }

    public function test_food_purchase_posts_recoverable_gst_to_input_asset(): void
    {
        $grn = $this->makeGrn(InventoryCostingConfig::MODE_TAX_AWARE);

        $inventoryItem = new \App\Models\InventoryItem(['is_alcohol' => false]);
        $poItem = new PurchaseOrderItemStub('gst');

        $line = new GrnItem([
            'quantity_accepted' => 1,
            'landed_unit_cost' => 500,
            'line_subtotal_accepted' => 500,
            'line_tax_accepted' => 90,
            'line_recoverable_tax_accepted' => 90,
            'line_non_recoverable_tax_accepted' => 0,
            'tax_input_credit_eligible' => true,
            'line_cess_accepted' => 0,
            'line_freight_allocated' => 0,
        ]);
        $line->setRelation('inventoryItem', $inventoryItem);
        $line->setRelation('purchaseOrderItem', $poItem);

        $grn->setRelation('items', collect([$line]));

        $entry = app(GrnApprovePoster::class)->post($grn);
        $codes = $entry->lines->load('account')->pluck('account.code')->all();

        $this->assertEqualsWithDelta(590, (float) $entry->lines->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(590, (float) $entry->lines->sum('credit'), 0.01);
        $this->assertContains(AccountCodes::INVENTORY_FOOD, $codes);
        $this->assertContains(AccountCodes::INPUT_GST, $codes);
        $this->assertNotContains(AccountCodes::DEFERRED_PROCUREMENT, $codes);

        $foodDebit = (float) $entry->lines->firstWhere('account.code', AccountCodes::INVENTORY_FOOD)?->debit;
        $gstDebit = (float) $entry->lines->firstWhere('account.code', AccountCodes::INPUT_GST)?->debit;
        $this->assertEqualsWithDelta(500, $foodDebit, 0.01);
        $this->assertEqualsWithDelta(90, $gstDebit, 0.01);
    }

    private function makeGrn(string $costingMode): \App\Models\GRN
    {
        $grn = new \App\Models\GRN([
            'id' => 1,
            'grn_number' => 'GRN-1',
            'purchase_order_id' => 10,
            'received_date' => '2026-06-10',
            'approved_at' => now(),
            'inventory_costing_mode' => $costingMode,
        ]);
        $grn->id = 1;
        $grn->setRelation('purchaseOrder', null);

        return $grn;
    }
}

/** @internal */
class PurchaseOrderItemStub extends \App\Models\PurchaseOrderItem
{
    public function __construct(public string $tax_type)
    {
        parent::__construct();
    }
}
