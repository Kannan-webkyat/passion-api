<?php

namespace App\Services\Accounting;

use App\Models\GRN;
use App\Models\JournalEntry;
use App\Services\InventoryCostingConfig;

final class AccountCodes
{
    public const ROOM_REVENUE = '4100';

    public const CASH = '1110';

    public const BANK_CARD = '1120';

    public const BANK_UPI = '1121';

    public const FOLIO_AR = '1130';

    public const INVENTORY_FOOD = '1310';

    public const INVENTORY_LIQUOR = '1311';

    public const DEFERRED_PROCUREMENT = '1360';

    public const INPUT_GST = '1420';

    public const INPUT_VAT = '1421';

    public const GRNI = '2110';

    public const AP_VENDORS = '2120';

    public const OUTPUT_CGST = '2210';

    public const OUTPUT_SGST = '2211';

    public const OUTPUT_IGST = '2212';

    public const OUTPUT_VAT = '2213';

    public const TIPS_PAYABLE = '2310';

    public const RESTAURANT_SALES = '4210';

    public const BAR_SALES = '4220';

    public const SERVICE_CHARGE = '4300';

    public const DELIVERY_CHARGE = '4310';

    public const SALES_DISCOUNTS = '4900';

    public const COGS_RESTAURANT = '5100';

    public const COGS_BAR = '5110';

    public const EXP_WASTAGE = '5200';

    public const EXP_STAFF_MEALS = '5210';

    public const GENERAL_EXPENSE = '6100';

    public const OPENING_BALANCE_EQUITY = '3900';

    public static function tenderAccount(string $method): string
    {
        $m = strtolower(trim($method));

        return match ($m) {
            'cash' => self::CASH,
            'upi' => self::BANK_UPI,
            'card' => self::BANK_CARD,
            'room_charge' => self::FOLIO_AR,
            default => str_contains($m, 'upi') ? self::BANK_UPI
                : (str_contains($m, 'cash') ? self::CASH : self::BANK_CARD),
        };
    }
}
