<?php

namespace Tests\Unit;

use App\Exceptions\LiquorTaxValidationException;
use App\Models\InventoryItem;
use App\Services\LiquorTaxValidator;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LiquorTaxValidatorTest extends TestCase
{
    public function test_alcohol_item_requires_vat_tax_type(): void
    {
        $item = new InventoryItem(['name' => 'Whisky']);
        $item->is_alcohol = true;

        $this->expectException(LiquorTaxValidationException::class);
        $this->expectExceptionMessage(LiquorTaxValidator::MSG_LIQUOR_REQUIRES_VAT);

        LiquorTaxValidator::validateLine($item, 'gst');
    }

    public function test_alcohol_item_allows_vat_tax_type(): void
    {
        $item = new InventoryItem(['name' => 'Whisky']);
        $item->is_alcohol = true;

        LiquorTaxValidator::validateLine($item, 'vat');

        $this->assertTrue(true);
    }

    public function test_non_alcohol_item_rejects_vat_tax_type(): void
    {
        $item = new InventoryItem(['name' => 'Rice']);
        $item->is_alcohol = false;

        $this->expectException(LiquorTaxValidationException::class);
        $this->expectExceptionMessage(LiquorTaxValidator::MSG_NON_LIQUOR_NO_VAT);

        LiquorTaxValidator::validateLine($item, 'vat');
    }

    public function test_non_alcohol_item_allows_gst_tax_type(): void
    {
        $item = new InventoryItem(['name' => 'Rice']);
        $item->is_alcohol = false;

        LiquorTaxValidator::validateLine($item, 'gst');

        $this->assertTrue(true);
    }

    public function test_validate_po_lines_checks_each_line(): void
    {
        $alcohol = new InventoryItem(['name' => 'Vodka']);
        $alcohol->id = 1;
        $alcohol->is_alcohol = true;
        $food = new InventoryItem(['name' => 'Sugar']);
        $food->id = 2;
        $food->is_alcohol = false;

        $inventory = new Collection([1 => $alcohol, 2 => $food]);

        $this->expectException(LiquorTaxValidationException::class);

        LiquorTaxValidator::validatePoLines([
            ['inventory_item_id' => 1, 'tax_type' => 'gst'],
            ['inventory_item_id' => 2, 'tax_type' => 'gst'],
        ], $inventory);
    }

    public function test_item_master_alcohol_requires_vat_tax_type(): void
    {
        $this->expectException(LiquorTaxValidationException::class);

        LiquorTaxValidator::validateItemMasterTax(true, 'local', 'Whisky');
    }

    public function test_item_master_non_alcohol_rejects_vat_tax_type(): void
    {
        $this->expectException(LiquorTaxValidationException::class);

        LiquorTaxValidator::validateItemMasterTax(false, 'vat', 'Rice');
    }

    public function test_item_master_alcohol_allows_vat(): void
    {
        LiquorTaxValidator::validateItemMasterTax(true, 'vat', 'Whisky');

        $this->assertTrue(true);
    }
}
