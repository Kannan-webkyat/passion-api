<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Front-office reset: clear stays and what they created, keep POS, inventory, procurement and masters.
 * Dry run by default. Pass --force to apply.
 */
class WipeBookingData extends Command
{
    protected $signature = 'db:wipe-bookings
                            {--force : Apply the deletes (otherwise only counts are shown)}
                            {--backup : Take a mysqldump before deleting}
                            {--yes : Skip the typed database-name confirmation}';

    protected $description = 'Clear bookings, folio, stay-driven housekeeping and checkout journals; keep POS and inventory';

    /** Turnover blocks created by stays. Maintenance and on_hold blocks are kept. */
    private const TURNOVER_BLOCK_STATUSES = ['dirty', 'cleaning', 'inspected', 'pending_inspection'];

    /** Room statuses reset to available. Maintenance is kept. */
    private const RESET_ROOM_STATUSES = ['occupied', 'dirty', 'cleaning', 'pending_inspection', 'inspected'];

    /** Truncated in this order (children first). */
    private const BOOKING_TABLES = [
        'aiosell_messages',
        'aiosell_booking_links',
        'doorloom_booking_links',
        'booking_payments',
        'booking_extra_charges',
        'booking_room_transfers',
        'booking_segments',
        'bookings',
        'booking_groups',
        'room_cleaning_release_audits',
        'room_cleaning_releases',
        'daily_room_cleaning_consumptions',
        'daily_room_cleanings',
        'laundry_request_lines',
        'laundry_requests',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $database = DB::getDatabaseName();

        $this->line('Environment: '.app()->environment()." | Database: {$database}");
        $this->line('KEPT: POS orders and payments, inventory and stock history, procurement/GRN,');
        $this->line('      non-checkout journals, maintenance/on-hold blocks, masters.');
        $this->newLine();

        $plan = $this->plan();
        foreach ($plan as $label => $count) {
            $this->line(sprintf('  %-60s %d', $label, $count));
        }
        $this->newLine();

        if (! $apply) {
            $this->info('Dry run. Nothing changed. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        if (($plan['journal_entries (source_type=booking_checkout)'] ?? 0) > 0) {
            $this->warn('Checkout journals are already in the accounts. Deleting them changes the trial balance.');
        }

        if (! $this->option('yes')) {
            $typed = (string) $this->ask("Type the database name to confirm ({$database})");
            if ($typed !== $database) {
                $this->error('Database name did not match. Aborted.');

                return self::FAILURE;
            }
        }

        if ($this->option('backup') && ! $this->dumpDatabase($database)) {
            $this->error('Backup failed. Aborted before deleting.');

            return self::FAILURE;
        }

        DB::transaction(function () {
            if (Schema::hasTable('pos_orders') && Schema::hasColumn('pos_orders', 'booking_id')) {
                $n = DB::table('pos_orders')->whereNotNull('booking_id')->update(['booking_id' => null]);
                $this->line("Unlinked pos_orders.booking_id ({$n} rows)");
            }
            if (Schema::hasTable('journal_entries')) {
                $ids = DB::table('journal_entries')->where('source_type', 'booking_checkout')->pluck('id');
                if ($ids->isNotEmpty()) {
                    if (Schema::hasTable('journal_lines')) {
                        DB::table('journal_lines')->whereIn('journal_entry_id', $ids)->delete();
                    }
                    DB::table('journal_entries')->whereIn('id', $ids)->delete();
                }
                $this->line("Deleted booking_checkout journals ({$ids->count()} entries)");
            }

            if (Schema::hasTable('room_status_blocks')) {
                $blockIds = DB::table('room_status_blocks')->whereIn('status', self::TURNOVER_BLOCK_STATUSES)->pluck('id');
                if ($blockIds->isNotEmpty() && Schema::hasTable('housekeeping_jobs')) {
                    $jobIds = DB::table('housekeeping_jobs')->whereIn('room_status_block_id', $blockIds)->pluck('id');
                    if ($jobIds->isNotEmpty() && Schema::hasTable('housekeeping_job_lines')) {
                        DB::table('housekeeping_job_lines')->whereIn('housekeeping_job_id', $jobIds)->delete();
                    }
                    DB::table('housekeeping_jobs')->whereIn('id', $jobIds)->delete();
                    $this->line("Deleted turnover housekeeping_jobs ({$jobIds->count()} rows)");
                }
                DB::table('room_status_blocks')->whereIn('id', $blockIds)->delete();
                $this->line("Deleted turnover room_status_blocks ({$blockIds->count()} rows)");
            }

            foreach (self::BOOKING_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $n = DB::table($table)->delete();
                $this->line("Cleared {$table} ({$n} rows)");
            }

            if (Schema::hasTable('rooms') && Schema::hasColumn('rooms', 'status')) {
                $n = DB::table('rooms')->whereIn('status', self::RESET_ROOM_STATUSES)->update(['status' => 'available']);
                $this->line("Reset rooms.status → available ({$n} rows)");
            }
        });

        $this->newLine();
        $this->info('Done. Bookings cleared. POS, inventory and masters kept.');
        $this->line('If AioSell is connected, open Settings → Integrations and click Push now.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function plan(): array
    {
        $plan = [];
        foreach (self::BOOKING_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $plan["{$table} (delete all)"] = DB::table($table)->count();
            }
        }
        if (Schema::hasTable('room_status_blocks')) {
            $blockIds = DB::table('room_status_blocks')->whereIn('status', self::TURNOVER_BLOCK_STATUSES)->pluck('id');
            $plan['room_status_blocks (dirty/cleaning/inspected/pending_inspection)'] = $blockIds->count();
            if (Schema::hasTable('housekeeping_jobs')) {
                $plan['housekeeping_jobs (on those blocks)'] = DB::table('housekeeping_jobs')->whereIn('room_status_block_id', $blockIds)->count();
            }
        }
        if (Schema::hasTable('journal_entries')) {
            $plan['journal_entries (source_type=booking_checkout)'] = DB::table('journal_entries')->where('source_type', 'booking_checkout')->count();
        }
        if (Schema::hasTable('pos_orders') && Schema::hasColumn('pos_orders', 'booking_id')) {
            $plan['pos_orders (unlink booking_id only)'] = DB::table('pos_orders')->whereNotNull('booking_id')->count();
        }
        if (Schema::hasTable('rooms') && Schema::hasColumn('rooms', 'status')) {
            $plan['rooms (status reset to available)'] = DB::table('rooms')->whereIn('status', self::RESET_ROOM_STATUSES)->count();
        }

        return $plan;
    }

    private function dumpDatabase(string $database): bool
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}");

            return false;
        }

        $file = $dir.'/pre-wipe-bookings-'.$database.'-'.date('Ymd-His').'.sql';
        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s %s > %s 2>&1',
            escapeshellarg((string) config('database.connections.mysql.host')),
            escapeshellarg((string) config('database.connections.mysql.port')),
            escapeshellarg((string) config('database.connections.mysql.username')),
            config('database.connections.mysql.password')
                ? '--password='.escapeshellarg((string) config('database.connections.mysql.password'))
                : '',
            escapeshellarg($database),
            escapeshellarg($file),
        );

        exec($cmd, $out, $code);

        if ($code !== 0 || ! is_file($file) || filesize($file) < 1024) {
            $this->error('mysqldump failed: '.implode(' ', $out));

            return false;
        }

        $this->info('Backup written: '.$file.' ('.round(filesize($file) / 1048576, 1).' MB)');

        return true;
    }
}
