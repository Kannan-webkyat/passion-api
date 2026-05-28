<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingExtraCharge;
use App\Models\InventoryItem;
use App\Models\RoomStatusBlock;
use App\Models\Setting;

/**
 * Checkout inspection charge lines (minibar + damaged/missing assets) for folio API and invoice PDF.
 */
final class BookingInspectionChargeLines
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forBooking(Booking $booking): array
    {
        $penalties = self::penaltiesMap();

        $lines = BookingExtraCharge::query()
            ->where('booking_id', $booking->id)
            ->where('source', 'inspection')
            ->orderBy('id')
            ->get(['id', 'kind', 'label', 'qty', 'unit_amount', 'total_amount', 'meta']);

        if ($lines->isEmpty()) {
            return self::snapshotFallbackLines($booking, $penalties);
        }

        return $lines->map(fn($line) => self::enrichLineForDisplay($line, $penalties))->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{minibar: float, asset_penalty: float, total: float}
     */
    public static function totalsByKind(array $lines): array
    {
        $minibar = 0.0;
        $assetPenalty = 0.0;
        foreach ($lines as $line) {
            $kind = strtolower((string) ($line['kind'] ?? ''));
            $total = round((float) ($line['resolved_total_amount'] ?? $line['total_amount'] ?? 0), 2);
            if ($kind === 'minibar') {
                $minibar += $total;
            } elseif ($kind === 'asset_penalty') {
                $assetPenalty += $total;
            }
        }

        return [
            'minibar' => round($minibar, 2),
            'asset_penalty' => round($assetPenalty, 2),
            'total' => round($minibar + $assetPenalty, 2),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function penaltiesMap(): array
    {
        $penaltiesRaw = (string) Setting::get('checkout_inspection_penalties', '{}');
        $penaltiesJson = json_decode($penaltiesRaw, true);

        return is_array($penaltiesJson) ? $penaltiesJson : [];
    }

    /**
     * @param  array<string, mixed>  $penalties
     * @return array<string, mixed>
     */
    public static function enrichLineForDisplay(mixed $line, array $penalties): array
    {
        $row = is_array($line) ? $line : $line->toArray();
        $kind = (string) ($row['kind'] ?? '');
        $qty = max(1.0, (float) ($row['qty'] ?? 1));
        $storedUnit = round((float) ($row['unit_amount'] ?? 0), 2);
        $storedTotal = round((float) ($row['total_amount'] ?? 0), 2);

        $resolvedUnit = $storedUnit;
        $resolvedTotal = $storedTotal;

        if ($kind === 'asset_penalty') {
            $meta = $row['meta'] ?? [];
            $meta = is_array($meta) ? $meta : [];
            $penKey = trim((string) ($meta['penalty_key'] ?? ''));
            $invItemId = (int) ($meta['inventory_item_id'] ?? 0);
            if ($resolvedUnit < 0.0001 && isset($meta['unit_damage_charge']) && is_numeric($meta['unit_damage_charge'])) {
                $resolvedUnit = round(max(0.0, (float) $meta['unit_damage_charge']), 2);
                $resolvedTotal = round($resolvedUnit * $qty, 2);
            } elseif ($resolvedUnit < 0.0001 && $invItemId > 0) {
                [$resolvedUnit] = CheckoutInspectionPenaltyAmount::resolveForAsset($invItemId, $penKey, $penalties);
                $resolvedTotal = round($resolvedUnit * $qty, 2);
            } elseif ($resolvedUnit < 0.0001 && $penKey !== '') {
                [$resolvedUnit] = CheckoutInspectionPenaltyAmount::resolve($penalties, $penKey);
                $resolvedTotal = round($resolvedUnit * $qty, 2);
            }
        } elseif ($kind === 'minibar') {
            $resolvedUnit = $storedUnit;
            $resolvedTotal = $storedTotal > 0.0001 ? $storedTotal : round($storedUnit * $qty, 2);
        }

        $row['resolved_unit_amount'] = $resolvedUnit;
        $row['resolved_total_amount'] = $resolvedTotal;

        return $row;
    }

    /**
     * @param  array<string, array<string, mixed>>  $penalties
     * @return array<int, array<string, mixed>>
     */
    public static function snapshotFallbackLines(Booking $booking, array $penalties = []): array
    {
        $roomIds = array_values(array_unique(array_filter(array_merge(
            [(int) $booking->room_id],
            $booking->segments()->pluck('room_id')->map(fn($id) => (int) $id)->all()
        ), static fn(int $id): bool => $id > 0)));

        if ($roomIds === []) {
            return [];
        }

        $blocks = RoomStatusBlock::query()
            ->whereIn('room_id', $roomIds, 'and', false)
            ->where('status', '=', 'inspected')
            ->whereNotNull('inspection_snapshot')
            ->orderByDesc('id')
            ->get();

        $block = null;
        foreach ($blocks as $candidate) {
            $snap = $candidate->inspection_snapshot;
            if (! is_array($snap) || ! empty($snap['cleared'])) {
                continue;
            }
            $snapBookingId = isset($snap['booking_id']) ? (int) $snap['booking_id'] : null;
            if ($snapBookingId !== null && $snapBookingId === (int) $booking->id) {
                $block = $candidate;
                break;
            }
        }

        if (! $block || ! is_array($block->inspection_snapshot)) {
            return [];
        }

        $snap = $block->inspection_snapshot;
        if (! empty($snap['cleared'])) {
            return [];
        }

        $out = [];
        $nid = -1;

        foreach (($snap['assets'] ?? []) as $a) {
            if (! is_array($a)) {
                continue;
            }
            $status = strtolower((string) ($a['status'] ?? ''));
            if (! in_array($status, ['missing', 'damaged'], true)) {
                continue;
            }
            $key = (string) ($a['key'] ?? '');
            $label = trim((string) ($a['label'] ?? '')) ?: ($key !== '' ? $key : 'Asset');
            $penKey = isset($a['penalty_key']) ? trim((string) $a['penalty_key']) : '';
            $lineQty = isset($a['qty']) ? max(1, (int) $a['qty']) : 1;
            $invItemId = isset($a['inventory_item_id']) ? (int) $a['inventory_item_id'] : null;
            if (str_starts_with($key, 'inv_')) {
                $fromKey = (int) substr($key, 4);
                if ($fromKey > 0) {
                    $invItemId = $invItemId ?: $fromKey;
                }
            }
            if (isset($a['unit_damage_charge']) && is_numeric($a['unit_damage_charge'])) {
                $unit = round(max(0.0, (float) $a['unit_damage_charge']), 2);
            } else {
                [$unit] = CheckoutInspectionPenaltyAmount::resolveForAsset($invItemId, $penKey, $penalties);
            }
            $total = round($unit * $lineQty, 2);

            $itemCost = isset($a['item_cost']) && is_numeric($a['item_cost'])
                ? round(max(0.0, (float) $a['item_cost']), 2)
                : 0.0;
            $additionalPenalty = isset($a['inspection_penalty_charge']) && is_numeric($a['inspection_penalty_charge'])
                ? round(max(0.0, (float) $a['inspection_penalty_charge']), 2)
                : 0.0;

            $out[] = self::enrichLineForDisplay([
                'id' => $nid--,
                'kind' => 'asset_penalty',
                'label' => $label,
                'qty' => $lineQty,
                'unit_amount' => $unit,
                'total_amount' => $total,
                'meta' => [
                    'asset_key' => $key,
                    'inventory_item_id' => $invItemId ?: null,
                    'asset_status' => $status,
                    'penalty_key' => $penKey !== '' ? $penKey : null,
                    'item_cost' => $itemCost,
                    'inspection_penalty_charge' => $additionalPenalty,
                    'unit_damage_charge' => $unit,
                    'from_inspection_snapshot' => true,
                ],
            ], $penalties);
        }

        foreach (($snap['minibar'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $itemId = (int) ($row['inventory_item_id'] ?? 0);
            $qty = (float) ($row['qty'] ?? 0);
            if ($qty <= 0 || $itemId <= 0) {
                continue;
            }
            /** @var InventoryItem|null $item */
            $item = InventoryItem::query()->find($itemId, ['id', 'name', 'sku', 'cost_price', 'conversion_factor']);
            if (! $item) {
                continue;
            }
            $conv = max(1.0, (float) ($item->conversion_factor ?: 1));
            $unitCost = round((float) ($item->cost_price ?? 0) / $conv, 2);
            $lineTotal = round($unitCost * $qty, 2);
            $out[] = self::enrichLineForDisplay([
                'id' => $nid--,
                'kind' => 'minibar',
                'label' => (string) ($row['name'] ?? $item->name ?? 'Minibar'),
                'qty' => $qty,
                'unit_amount' => $unitCost,
                'total_amount' => $lineTotal,
                'meta' => [
                    'inventory_item_id' => $itemId,
                    'from_inspection_snapshot' => true,
                ],
            ], $penalties);
        }

        return $out;
    }
}
