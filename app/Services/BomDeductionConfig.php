<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Controls whether MTO / POS deduction uses prep-item stock or expands nested BOM to raw ingredients.
 */
final class BomDeductionConfig
{
    public const SETTING_KEY = 'bom_deduction_mode';

    /** Deduct semi-finished prep stock at sale (default legacy behaviour). */
    public const MODE_PREP_STOCK = 'prep_stock';

    /** Expand semi-finished ingredients to their raw BOM at sale. */
    public const MODE_EXPAND_RAW = 'expand_raw';

    private static ?string $testModeOverride = null;

    public static function mode(): string
    {
        if (self::$testModeOverride !== null) {
            return self::$testModeOverride;
        }

        $raw = strtolower(trim((string) Setting::get(self::SETTING_KEY, self::MODE_PREP_STOCK)));

        return $raw === self::MODE_PREP_STOCK ? self::MODE_PREP_STOCK : self::MODE_EXPAND_RAW;
    }

    public static function expandsNested(): bool
    {
        return self::mode() === self::MODE_EXPAND_RAW;
    }

    public static function setMode(string $mode): void
    {
        $normalized = strtolower(trim($mode)) === self::MODE_PREP_STOCK
            ? self::MODE_PREP_STOCK
            : self::MODE_EXPAND_RAW;

        Setting::set(self::SETTING_KEY, $normalized);
        self::$testModeOverride = null;
    }

    /** @internal For unit tests only. */
    public static function setModeForTesting(?string $mode): void
    {
        self::$testModeOverride = $mode === null
            ? null
            : (strtolower(trim($mode)) === self::MODE_PREP_STOCK
                ? self::MODE_PREP_STOCK
                : self::MODE_EXPAND_RAW);
    }

    /** @return array<string, mixed> */
    public static function publicMeta(): array
    {
        $mode = self::mode();
        $expands = $mode === self::MODE_EXPAND_RAW;

        return [
            'bom_deduction_mode' => $mode,
            'label' => $expands ? 'Expand nested BOM' : 'Deduct prep stock',
            'description' => $expands
                ? 'When a recipe uses a prep item (e.g. house syrup), POS deducts the underlying raw ingredients instead of prep stock. Do not batch-produce those prep items in Kitchen — use prep_stock mode if you run kitchen prep batches.'
                : 'When a recipe uses a prep item, POS deducts prep stock. Raw materials are consumed during Kitchen batch production (recommended for hotels).',
            'expands_nested' => $expands,
        ];
    }
}
