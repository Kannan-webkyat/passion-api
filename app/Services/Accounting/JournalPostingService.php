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

        $this->absorbRoundingImbalance($normalized);
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

    /**
     * Replace line items on an existing posted entry (maintenance / tax model backfill).
     * When no posted entry exists, delegates to post().
     *
     * @param  array<int, array{account_code: string, debit?: float|string, credit?: float|string, tax_tag?: string|null, meta?: array<string, mixed>|null}>  $lines
     */
    public function replacePosted(
        string $sourceType,
        int $sourceId,
        string $entryDate,
        ?string $businessDate,
        ?string $sourceRef,
        ?string $memo,
        array $lines,
        ?int $postedBy = null
    ): JournalEntry {
        $normalized = $this->normalizeLines($lines);
        if ($normalized === []) {
            throw new JournalPostingException("No journal lines for {$sourceType}#{$sourceId}");
        }

        $this->absorbRoundingImbalance($normalized);
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
            $existing = JournalEntry::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', JournalEntry::STATUS_POSTED)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                return $this->post(
                    $sourceType,
                    $sourceId,
                    $entryDate,
                    $businessDate,
                    $sourceRef,
                    $memo,
                    $this->denormalizeForPost($normalized),
                    $postedBy
                );
            }

            $existing->lines()->delete();

            foreach ($normalized as $index => $line) {
                JournalLine::create([
                    'journal_entry_id' => $existing->id,
                    'line_no' => $index + 1,
                    'account_id' => $this->accountId($line['account_code']),
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'tax_tag' => $line['tax_tag'] ?? null,
                    'meta' => $line['meta'] ?? null,
                ]);
            }

            $existing->update([
                'entry_date' => $entryDate,
                'business_date' => $businessDate,
                'source_ref' => $sourceRef,
                'memo' => $memo,
                'posted_by' => $postedBy ?? $existing->posted_by,
            ]);

            return $existing->fresh(['lines.account']);
        });
    }

    /**
     * @param  list<array{account_code: string, debit: float, credit: float, tax_tag: ?string, meta: ?array}>  $normalized
     * @return list<array{account_code: string, debit?: float, credit?: float, tax_tag?: ?string, meta?: ?array}>
     */
    private function denormalizeForPost(array $normalized): array
    {
        return array_map(fn (array $line) => array_filter([
            'account_code' => $line['account_code'],
            'debit' => ($line['debit'] ?? 0) > 0 ? $line['debit'] : null,
            'credit' => ($line['credit'] ?? 0) > 0 ? $line['credit'] : null,
            'tax_tag' => $line['tax_tag'] ?? null,
            'meta' => $line['meta'] ?? null,
        ], fn ($v) => $v !== null), $normalized);
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

    /**
     * Multi-line posters (esp. GRN: landed 4dp × qty vs GRNI 2dp) can drift a few paise.
     * Absorb up to 5 paise on the oversized side so the journal stays balanced
     * (do not inflate the light side / GRNI when debit is short — trim the heavy side).
     *
     * @param  list<array{account_code: string, debit: float, credit: float, tax_tag: ?string, meta: ?array}>  $lines
     */
    private function absorbRoundingImbalance(array &$lines): void
    {
        $diffPaise = $this->debitCreditDiffPaise($lines);
        if ($diffPaise === 0) {
            return;
        }

        // Max 5 paise (₹0.05) — enough for large Bevco GRNs; larger gaps stay hard errors.
        if (abs($diffPaise) > 5) {
            return;
        }

        $side = $diffPaise > 0 ? 'debit' : 'credit';
        $adjustPaise = abs($diffPaise);
        $best = null;
        $bestPaise = -1;
        foreach ($lines as $i => $line) {
            $paise = (int) round(((float) $line[$side]) * 100);
            if ($paise > $bestPaise) {
                $bestPaise = $paise;
                $best = $i;
            }
        }

        if ($best === null || $bestPaise < $adjustPaise) {
            return;
        }

        $lines[$best][$side] = round(($bestPaise - $adjustPaise) / 100, 2);
        $lines = array_values(array_filter($lines, fn (array $l) => $l['debit'] > 0 || $l['credit'] > 0));
    }

    /** @param list<array{debit: float, credit: float}> $lines */
    private function debitCreditDiffPaise(array $lines): int
    {
        $debitPaise = 0;
        $creditPaise = 0;
        foreach ($lines as $line) {
            $debitPaise += (int) round(((float) $line['debit']) * 100);
            $creditPaise += (int) round(((float) $line['credit']) * 100);
        }

        return $debitPaise - $creditPaise;
    }

    /** @param list<array{debit: float, credit: float}> $lines */
    private function assertBalanced(array $lines): void
    {
        $diffPaise = $this->debitCreditDiffPaise($lines);
        if ($diffPaise === 0) {
            return;
        }

        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        throw new JournalPostingException("Journal not balanced: debit={$debit} credit={$credit}");
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
