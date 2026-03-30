<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;

class MenuItemSyncService
{
    /**
     * Sync variants. When `price` is omitted, the existing variant base price is kept.
     * When `restaurant_prices` is omitted, per-outlet variant prices are left unchanged;
     * new outlet–variant pairs get the variant base price.
     */
    public function syncVariants(MenuItem $menuItem, ?array $variants): void
    {
        if ($variants === null) {
            return;
        }

        $incoming = collect($variants)->filter(function ($row) {
            return is_array($row) && trim($row['size_label'] ?? '') !== '';
        });
        $incomingIds = $incoming->map(fn ($row) => is_array($row) ? ($row['id'] ?? null) : null)->filter()->values()->toArray();

        $menuItem->variants()->whereNotIn('id', $incomingIds)->delete();

        $menuItem->loadMissing('restaurantMenuItems');

        foreach ($incoming as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $existingVariant = ! empty($row['id'])
                ? MenuItemVariant::where('menu_item_id', $menuItem->id)->where('id', $row['id'])->first()
                : null;

            $basePrice = array_key_exists('price', $row)
                ? (float) $row['price']
                : (float) ($existingVariant->price ?? 0);

            $mlQuantity = array_key_exists('ml_quantity', $row)
                ? (isset($row['ml_quantity']) && $row['ml_quantity'] !== '' ? (float) $row['ml_quantity'] : null)
                : $existingVariant?->ml_quantity;

            $v = MenuItemVariant::updateOrCreate(
                ['id' => $row['id'] ?? null, 'menu_item_id' => $menuItem->id],
                [
                    'size_label' => $row['size_label'],
                    'price' => $basePrice,
                    'ml_quantity' => $mlQuantity,
                    'sort_order' => $i,
                ]
            );

            $hasRestaurantPrices = array_key_exists('restaurant_prices', $row);

            foreach ($menuItem->restaurantMenuItems as $rmi) {
                if ($hasRestaurantPrices) {
                    $price = $basePrice;
                    foreach ($row['restaurant_prices'] ?? [] as $rp) {
                        if (is_array($rp) && (int) ($rp['restaurant_master_id'] ?? 0) === (int) $rmi->restaurant_master_id) {
                            $price = (float) ($rp['price'] ?? $basePrice);
                            break;
                        }
                    }
                } else {
                    $pivot = RestaurantMenuItemVariant::where('restaurant_menu_item_id', $rmi->id)
                        ->where('menu_item_variant_id', $v->id)
                        ->first();
                    if ($pivot) {
                        continue;
                    }
                    $price = $basePrice;
                }

                RestaurantMenuItemVariant::updateOrCreate(
                    [
                        'restaurant_menu_item_id' => $rmi->id,
                        'menu_item_variant_id' => $v->id,
                    ],
                    ['price' => $price]
                );
            }
        }
    }

    /**
     * Sync outlet links. When `price` is omitted, the existing outlet row price is kept (Menu Configuration);
     * new links default to 0 until set in Menu Pricing.
     */
    public function syncRestaurantLinks(MenuItem $menuItem, ?array $links): void
    {
        if ($links === null) {
            return;
        }

        $incoming = collect($links)->filter(fn ($row) => is_array($row) && (int) ($row['restaurant_master_id'] ?? 0) > 0);
        $restaurantIds = $incoming->map(fn ($row) => is_array($row) ? ($row['restaurant_master_id'] ?? null) : null)->filter()->values()->toArray();

        $menuItem->restaurantMenuItems()->whereNotIn('restaurant_master_id', $restaurantIds)->delete();

        foreach ($incoming as $row) {
            if (! is_array($row)) {
                continue;
            }

            $existing = RestaurantMenuItem::where('menu_item_id', $menuItem->id)
                ->where('restaurant_master_id', $row['restaurant_master_id'])
                ->first();

            $price = array_key_exists('price', $row)
                ? (float) $row['price']
                : (float) ($existing->price ?? 0);

            $data = [
                'price' => $price,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];

            $data['price_tax_inclusive'] = true;

            // Only touch EPT when the client sends `fixed_ept`; otherwise preserve (Menu Pricing PUT
            // often sends price-only payloads from older clients — must not wipe config-set EPT).
            if (array_key_exists('fixed_ept', $row)) {
                $fe = $row['fixed_ept'];
                $data['fixed_ept'] = ($fe !== null && $fe !== '')
                    ? (int) $fe
                    : null;
            } else {
                $data['fixed_ept'] = $existing?->fixed_ept;
            }

            RestaurantMenuItem::updateOrCreate(
                [
                    'menu_item_id' => $menuItem->id,
                    'restaurant_master_id' => $row['restaurant_master_id'],
                ],
                $data
            );
        }
    }
}
