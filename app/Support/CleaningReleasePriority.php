<?php

namespace App\Support;

use InvalidArgumentException;

final class CleaningReleasePriority
{
    public const NORMAL = 'normal';

    public const URGENT = 'urgent';

    public const VIP = 'vip';

    public const DEEP_CLEAN = 'deep_clean';

    /** @return array<int, string> */
    public static function values(): array
    {
        return [
            self::NORMAL,
            self::URGENT,
            self::VIP,
            self::DEEP_CLEAN,
        ];
    }

    public static function validate(?string $priority, string $default = self::NORMAL): string
    {
        $value = $priority ?? $default;

        if (! in_array($value, self::values(), true)) {
            throw new InvalidArgumentException(
                'Priority must be one of: '.implode(', ', self::values()).'.'
            );
        }

        return $value;
    }
}
