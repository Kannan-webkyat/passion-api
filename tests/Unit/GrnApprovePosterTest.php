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
            ['1360', 'Deferred', 'asset'],
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
            \App\Models\JournalEntry::query()->where('source_type', 'grn_approve')->where('source_id', 1)->delete();
        }
        parent::tearDown();
    }

    public function test_builds_balanced_grn_journal_from_grn_item_fields(): void
    {
        $grn = new \App\Models\GRN([
            'id' => 1,
            'grn_number' => 'GRN-1',
            'purchase_order_id' => 10,
            'received_date' => '2026-06-10',
            'approved_at' => now(),
            'inventory_costing_mode' => InventoryCostingConfig::MODE_EXCLUSIVE_ONLY,
        ]);
        $grn->id = 1;

        $inventoryItem = new \App\Models\InventoryItem(['is_alcohol' => false]);
        $poItem = new PurchaseOrderItemStub('gst');

        $line = new GrnItem([
            'quantity_accepted' => 10,
            'landed_unit_cost' => 100,
            'line_subtotal_accepted' => 1000,
            'line_tax_accepted' => 180,
            'line_cess_accepted' => 0,
            'line_freight_allocated' => 50,
        ]);
        $line->setRelation('inventoryItem', $inventoryItem);
        $line->setRelation('purchaseOrderItem', $poItem);

        $grn->setRelation('items', collect([$line]));
        $grn->setRelation('purchaseOrder', null);

        $entry = app(GrnApprovePoster::class)->post($grn);

        $this->assertSame('grn_approve', $entry->source_type);
        $this->assertEqualsWithDelta(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit'),
            0.01
        );

        $codes = $entry->lines->load('account')->pluck('account.code')->all();
        $this->assertContains(AccountCodes::INVENTORY_FOOD, $codes);
        $this->assertContains(AccountCodes::INPUT_GST, $codes);
        $this->assertContains(AccountCodes::DEFERRED_PROCUREMENT, $codes);
        $this->assertContains(AccountCodes::GRNI, $codes);
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
