<?php

namespace App\Services\Accounting;

use App\Exceptions\JournalPostingException;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Double-entry journal writer. Idempotent per (source_type, source_id) when status=posted.
 */
final class JournalPostingService
{
  /** @var Collection<string, int>|null */
    private static ?Collection $accountIdsByCode = null;

    /**
     * @param  array<int, array{account_code: string, debit?: float|string, credit?: float|string, tax_tag?: string|null, meta?: array<string, mixed>|null}>  $lines
     */
    public function post(
        string $sourceType,
        int $sourceId,
        string $entryDate,
        ?string $businessDate,
        ?string $sourceRef,
        ?string $memo,
        array $lines,
        ?int $postedBy = null
    ): JournalEntry {
        $existing = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', JournalEntry::STATUS_POSTED)
            ->first();

        if ($existing) {
            return $existing->load('lines.account');
        }

        $normalized = $this->normalizeLines($lines);
        if ($normalized === []) {
            throw new JournalPostingException("No journal lines for {$sourceType}#{$sourceId}");
        }

        $this->assertBalanced($normalized);

        return DB::transaction(function () use (
            $sourceType,
            $sourceId,
            $entryDate,
            $businessDate,
            $sourceRef,
            $memo,
            $normalized,
            $postedBy
        ) {
            $duplicate = JournalEntry::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                return $duplicate->load('lines.account');
            }

            $entry = JournalEntry::create([
                'entry_number' => $this->nextEntryNumber($entryDate),
                'entry_date' => $entryDate,
                'business_date' => $businessDate,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_ref' => $sourceRef,
                'memo' => $memo,
                'status' => JournalEntry::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $postedBy,
            ]);

            foreach ($normalized as $index => $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'line_no' => $index + 1,
                    'account_id' => $this->accountId($line['account_code']),
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'tax_tag' => $line['tax_tag'] ?? null,
                    'meta' => $line['meta'] ?? null,
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    public function accountId(string $code): int
    {
        if (self::$accountIdsByCode === null) {
            self::$accountIdsByCode = ChartOfAccount::query()
                ->where('is_active', true)
                ->pluck('id', 'code');
        }

        $id = self::$accountIdsByCode->get($code);
        if ($id === null) {
            throw new JournalPostingException("Chart of account not found: {$code}");
        }

        return (int) $id;
    }

    public static function flushAccountCache(): void
    {
        self::$accountIdsByCode = null;
    }

    /**
     * @param  array<int, array{account_code: string, debit?: float|string, credit?: float|string, tax_tag?: string|null, meta?: array<string, mixed>|null}>  $lines
     * @return list<array{account_code: string, debit: float, credit: float, tax_tag: ?string, meta: ?array}>
     */
    private function normalizeLines(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $code = trim((string) ($line['account_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $debit = round(max(0, (float) ($line['debit'] ?? 0)), 2);
            $credit = round(max(0, (float) ($line['credit'] ?? 0)), 2);
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $key = $code.'|'.($line['tax_tag'] ?? '');
            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'account_code' => $code,
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'tax_tag' => $line['tax_tag'] ?? null,
                    'meta' => $line['meta'] ?? null,
                ];
            }

            $merged[$key]['debit'] = round($merged[$key]['debit'] + $debit, 2);
            $merged[$key]['credit'] = round($merged[$key]['credit'] + $credit, 2);
        }

        return array_values(array_filter($merged, fn (array $l) => $l['debit'] > 0 || $l['credit'] > 0));
    }

    /** @param list<array{debit: float, credit: float}> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        if (abs($debit - $credit) > 0.01) {
            throw new JournalPostingException("Journal not balanced: debit={$debit} credit={$credit}");
        }
    }

    private function nextEntryNumber(string $entryDate): string
    {
        $year = substr($entryDate, 0, 4);
        $prefix = "JE-{$year}-";

        $last = JournalEntry::query()
            ->where('entry_number', 'like', $prefix.'%')
            ->orderByDesc('entry_number')
            ->value('entry_number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
