<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live / soft reset: clear transactional ops, keep masters
 * (menu, prices, recipes, inventory item defs, outlets, users, vendors, COA).
 */
class WipeOperationalData extends Command
{
    protected $signature = 'db:wipe-ops
                            {--force : Required to actually run}
                            {--with-hotel : Also clear bookings / HK / laundry ops}
                            {--keep-tokens : Do not clear API tokens (users stay logged in)}
                            {--backup : Take a mysqldump before wiping}
                            {--yes : Skip the typed database-name confirmation}';

    protected $description = 'Clear sales, stock qty, POs/GRN, journals; keep menu items & prices';

    /** @var list<string> */
    private array $posTables = [
        'pos_void_waste',
        'pos_payments',
        'pos_order_refunds',
        'pos_order_items',
        'pos_orders',
        'pos_day_closing_archives',
        'pos_day_closings',
        'table_reservations',
    ];

    /** @var list<string> */
    private array $inventoryOpTables = [
        'inventory_cost_audit_log',
        'inventory_cost_layers',
        'inventory_transactions',
        'production_logs',
        'store_request_items',
        'store_requests',
        'stock_returns',
        'menu_item_stocks',
    ];

    /** @var list<string> */
    private array $procurementTables = [
        'vendor_payments',
        'grn_attachments',
        'grn_audit_logs',
        'grn_items',
        'grns',
        'purchase_order_items',
        'purchase_orders',
        'procurement_requisition_item_vendors',
        'procurement_requisition_items',
        'procurement_requisitions',
    ];

    /** @var list<string> */
    private array $accountingTables = [
        'journal_lines',
        'journal_entries',
    ];

    /** @var list<string> */
    private array $hotelOpTables = [
        'booking_payments',
        'booking_extra_charges',
        'booking_room_transfers',
        'booking_segments',
        'bookings',
        'booking_groups',
        'housekeeping_job_lines',
        'housekeeping_jobs',
        'daily_room_cleaning_consumptions',
        'daily_room_cleanings',
        'laundry_request_lines',
        'laundry_requests',
        'room_cleaning_release_audits',
        'room_cleaning_releases',
        'room_status_blocks',
    ];

    /** @var list<string> */
    private array $noiseTables = [
        'login_attempts',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
    ];

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force');
            $this->line('Example: php artisan db:wipe-ops --force');
            $this->line('Optional: --with-hotel  --keep-tokens');

            return 1;
        }

        $database = DB::getDatabaseName();

        $this->warn('This will permanently clear operational data.');
        $this->line("Environment: ".app()->environment()." | Database: {$database}");
        $this->line('KEPT: menu items/prices/recipes, inventory item masters, locations,');
        $this->line('      outlets, tables, users, vendors, chart of accounts, settings.');
        $this->line('CLEARED: POS sales, stock quantities, transfers, production,');
        $this->line('         POs/GRN/requisitions, journal entries.');
        if ($this->option('with-hotel')) {
            $this->line('Also clearing hotel ops (bookings / HK / laundry).');
        }

        if (! $this->option('yes')) {
            $typed = (string) $this->ask("Type the database name to confirm ({$database})");
            if ($typed !== $database) {
                $this->error('Database name did not match. Aborted.');

                return 1;
            }
        }

        if ($this->option('backup') && ! $this->dumpDatabase($database)) {
            $this->error('Backup failed. Aborted before wiping.');

            return 1;
        }

        $tables = array_merge(
            $this->posTables,
            $this->inventoryOpTables,
            $this->procurementTables,
            $this->accountingTables,
            $this->noiseTables,
        );

        if ($this->option('with-hotel')) {
            $tables = array_merge($tables, $this->hotelOpTables);
        }

        if (! $this->option('keep-tokens')) {
            $tables[] = 'personal_access_tokens';
            $tables[] = 'sessions';
        }

        $existing = array_values(array_filter(
            $tables,
            fn (string $t) => Schema::hasTable($t),
        ));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($existing as $table) {
                $before = DB::table($table)->count();
                DB::table($table)->truncate();
                $this->line("Truncated {$table} ({$before} rows)");
            }

            if (Schema::hasTable('inventory_item_locations')) {
                $n = DB::table('inventory_item_locations')->where('quantity', '!=', 0)->count();
                DB::table('inventory_item_locations')->update(['quantity' => 0]);
                $this->line("Zeroed inventory_item_locations.quantity ({$n} non-zero rows)");
            }

            if (Schema::hasTable('inventory_items')) {
                $updates = ['current_stock' => 0];
                if (Schema::hasColumn('inventory_items', 'stock_expected')) {
                    $updates['stock_expected'] = 0;
                }
                DB::table('inventory_items')->update($updates);
                $this->line('Zeroed inventory_items.current_stock'
                    .(isset($updates['stock_expected']) ? ' + stock_expected' : ''));
            }

            if (Schema::hasTable('restaurant_tables') && Schema::hasColumn('restaurant_tables', 'status')) {
                $n = DB::table('restaurant_tables')
                    ->where('status', '!=', 'available')
                    ->update(['status' => 'available']);
                $this->line("Reset restaurant_tables.status → available ({$n} rows)");
            }

            if ($this->option('with-hotel')
                && Schema::hasTable('rooms')
                && Schema::hasColumn('rooms', 'status')) {
                // Common vacant labels in this codebase — set to vacant if column exists.
                $n = DB::table('rooms')->update(['status' => 'vacant']);
                $this->line("Reset rooms.status → vacant ({$n} rows)");
            }

            $this->clearOrphanProcurementFiles();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->newLine();
        $this->info('Done. Masters retained. Stock is zero — enter opening stock / GRN before selling.');
        $this->line('Verify: POS menu prices still present; hand qty = 0.');

        return 0;
    }

    private function dumpDatabase(string $database): bool
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}");

            return false;
        }

        $file = $dir.'/pre-wipe-'.$database.'-'.date('Ymd-His').'.sql';
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

    private function clearOrphanProcurementFiles(): void
    {
        $dirs = [
            storage_path('app/public/po_documents'),
            storage_path('app/public/grn_documents'),
            storage_path('app/public/po_invoices'),
            storage_path('app/public/grn_attachments'),
        ];
        $removed = 0;
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $path = $dir.DIRECTORY_SEPARATOR.$f;
                if (is_file($path) && @unlink($path)) {
                    $removed++;
                }
            }
        }
        $this->line("Removed {$removed} orphan PO/GRN upload file(s)");
    }
}
