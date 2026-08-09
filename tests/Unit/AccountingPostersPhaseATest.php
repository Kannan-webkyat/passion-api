<?php

namespace Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Services\Accounting\AccountCodes;
use App\Services\Accounting\BookingCheckoutPoster;
use App\Services\Accounting\InventoryAdjustmentPoster;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\PosRefundPoster;
use App\Models\Booking;
use App\Models\PosOrder;
use App\Models\PosOrderRefund;
use Tests\TestCase;

class AccountingPostersPhaseATest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! \Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
            $this->markTestSkipped('Accounting migrations not run.');
        }

        foreach ([
            ['1110', 'Cash', 'asset'],
            ['1130', 'Folio AR', 'asset'],
            ['1310', 'Inventory Food', 'asset'],
            ['2210', 'Output CGST', 'liability'],
            ['2211', 'Output SGST', 'liability'],
            ['4100', 'Room Revenue', 'income'],
            ['4210', 'Restaurant Sales', 'income'],
            ['4310', 'Delivery Charge Income', 'income'],
            ['4311', 'Packing / Parcel Charge Income', 'income'],
            ['4900', 'Sales Discounts', 'contra_income'],
            ['5200', 'Wastage', 'expense'],
            ['5210', 'Staff Meals', 'expense'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_posting' => true, 'is_active' => true]
            );
        }

        JournalPostingService::flushAccountCache();
    }

    public function test_booking_checkout_poster_balances_room_only_stay(): void
    {
        $booking = new Booking([
            'id' => 9001,
            'booking_unit' => 'hour_package',
            'total_price' => 1180,
            'extra_charges' => 0,
            'deposit_amount' => 1180,
            'payment_method' => 'cash',
            'refund_amount' => 0,
            'checkout_discount_amount' => 0,
            'check_out' => '2026-07-02',
        ]);
        $booking->id = 9001;

        $entry = app(BookingCheckoutPoster::class)->post($booking);

        $this->assertNotNull($entry);
        $this->assertEqualsWithDelta(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit'),
            0.01
        );
        $this->assertNotNull($entry->lines->firstWhere('account_id', ChartOfAccount::where('code', AccountCodes::ROOM_REVENUE)->value('id')));
    }

    public function test_pos_refund_poster_balances_proportional_reversal(): void
    {
        $order = new PosOrder([
            'id' => 501,
            'total_amount' => 200,
            'gst_net_taxable' => 160,
            'vat_net_taxable' => 0,
            'cgst_amount' => 14.4,
            'sgst_amount' => 14.4,
            'igst_amount' => 0,
            'vat_tax_amount' => 0,
            'discount_amount' => 0,
            'service_charge_amount' => 0,
            'delivery_charge' => 0,
            'packing_charge' => 10,
            'tip_amount' => 0,
            'rounding_amount' => 1.2,
        ]);
        $order->id = 501;

        $refund = new PosOrderRefund([
            'id' => 77,
            'order_id' => 501,
            'amount' => 100,
            'method' => 'cash',
            'business_date' => '2026-07-02',
            'refunded_at' => now(),
        ]);
        $refund->id = 77;
        $refund->setRelation('order', $order);

        $entry = app(PosRefundPoster::class)->post($refund);

        $this->assertNotNull($entry);
        $this->assertEqualsWithDelta(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit'),
            0.01
        );
        $packLine = $entry->lines->firstWhere(
            'account_id',
            ChartOfAccount::where('code', AccountCodes::PACKING_CHARGE)->value('id')
        );
        $this->assertNotNull($packLine);
        $this->assertEqualsWithDelta(5.0, (float) $packLine->debit, 0.01);
    }

    public function test_pos_settle_poster_credits_packing_charge(): void
    {
        $order = new PosOrder([
            'id' => 502,
            'total_amount' => 115,
            'gst_net_taxable' => 100,
            'vat_net_taxable' => 0,
            'cgst_amount' => 2.5,
            'sgst_amount' => 2.5,
            'igst_amount' => 0,
            'vat_tax_amount' => 0,
            'discount_amount' => 0,
            'service_charge_amount' => 0,
            'delivery_charge' => 0,
            'packing_charge' => 10,
            'tip_amount' => 0,
            'rounding_amount' => 0,
            'business_date' => '2026-08-09',
            'is_complimentary' => false,
        ]);
        $order->id = 502;
        $order->setRelation('payments', collect([
            new \App\Models\PosPayment([
                'id' => 1,
                'order_id' => 502,
                'method' => 'cash',
                'amount' => 115,
            ]),
        ]));

        $entry = app(\App\Services\Accounting\PosSettlePoster::class)->post($order);

        $this->assertNotNull($entry);
        $this->assertEqualsWithDelta(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit'),
            0.01
        );
        $packLine = $entry->lines->firstWhere(
            'account_id',
            ChartOfAccount::where('code', AccountCodes::PACKING_CHARGE)->value('id')
        );
        $this->assertNotNull($packLine);
        $this->assertEqualsWithDelta(10.0, (float) $packLine->credit, 0.01);
    }

    public function test_inventory_adjustment_poster_posts_wastage(): void
    {
        $item = new InventoryItem(['is_alcohol' => false]);
        $tx = new InventoryTransaction([
            'id' => 880,
            'type' => 'out',
            'reason' => 'Wastage',
            'total_cost' => 45.50,
            'created_at' => now(),
        ]);
        $tx->id = 880;
        $tx->setRelation('item', $item);

        $entry = app(InventoryAdjustmentPoster::class)->post($tx);

        $this->assertNotNull($entry);
        $this->assertEqualsWithDelta(45.50, (float) $entry->lines->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(45.50, (float) $entry->lines->sum('credit'), 0.01);
    }
}
