<?php

namespace Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Accounting\AccountCodes;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\VendorPaymentPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VendorPaymentPartialFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('vendor_payments')) {
            $this->markTestSkipped('Vendor payment migrations not available.');
        }

        $this->seedChartAccounts();
        JournalPostingService::flushAccountCache();
    }

    public function test_partial_then_full_payment_updates_status_and_posts_grni_journals(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('accounting-vendor-pay');
        $user->givePermissionTo('accounting-vendor-pay');
        Sanctum::actingAs($user);

        $vendor = Vendor::query()->create([
            'name' => 'Test BEVCO',
            'phone' => '9999999999',
        ]);

        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-TEST-PARTIAL-'.random_int(1000, 9999),
            'vendor_id' => $vendor->id,
            'location_id' => null,
            'order_date' => now()->toDateString(),
            'status' => 'received',
            'payment_status' => 'pending',
            'total_amount' => 1000,
            'grand_total_payable' => 1000,
            'paid_amount' => 0,
            'created_by' => $user->id,
            'received_at' => now(),
        ]);

        $first = $this->postJson("/api/inventory/purchase-orders/{$po->id}/pay", [
            'payment_method' => 'transfer',
            'payment_reference' => 'UTR-PARTIAL-400',
            'paid_amount' => 400,
        ]);
        $first->assertOk();

        $po->refresh();
        $this->assertSame('partially_paid', $po->payment_status);
        $this->assertEqualsWithDelta(400.0, (float) $po->paid_amount, 0.01);
        $this->assertCount(1, VendorPayment::query()->where('purchase_order_id', $po->id)->get());

        $second = $this->postJson("/api/inventory/purchase-orders/{$po->id}/pay", [
            'payment_method' => 'transfer',
            'payment_reference' => 'UTR-FINAL-600',
            'paid_amount' => 600,
        ]);
        $second->assertOk();

        $po->refresh();
        $this->assertSame('paid', $po->payment_status);
        $this->assertEqualsWithDelta(1000.0, (float) $po->paid_amount, 0.01);
        $this->assertCount(2, VendorPayment::query()->where('purchase_order_id', $po->id)->get());

        $payments = VendorPayment::query()->where('purchase_order_id', $po->id)->orderBy('id')->get();
        $this->assertNotNull($payments[0]->journal_entry_id);
        $this->assertNotNull($payments[1]->journal_entry_id);

        $grniDebits = (float) \App\Models\JournalLine::query()
            ->whereIn('journal_entry_id', $payments->pluck('journal_entry_id'))
            ->whereHas('account', fn ($q) => $q->where('code', AccountCodes::GRNI))
            ->sum('debit');
        $this->assertEqualsWithDelta(1000.0, $grniDebits, 0.01);
    }

    public function test_overpayment_is_rejected(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('accounting-vendor-pay');
        $user->givePermissionTo('accounting-vendor-pay');
        Sanctum::actingAs($user);

        $vendor = Vendor::query()->create([
            'name' => 'Test Vendor',
            'phone' => '8888888888',
        ]);

        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-TEST-OVER-'.random_int(1000, 9999),
            'vendor_id' => $vendor->id,
            'order_date' => now()->toDateString(),
            'status' => 'received',
            'payment_status' => 'pending',
            'total_amount' => 1000,
            'grand_total_payable' => 1000,
            'paid_amount' => 0,
            'created_by' => $user->id,
            'received_at' => now(),
        ]);

        $response = $this->postJson("/api/inventory/purchase-orders/{$po->id}/pay", [
            'payment_method' => 'transfer',
            'payment_reference' => 'UTR-TOO-MUCH',
            'paid_amount' => 1001,
        ]);

        $response->assertStatus(422);
        $po->refresh();
        $this->assertSame('pending', $po->payment_status);
        $this->assertEqualsWithDelta(0.0, (float) $po->paid_amount, 0.01);
    }

    public function test_vendor_payment_poster_debits_grni_and_credits_bank(): void
    {
        $vendor = Vendor::query()->create([
            'name' => 'Poster Vendor',
            'phone' => '7777777777',
        ]);

        $po = PurchaseOrder::query()->create([
            'po_number' => 'PO-TEST-POSTER-'.random_int(1000, 9999),
            'vendor_id' => $vendor->id,
            'order_date' => now()->toDateString(),
            'status' => 'received',
            'payment_status' => 'pending',
            'total_amount' => 500,
            'grand_total_payable' => 500,
            'paid_amount' => 0,
        ]);

        $payment = VendorPayment::query()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => $vendor->id,
            'amount' => 250,
            'payment_method' => 'transfer',
            'payment_reference' => 'UTR-250',
            'paid_at' => now(),
        ]);

        $entry = app(VendorPaymentPoster::class)->post($payment);
        $payment->refresh();

        $this->assertNotNull($entry);
        $this->assertSame($entry->id, $payment->journal_entry_id);

        $codes = $entry->lines->map(fn ($l) => $l->account->code ?? ChartOfAccount::find($l->account_id)?->code)->all();
        $this->assertContains(AccountCodes::GRNI, $codes);

        $debitGrni = $entry->lines->first(fn ($l) => ($l->account->code ?? '') === AccountCodes::GRNI);
        $this->assertNotNull($debitGrni);
        $this->assertEqualsWithDelta(250.0, (float) $debitGrni->debit, 0.01);
    }

    private function seedChartAccounts(): void
    {
        foreach ([
            [AccountCodes::GRNI, 'GRNI', 'liability'],
            [AccountCodes::BANK_CARD, 'Bank', 'asset'],
            ['1110', 'Cash', 'asset'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_posting' => true, 'is_active' => true]
            );
        }
    }
}
