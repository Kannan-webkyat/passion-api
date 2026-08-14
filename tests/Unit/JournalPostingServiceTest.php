<?php

namespace Tests\Unit;

use App\Exceptions\JournalPostingException;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalPostingService;
use Tests\TestCase;

class JournalPostingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! \Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
            $this->markTestSkipped('Accounting migrations not run.');
        }
        $this->ensureTestAccounts();
        JournalPostingService::flushAccountCache();
    }

    protected function tearDown(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
            JournalEntry::query()->where('source_type', 'like', 'test_%')->delete();
        }
        parent::tearDown();
    }

    public function test_post_creates_balanced_journal(): void
    {
        $service = app(JournalPostingService::class);

        $entry = $service->post(
            sourceType: 'test_balanced',
            sourceId: random_int(100000, 999999),
            entryDate: '2026-06-20',
            businessDate: null,
            sourceRef: 'TEST-1',
            memo: 'Test',
            lines: [
                ['account_code' => '1110', 'debit' => 118.0],
                ['account_code' => '4210', 'credit' => 100.0],
                ['account_code' => '2210', 'credit' => 18.0],
            ],
        );

        $this->assertSame('posted', $entry->status);
        $this->assertCount(3, $entry->lines);

        $debit = (float) $entry->lines->sum('debit');
        $credit = (float) $entry->lines->sum('credit');
        $this->assertEqualsWithDelta($debit, $credit, 0.01);
    }

    public function test_post_is_idempotent(): void
    {
        $service = app(JournalPostingService::class);
        $lines = [
            ['account_code' => '1110', 'debit' => 50.0],
            ['account_code' => '4210', 'credit' => 50.0],
        ];

        $first = $service->post('test_idempotent', 99, '2026-06-20', null, null, null, $lines);
        $second = $service->post('test_idempotent', 99, '2026-06-20', null, null, null, $lines);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'test_idempotent')->count());
    }

    public function test_unbalanced_journal_throws(): void
    {
        $this->expectException(JournalPostingException::class);

        app(JournalPostingService::class)->post(
            sourceType: 'bad',
            sourceId: 1,
            entryDate: '2026-06-20',
            businessDate: null,
            sourceRef: null,
            memo: null,
            lines: [
                ['account_code' => '1110', 'debit' => 100.0],
                ['account_code' => '4210', 'credit' => 90.0],
            ],
        );
    }

    public function test_one_paise_rounding_is_absorbed_without_inflating_credit(): void
    {
        $service = app(JournalPostingService::class);

        $entry = $service->post(
            sourceType: 'test_penny',
            sourceId: random_int(100000, 999999),
            entryDate: '2026-06-20',
            businessDate: null,
            sourceRef: 'GRN-PENNY',
            memo: 'One paise GRN rounding',
            lines: [
                ['account_code' => '1110', 'debit' => 641291.23],
                ['account_code' => '4210', 'credit' => 641291.22],
            ],
        );

        $debit = round((float) $entry->lines->sum('debit'), 2);
        $credit = round((float) $entry->lines->sum('credit'), 2);
        $this->assertSame($debit, $credit);
        $this->assertEqualsWithDelta(641291.22, $debit, 0.001);
        $this->assertEqualsWithDelta(641291.22, (float) $entry->lines->firstWhere('account.code', '4210')?->credit, 0.001);
        $this->assertEqualsWithDelta(641291.22, (float) $entry->lines->firstWhere('account.code', '1110')?->debit, 0.001);
    }

    private function ensureTestAccounts(): void
    {
        foreach ([
            ['1110', 'Cash', 'asset'],
            ['4210', 'Restaurant Sales', 'income'],
            ['2210', 'Output CGST', 'liability'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_posting' => true, 'is_active' => true]
            );
        }
    }
}
