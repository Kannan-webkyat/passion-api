<?php

namespace Tests\Unit;

use App\Services\MenuPerformanceSummarizer;
use Tests\TestCase;

class MenuPerformanceSummarizerTest extends TestCase
{
    public function test_summarizes_spirit_variants_to_bottles_and_pegs(): void
    {
        $rows = collect([
            (object) [
                'row_kind' => 'menu_item',
                'menu_item_id' => 1,
                'name' => 'MCR',
                'variant_label' => '30ml',
                'variant_ml' => 30,
                'conversion_factor' => 750,
                'category_name' => 'Whisky',
                'qty_sold' => 2,
                'revenue' => 600,
                'lines_sold' => 2,
                'bills_count' => 2,
                'is_liquor' => 1,
            ],
            (object) [
                'row_kind' => 'menu_item',
                'menu_item_id' => 1,
                'name' => 'MCR',
                'variant_label' => '60ml',
                'variant_ml' => 60,
                'conversion_factor' => 750,
                'category_name' => 'Whisky',
                'qty_sold' => 1,
                'revenue' => 400,
                'lines_sold' => 1,
                'bills_count' => 1,
                'is_liquor' => 1,
            ],
            (object) [
                'row_kind' => 'menu_item',
                'menu_item_id' => 1,
                'name' => 'MCR',
                'variant_label' => 'Full bottle',
                'variant_ml' => 1,
                'conversion_factor' => 750,
                'category_name' => 'Whisky',
                'qty_sold' => 10,
                'revenue' => 10000,
                'lines_sold' => 10,
                'bills_count' => 8,
                'is_liquor' => 1,
            ],
        ]);

        $out = app(MenuPerformanceSummarizer::class)->summarize($rows);
        $this->assertCount(1, $out);
        $row = $out->first();
        $this->assertSame('MCR', $row->name);
        $this->assertEquals(10.0, $row->bottles_sold);
        $this->assertEquals(2.0, $row->pegs_sold); // 60+60 ml = 120ml = 2 pegs
        $this->assertSame('10 btl 2 peg', $row->qty_display);
    }

    public function test_peg_only_sales_roll_into_bottles_from_total_ml(): void
    {
        $rows = collect([
            (object) [
                'row_kind' => 'menu_item',
                'menu_item_id' => 2,
                'name' => 'MCR',
                'variant_label' => '60ml',
                'variant_ml' => 60,
                'conversion_factor' => 750,
                'category_name' => 'Whisky',
                'qty_sold' => 15,
                'revenue' => 6000,
                'lines_sold' => 15,
                'bills_count' => 12,
                'is_liquor' => 1,
            ],
        ]);

        $out = app(MenuPerformanceSummarizer::class)->summarize($rows);
        $row = $out->first();
        $this->assertEquals(1.0, $row->bottles_sold); // 900ml = 1 bottle + 150ml
        $this->assertEquals(2.5, $row->pegs_sold);
        $this->assertSame('1 btl 2.5 peg', $row->qty_display);
    }

    public function test_whole_bottle_liquor_without_variants_shows_bottles(): void
    {
        $rows = collect([
            (object) [
                'row_kind' => 'menu_item',
                'menu_item_id' => 3,
                'name' => 'KINGFISHER 650ML',
                'variant_label' => '',
                'variant_ml' => 0,
                'conversion_factor' => 650,
                'category_name' => 'Beer',
                'qty_sold' => 8,
                'revenue' => 2400,
                'lines_sold' => 8,
                'bills_count' => 6,
                'is_liquor' => 1,
            ],
        ]);

        $out = app(MenuPerformanceSummarizer::class)->summarize($rows);
        $row = $out->first();
        $this->assertTrue($row->is_summarized);
        $this->assertEquals(8.0, $row->bottles_sold);
        $this->assertSame('8 btl', $row->qty_display);
    }
}
