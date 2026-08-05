<?php

namespace Tests\Unit;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\TrialBalanceService;
use Tests\TestCase;

class TrialBalanceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! \Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
            $this->markTestSkipped('Accounting migrations not run.');
        }
        JournalPostingService::flushAccountCache();
    }

    protected function tearDown(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('journal_entries')) {
            JournalEntry::query()->where('source_type', 'test_trial_balance')->delete();
        }
        parent::tearDown();
    }

    public function test_for_period_returns_balanced_totals(): void
    {
        $cash = ChartOfAccount::query()->firstOrCreate(
            ['code' => '1110'],
            ['name' => 'Cash', 'type' => 'asset', 'is_posting' => true, 'is_active' => true]
        );
        $sales = ChartOfAccount::query()->firstOrCreate(
            ['code' => '4210'],
            ['name' => 'Restaurant Sales', 'type' => 'income', 'is_posting' => true, 'is_active' => true]
        );

        $entry = JournalEntry::create([
            'entry_number' => 'JE-TEST-TB-'.uniqid(),
            'entry_date' => '2026-06-15',
            'source_type' => 'test_trial_balance',
            'source_id' => random_int(1, 999999),
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
        ]);

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'line_no' => 1,
            'account_id' => $cash->id,
            'debit' => 500,
            'credit' => 0,
        ]);
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'line_no' => 2,
            'account_id' => $sales->id,
            'debit' => 0,
            'credit' => 500,
        ]);

        $result = app(TrialBalanceService::class)->forPeriod('2026-06-01', '2026-06-30');

        $this->assertTrue($result['totals']['balanced']);
        $this->assertEqualsWithDelta(500.0, $result['totals']['debit'], 0.01);
        $this->assertEqualsWithDelta(500.0, $result['totals']['credit'], 0.01);

        $cashRow = collect($result['accounts'])->firstWhere('code', '1110');
        $this->assertNotNull($cashRow);
        $this->assertEqualsWithDelta(500.0, $cashRow['balance'], 0.01);
    }
}
