<?php

namespace App\Http\Controllers\Concerns;

trait AuthorizesHousekeepingPermissions
{
    public const HK_DIRTY = 'housekeeping-dirty-rooms';

    public const HK_CHECKOUT = 'housekeeping-checkout-inspection';

    public const HK_CLEANING = 'housekeeping-cleaning-tasks';

    public const HK_DAILY = 'housekeeping-daily-room-cleaning';

    public const HK_CLEAN = 'housekeeping-clean-rooms';

    public const HK_SUPERVISOR_INSPECTION = 'housekeeping-supervisor-inspection';

    public const HK_LAUNDRY = 'housekeeping-laundry';

    public const HK_ROOM_STOCK = 'housekeeping-room-stock';

    public const HK_CLEANING_AVAILABILITY = 'housekeeping-cleaning-availability';

    /** @return array<int, string> */
    private static function granularHousekeepingMenuPermissions(): array
    {
        return [
            self::HK_DIRTY,
            self::HK_CHECKOUT,
            self::HK_CLEANING,
            self::HK_DAILY,
            self::HK_CLEAN,
            self::HK_LAUNDRY,
            self::HK_ROOM_STOCK,
        ];
    }

    /**
     * @param  array<int, string>  $section
     */
    protected function allowHousekeepingViewSection(array $section): void
    {
        $this->authorizePermissions($section);
    }

    protected function allowHousekeepingNav(): void
    {
        $this->authorizePermissions(self::granularHousekeepingMenuPermissions());
    }

    /**
     * @param  array<int, string>  $section
     */
    protected function allowHousekeepingOperate(array $section): void
    {
        $this->allowHousekeepingViewSection($section);
    }

    protected function allowHousekeepingLaundryView(): void
    {
        $this->allowHousekeepingViewSection([self::HK_LAUNDRY]);
    }

    protected function allowHousekeepingLaundryOperate(): void
    {
        $this->authorizePermissions([self::HK_LAUNDRY]);
    }
}
