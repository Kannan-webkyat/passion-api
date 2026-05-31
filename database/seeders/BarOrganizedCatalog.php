<?php

namespace Database\Seeders;

use Illuminate\Support\Str;

/** Shared SKU / menu-code helpers for bar inventory + menu seeders. */
final class BarOrganizedCatalog
{
    /** @var array<string, string> */
    public const CAT_CODES = [
        'Brandy' => 'BRD',
        'Whisky' => 'WSK',
        'Rum' => 'RUM',
        'Vodka' => 'VDK',
        'Wine' => 'WIN',
        'Gin' => 'GIN',
        'Beer' => 'BER',
    ];

    public static function inventorySku(string $category, string $item, int $size): string
    {
        $code = self::CAT_CODES[$category] ?? 'BAR';
        $slug = Str::upper(Str::slug(Str::limit($item, 24, ''), '_'));
        $slug = $slug !== '' ? $slug : 'ITEM';

        return "BAR-{$code}-{$slug}-{$size}";
    }

    public static function menuItemCode(string $category, string $item, int $size): string
    {
        return 'MNU-'.substr(self::inventorySku($category, $item, $size), 4);
    }
}
