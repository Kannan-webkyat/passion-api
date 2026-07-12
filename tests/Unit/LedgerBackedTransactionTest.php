<?php

namespace Tests\Unit;

use App\Exceptions\JournalPostingException;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerBackedTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LedgerBackedTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('journal_entries')) {
            $this->markTestSkipped('Accounting migrations not run.');
        }

        foreach ([
            ['1110', 'Cash', 'asset'],
            ['4210', 'Restaurant Sales', 'income'],
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
        if (Schema::hasTable('journal_entries')) {
            JournalEntry::query()->where('source_type', 'like', 'test_ledger_%')->delete();
        }

        if (Schema::hasTable('ledger_backed_test_runs')) {
            DB::table('ledger_backed_test_runs')->truncate();
        }

        parent::tearDown();
    }

    public function test_rolls_back_mutation_when_journal_posting_fails(): void
    {
        if (! Schema::hasTable('ledger_backed_test_runs')) {
            Schema::create('ledger_backed_test_runs', function ($table) {
                $table->id();
                $table->string('marker')->nullable();
                $table->timestamps();
            });
        }

        $ledger = app(LedgerBackedTransaction::class);

        try {
            $ledger->run(
                mutate: function () {
                    DB::table('ledger_backed_test_runs')->insert([
                        'marker' => 'should_roll_back',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return 'mutated';
                },
                postJournal: function () {
                    throw new JournalPostingException('Simulated posting failure');
                },
            );

            $this->fail('Expected JournalPostingException was not thrown.');
        } catch (JournalPostingException $e) {
            $this->assertStringContainsString('Simulated posting failure', $e->getMessage());
        }

        $this->assertSame(0, DB::table('ledger_backed_test_runs')->where('marker', 'should_roll_back')->count());
    }

    public function test_commits_mutation_when_journal_not_required(): void
    {
        if (! Schema::hasTable('ledger_backed_test_runs')) {
            Schema::create('ledger_backed_test_runs', function ($table) {
                $table->id();
                $table->string('marker')->nullable();
                $table->timestamps();
            });
        }

        $result = app(LedgerBackedTransaction::class)->run(
            mutate: function () {
                DB::table('ledger_backed_test_runs')->insert([
                    'marker' => 'no_journal_needed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 'ok';
            },
            postJournal: function () {
                throw new JournalPostingException('Should not be called');
            },
            journalRequired: fn () => false,
        );

        $this->assertSame('ok', $result);
        $this->assertSame(1, DB::table('ledger_backed_test_runs')->where('marker', 'no_journal_needed')->count());
    }

    public function test_commits_when_journal_posts_successfully(): void
    {
        if (! Schema::hasTable('ledger_backed_test_runs')) {
            Schema::create('ledger_backed_test_runs', function ($table) {
                $table->id();
                $table->string('marker')->nullable();
                $table->timestamps();
            });
        }

        $sourceId = random_int(100000, 999999);

        app(LedgerBackedTransaction::class)->run(
            mutate: function () use ($sourceId) {
                DB::table('ledger_backed_test_runs')->insert([
                    'marker' => 'with_journal',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $sourceId;
            },
            postJournal: fn (int $id) => app(JournalPostingService::class)->post(
                sourceType: 'test_ledger_backed',
                sourceId: $id,
                entryDate: '2026-07-12',
                businessDate: null,
                sourceRef: 'TEST',
                memo: 'Ledger-backed test',
                lines: [
                    ['account_code' => '1110', 'debit' => 10.0],
                    ['account_code' => '4210', 'credit' => 10.0],
                ],
            ),
        );

        $this->assertSame(1, DB::table('ledger_backed_test_runs')->where('marker', 'with_journal')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'test_ledger_backed')->where('source_id', $sourceId)->count());
    }
}
