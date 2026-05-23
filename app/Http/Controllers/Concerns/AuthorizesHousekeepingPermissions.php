<?php

namespace App\Http\Controllers\Concerns;

trait AuthorizesHousekeepingPermissions
{
    public const HK_DIRTY = 'housekeeping-dirty-rooms';

    public const HK_CHECKOUT = 'housekeeping-checkout-inspection';

    public const HK_CLEANING = 'housekeeping-cleaning-tasks';

    public const HK_DAILY = 'housekeeping-daily-room-cleaning';

    public const HK_CLEAN = 'housekeeping-clean-rooms';

    public const HK_LAUNDRY = 'housekeeping-laundry';

    /** @return array<int, string> */
    private static function hkLegacyView(): array
    {
        return ['housekeeping-view', 'view-rooms', 'manage-rooms'];
    }

    /** @return array<int, string> */
    private static function hkLegacyOperate(): array
    {
        return ['housekeeping-operate', 'manage-rooms'];
    }

    /**
     * @param  array<int, string>  $section
     */
    protected function allowHousekeepingViewSection(array $section): void
    {
        $this->authorizePermissions(array_merge($section, self::hkLegacyView()));
    }

    protected function allowHousekeepingNav(): void
    {
        $this->authorizePermissions(array_merge([
            self::HK_DIRTY,
            self::HK_CHECKOUT,
            self::HK_CLEANING,
            self::HK_DAILY,
            self::HK_CLEAN,
            self::HK_LAUNDRY,
        ], self::hkLegacyView()));
    }

    protected function allowHousekeepingOperate(): void
    {
        $this->authorizePermissions(self::hkLegacyOperate());
    }

    protected function allowHousekeepingLaundryView(): void
    {
        $this->allowHousekeepingViewSection([self::HK_LAUNDRY]);
    }

    protected function allowHousekeepingLaundryOperate(): void
    {
        $this->authorizePermissions(array_merge([self::HK_LAUNDRY], self::hkLegacyOperate()));
    }
}
