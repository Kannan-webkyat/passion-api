<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixMigrationNames extends Command
{
    protected $signature = 'app:fix-migration-names';
    protected $description = 'Fix out-of-order migration names and mark already-existing tables as ran';

    public function handle()
    {
        // These migrations already ran under old names (000001, 000003 etc.)
        // but the tables already exist. Mark them as ran under the new names.
        $alreadyRan = [
            '2026_03_10_070246_create_combos_table'           => 'combos',
            '2026_03_10_070247_drop_sub_category_from_combos' => null,  // it's an alter, combos already doesn't have the column
            '2026_03_10_070248_create_combo_items_table'      => 'combo_items',
            '2026_03_10_070249_add_image_to_menu_items_table' => null,  // column may already exist
        ];

        // Get current max batch number
        $maxBatch = DB::table('migrations')->max('batch') ?? 0;

        foreach ($alreadyRan as $migration => $tableToCheck) {
            $exists = DB::table('migrations')->where('migration', $migration)->exists();
            if ($exists) {
                $this->warn("Already recorded: {$migration}");
                continue;
            }

            // Check if the underlying table already exists (for create migrations)
            if ($tableToCheck && !Schema::hasTable($tableToCheck)) {
                $this->warn("Table '{$tableToCheck}' does not exist yet — skipping {$migration}");
                continue;
            }

            DB::table('migrations')->insert([
                'migration' => $migration,
                'batch'     => $maxBatch + 1,
            ]);
            $this->info("Marked as ran: {$migration}");
        }

        $this->info('Done. Run php artisan migrate to apply any remaining pending migrations.');
    }
}
