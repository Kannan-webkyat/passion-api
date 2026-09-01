<?php

namespace Tests\Unit;

use App\Services\BomEnforcementConfig;
use Tests\TestCase;

class BomEnforcementConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        BomEnforcementConfig::setEnabledForTesting(null);
        parent::tearDown();
    }

    public function test_defaults_to_enabled(): void
    {
        BomEnforcementConfig::setEnabledForTesting(true);
        $this->assertTrue(BomEnforcementConfig::isEnabled());
    }

    public function test_public_meta_when_disabled(): void
    {
        BomEnforcementConfig::setEnabledForTesting(false);
        $meta = BomEnforcementConfig::publicMeta();
        $this->assertFalse($meta['bom_stock_enforcement']);
        $this->assertStringContainsString('off', strtolower($meta['enforcement_label']));
    }
}
