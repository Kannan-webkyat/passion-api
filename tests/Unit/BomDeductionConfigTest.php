<?php

namespace Tests\Unit;

use App\Services\BomDeductionConfig;
use Tests\TestCase;

class BomDeductionConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        BomDeductionConfig::setModeForTesting(null);
        parent::tearDown();
    }

    public function test_expand_raw_mode_is_recognized(): void
    {
        BomDeductionConfig::setModeForTesting(BomDeductionConfig::MODE_EXPAND_RAW);

        $this->assertTrue(BomDeductionConfig::expandsNested());
    }

    public function test_prep_stock_mode_does_not_expand(): void
    {
        BomDeductionConfig::setModeForTesting(BomDeductionConfig::MODE_PREP_STOCK);

        $this->assertFalse(BomDeductionConfig::expandsNested());
    }

    public function test_public_meta_matches_mode(): void
    {
        BomDeductionConfig::setModeForTesting(BomDeductionConfig::MODE_EXPAND_RAW);
        $meta = BomDeductionConfig::publicMeta();

        $this->assertSame(BomDeductionConfig::MODE_EXPAND_RAW, $meta['bom_deduction_mode']);
        $this->assertTrue($meta['expands_nested']);
    }
}
