<?php

namespace App\Services\Accounting;

use App\Exceptions\JournalPostingException;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\Schema;

/**
 * Fail-closed checks for ledger-backed stock movements.
 */
final class LedgerPostingGuard
{
    public static function assertInfrastructure(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('journal_lines')) {
            throw new JournalPostingException(
                'Accounting journal tables are not migrated. Stock movements cannot post without a ledger entry.'
            );
        }

        if (! Schema::hasTable('chart_of_accounts')) {
            throw new JournalPostingException(
                'Chart of accounts is not available. Stock movements cannot post without a ledger entry.'
            );
        }
    }

    public static function requireEntry(?JournalEntry $entry, string $context): JournalEntry
    {
        self::assertInfrastructure();

        if ($entry === null) {
            throw new JournalPostingException(
                "Required journal entry was not created ({$context}). The stock mutation was rolled back."
            );
        }

        return $entry;
    }
}
