<?php

namespace Tests\Unit\Support;

use App\Support\CleaningReleasePriority;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CleaningReleasePriorityTest extends TestCase
{
    public function test_values_returns_all_supported_priorities(): void
    {
        $this->assertSame(
            ['normal', 'urgent', 'vip', 'deep_clean'],
            CleaningReleasePriority::values(),
        );
    }

    #[DataProvider('validPriorityProvider')]
    public function test_validate_accepts_supported_priorities(?string $input, string $expected): void
    {
        $this->assertSame($expected, CleaningReleasePriority::validate($input));
    }

    public static function validPriorityProvider(): array
    {
        return [
            'null defaults to normal' => [null, CleaningReleasePriority::NORMAL],
            'normal' => ['normal', CleaningReleasePriority::NORMAL],
            'urgent' => ['urgent', CleaningReleasePriority::URGENT],
            'vip' => ['vip', CleaningReleasePriority::VIP],
            'deep clean' => ['deep_clean', CleaningReleasePriority::DEEP_CLEAN],
        ];
    }

    #[DataProvider('invalidPriorityProvider')]
    public function test_validate_rejects_out_of_range_values(?string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be one of:');

        CleaningReleasePriority::validate($input);
    }

    public static function invalidPriorityProvider(): array
    {
        return [
            'numeric string' => ['3'],
            'empty string' => [''],
            'unknown label' => ['high'],
            'legacy numeric scale' => ['5'],
        ];
    }
}
