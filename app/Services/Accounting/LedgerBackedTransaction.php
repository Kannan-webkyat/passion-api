<?php

namespace App\Services\Accounting;

use App\Exceptions\JournalPostingException;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fail-closed wrapper: stock / state mutation and journal posting share one DB transaction.
 * If a required journal entry is missing or posting throws, the entire operation rolls back.
 *
 * Usage:
     *   app(LedgerBackedTransaction::class)->run(
     *       mutate: fn () => $this->postStock(...),
     *       postMutate: fn ($result) => app(InventoryCostLayerService::class)->recordGrnReceipt($result, $lineReceipts, $userId),
     *       postJournal: fn ($result) => app(GrnApprovePoster::class)->postStrict($result, $userId),
     *       journalRequired: fn ($result) => app(GrnApprovePoster::class)->isJournalRequired($result),
     *   );
 */
final class LedgerBackedTransaction
{
    /**
     * @template T
     *
     * @param  callable(): T  $mutate
     * @param  (callable(T): void)|null  $postMutate  Runs after mutate, before journal (e.g. cost audit layers).
     * @param  (callable(T): JournalEntry|null)|null  $postJournal
     * @param  (callable(T): bool)|null  $journalRequired  When omitted and $postJournal is set, journal is always required.
     * @return T
     */
    public function run(
        callable $mutate,
        ?callable $postMutate = null,
        ?callable $postJournal = null,
        ?callable $journalRequired = null,
    ): mixed {
        return DB::transaction(function () use ($mutate, $postMutate, $postJournal, $journalRequired) {
            $result = $mutate();

            if ($postMutate !== null) {
                try {
                    $postMutate($result);
                } catch (JournalPostingException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new JournalPostingException(
                        'Post-mutation step failed: '.$e->getMessage(),
                        (int) $e->getCode(),
                        $e
                    );
                }
            }

            if ($postJournal === null) {
                return $result;
            }

            $required = $journalRequired !== null
                ? (bool) $journalRequired($result)
                : true;

            if (! $required) {
                return $result;
            }

            LedgerPostingGuard::assertInfrastructure();

            try {
                $entry = $postJournal($result);
            } catch (JournalPostingException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new JournalPostingException(
                    'Journal posting failed: '.$e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }

            LedgerPostingGuard::requireEntry($entry, 'ledger-backed stock mutation');

            return $result;
        });
    }

    /**
     * For journal posts that occur mid-mutation (e.g. POS COGS per deduction line).
     */
    public function assertJournalWhenRequired(?JournalEntry $entry, bool $required, string $context): void
    {
        if (! $required) {
            return;
        }

        LedgerPostingGuard::requireEntry($entry, $context);
    }
}
