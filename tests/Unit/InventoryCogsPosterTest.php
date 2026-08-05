<?php

namespace Tests\Unit;

use App\Models\InventoryTransaction;
use App\Services\Accounting\InventoryCogsPoster;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryCogsPosterTest extends TestCase
{
    public function test_post_returns_null_for_non_pos_out_transaction(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            $this->markTestSkipped('Accounting migrations not run.');
        }

        $poster = app(InventoryCogsPoster::class);

        $tx = new InventoryTransaction([
            'type' => 'in',
            'reason' => 'GRN Receipt',
            'total_cost' => 100,
        ]);

        $this->assertNull($poster->post($tx));
        $this->assertNull($poster->postReversal($tx));
    }

    public function test_post_reversal_returns_null_for_non_reversal_transaction(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            $this->markTestSkipped('Accounting migrations not run.');
        }

        $poster = app(InventoryCogsPoster::class);

        $tx = new InventoryTransaction([
            'type' => 'out',
            'reason' => 'POS Order',
            'total_cost' => 50,
        ]);

        $this->assertNull($poster->postReversal($tx));
    }
}
