<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HousekeepingChecklistItem extends Model
{
    public const CATEGORY_DAILY_ROOM_CLEANING = 'daily_room_cleaning';

    public const CATEGORY_CHECKOUT_INSPECTION = 'checkout_inspection';

    public const CATEGORY_DEEP_CLEANING = 'deep_cleaning';

    public const CATEGORY_VIP_ROOM_SETUP = 'vip_room_setup';

    public const CATEGORY_TURNOVER_CLEANING = 'turnover_cleaning';

    public const CATEGORY_CUSTOM = 'custom';

    /** @return array<string, string> */
    public static function categoryLabels(): array
    {
        return [
            self::CATEGORY_DAILY_ROOM_CLEANING => 'Daily Room Cleaning',
            self::CATEGORY_CHECKOUT_INSPECTION => 'Checkout Inspection',
            self::CATEGORY_DEEP_CLEANING => 'Deep Cleaning',
            self::CATEGORY_VIP_ROOM_SETUP => 'VIP Room Setup',
            self::CATEGORY_TURNOVER_CLEANING => 'Turnover Cleaning',
            self::CATEGORY_CUSTOM => 'Custom',
        ];
    }

    protected $fillable = [
        'task_key',
        'task_name',
        'category',
        'section',
        'display_order',
        'required',
        'is_active',
        'estimated_minutes',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'required' => 'boolean',
        'is_active' => 'boolean',
        'estimated_minutes' => 'integer',
    ];
}
