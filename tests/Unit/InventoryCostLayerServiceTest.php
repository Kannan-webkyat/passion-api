<?php

namespace Tests\Unit;

use App\Services\InventoryCostLayerService;
use Tests\TestCase;

class InventoryCostLayerServiceTest extends TestCase
{
    public function test_empty_consume_result_shape(): void
    {
        $service = app(InventoryCostLayerService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('emptyConsumeResult');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($service, 5.0);

        $this->assertSame(5.0, $result['quantity_requested']);
        $this->assertSame(0.0, $result['quantity_from_layers']);
        $this->assertSame(5.0, $result['quantity_unlayered']);
        $this->assertSame([], $result['slices']);
    }
}
