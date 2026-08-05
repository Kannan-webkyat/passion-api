<?php

namespace App\Support;

use InvalidArgumentException;

final class CleaningServiceClassification
{
    public const TYPE_DAILY = 'daily';

    public const TYPE_OTHER = 'other';

    public const SUBTYPE_REQUESTED = 'requested';

    public const SUBTYPE_COMPLAINT = 'complaint';

    public const SUBTYPE_RERELEASE = 'rerelease';

    /** @return array<int, string> */
    public static function types(): array
    {
        return [self::TYPE_DAILY, self::TYPE_OTHER];
    }

    /** @return array<int, string> */
    public static function otherSubtypes(): array
    {
        return [
            self::SUBTYPE_REQUESTED,
            self::SUBTYPE_COMPLAINT,
            self::SUBTYPE_RERELEASE,
        ];
    }

    public static function validateType(?string $type, string $default = self::TYPE_DAILY): string
    {
        $value = $type ?? $default;
        if (! in_array($value, self::types(), true)) {
            throw new InvalidArgumentException(
                'Service type must be one of: '.implode(', ', self::types()).'.'
            );
        }

        return $value;
    }

    public static function validateOtherSubtype(?string $subtype, string $default = self::SUBTYPE_REQUESTED): string
    {
        $value = $subtype ?? $default;
        if (! in_array($value, self::otherSubtypes(), true)) {
            throw new InvalidArgumentException(
                'Service subtype must be one of: '.implode(', ', self::otherSubtypes()).'.'
            );
        }

        return $value;
    }

    public static function label(string $type, ?string $subtype = null): string
    {
        if ($type === self::TYPE_DAILY) {
            return 'Daily Cleaning';
        }

        return match ($subtype) {
            self::SUBTYPE_COMPLAINT => 'Complaint / re-service',
            self::SUBTYPE_RERELEASE => 'Requested re-service',
            default => 'Requested cleaning',
        };
    }
}
