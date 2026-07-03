<?php

namespace Tests\Unit;

use App\Services\DayClosingService;
use PHPUnit\Framework\TestCase;

class DayClosingServiceTest extends TestCase
{
    private function serviceWithLastClosed(?string $lastClosed): DayClosingService
    {
        return new class($lastClosed) extends DayClosingService {
            public function __construct(private ?string $lastClosed) {}

            public function lastClosedDate(int $restaurantId): ?string
            {
                return $this->lastClosed;
            }
        };
    }

    public function test_sequential_close_allows_first_business_day(): void
    {
        $service = $this->serviceWithLastClosed(null);
        $status = $service->sequentialCloseStatus(1, '2026-07-01');

        $this->assertTrue($status['ok']);
    }

    public function test_sequential_close_blocks_skipping_a_day(): void
    {
        $service = $this->serviceWithLastClosed('2026-06-30');
        $status = $service->sequentialCloseStatus(1, '2026-07-02');

        $this->assertFalse($status['ok']);
        $this->assertSame('2026-07-01', $status['required_next']);
    }

    public function test_sequential_close_allows_next_day_after_last_closed(): void
    {
        $service = $this->serviceWithLastClosed('2026-06-30');
        $status = $service->sequentialCloseStatus(1, '2026-07-01');

        $this->assertTrue($status['ok']);
    }
}
