<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Master switch: when off, food is not blocked by recipes, kitchen production, or MTO ingredient checks.
 * Direct-sale inventory links (e.g. liquor bottles) still enforce shelf stock.
 */
final class BomEnforcementConfig
{
    public const SETTING_KEY = 'bom_stock_enforcement';

    private static ?bool $testOverride = null;

    public static function isEnabled(): bool
    {
        if (self::$testOverride !== null) {
            return self::$testOverride;
        }

        $raw = Setting::get(self::SETTING_KEY, '1');

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::set(self::SETTING_KEY, $enabled ? '1' : '0');
        self::$testOverride = null;
    }

    /** @internal For unit tests only. */
    public static function setEnabledForTesting(?bool $enabled): void
    {
        self::$testOverride = $enabled;
    }

    /** @return array<string, mixed> */
    public static function publicMeta(): array
    {
        $enabled = self::isEnabled();

        return [
            'bom_stock_enforcement' => $enabled,
            'enforcement_label' => $enabled ? 'Recipe stock enforcement on' : 'Recipe stock enforcement off',
            'enforcement_description' => $enabled
                ? 'Food can be blocked by recipes, kitchen production, and ingredient checks on POS/KOT.'
                : 'Food sells on menu + price only. Liquor and other direct-sale inventory links still check bar stock.',
        ];
    }
}
