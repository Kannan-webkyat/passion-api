<?php

namespace App\Support;

use App\Models\AiosellIntegration;
use App\Models\AiosellRatePlanMap;
use App\Models\AiosellRoomMap;
use App\Models\RatePlan;
use App\Models\RoomType;

final class AiosellMapping
{
    /**
     * @return array{ok: bool, message: string}
     */
    public static function pull(): array
    {
        $result = AiosellClient::propertyDetails();
        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message'] ?: 'Could not load AioSell property details.'];
        }

        $body = $result['json'];
        $rooms = $body['rooms'] ?? [];
        if (! is_array($rooms)) {
            return ['ok' => false, 'message' => 'AioSell property details did not include rooms.'];
        }

        $passionTypes = RoomType::query()->with('ratePlans')->get();
        foreach ($rooms as $room) {
            if (! is_array($room)) {
                continue;
            }
            $code = trim((string) ($room['room_id'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($room['room_name'] ?? ''));
            $map = AiosellRoomMap::query()->firstOrNew(['room_code' => $code]);
            $map->room_name = $name !== '' ? $name : $map->room_name;
            if (! $map->exists || $map->room_type_id === null) {
                $match = $passionTypes->first(function (RoomType $type) use ($name) {
                    return $name !== '' && strcasecmp((string) $type->name, $name) === 0;
                });
                if ($match) {
                    $map->room_type_id = $match->id;
                }
            }
            if (! $map->exists) {
                $map->active = true;
            }
            $map->save();

            $plans = $room['rateplans'] ?? [];
            if (! is_array($plans)) {
                continue;
            }
            foreach ($plans as $plan) {
                if (! is_array($plan)) {
                    continue;
                }
                $rateCode = trim((string) ($plan['rateplan_id'] ?? ''));
                if ($rateCode === '') {
                    continue;
                }
                $occupancy = (int) ($plan['occupancy'] ?? 0);
                $meals = (int) ($plan['no_of_meals'] ?? 0);
                $row = AiosellRatePlanMap::query()->firstOrNew(['rateplan_code' => $rateCode]);
                $row->room_code = $code;
                $row->occupancy_letter = self::occupancyLetter($occupancy);
                $row->meal_code = self::mealCodeFromRateplan($rateCode) ?? self::mealCode($meals);
                $row->rateplan_name = trim((string) ($plan['rateplan_name'] ?? '')) ?: $row->rateplan_name;
                if ($row->room_type_id === null && $map->room_type_id) {
                    $row->room_type_id = $map->room_type_id;
                }
                if ($row->rate_plan_id === null && $row->room_type_id) {
                    $mealType = self::passionMeal($row->meal_code);
                    $candidates = RatePlan::query()
                        ->where('room_type_id', $row->room_type_id)
                        ->where('is_active', true)
                        ->where('billing_unit', '!=', 'hour_package')
                        ->when($mealType !== null, fn ($q) => $q->where('meal_plan_type', $mealType))
                        ->get();
                    if ($candidates->count() === 1) {
                        $row->rate_plan_id = $candidates->first()->id;
                    }
                }
                if (! $row->exists) {
                    $row->active = true;
                }
                $row->save();
            }
        }

        $channels = [];
        foreach ($body['connected_channels'] ?? [] as $channel) {
            if (is_array($channel) && ! empty($channel['partner_id'])) {
                $channels[] = (string) $channel['partner_id'];
            }
        }
        $integration = AiosellIntegration::current();
        $integration->connected_channels = array_values(array_unique($channels));
        $integration->last_error = null;
        $integration->save();

        return ['ok' => true, 'message' => 'Mapping loaded.'];
    }

    public static function occupancyLetter(int $occupancy): string
    {
        return match ($occupancy) {
            1 => 's',
            2 => 'd',
            3 => 't',
            4 => 'q',
            default => $occupancy > 0 ? (string) $occupancy : 's',
        };
    }

    public static function mealCode(int $meals): string
    {
        return match ($meals) {
            0 => 'EP',
            1 => 'CP',
            2 => 'MAP',
            default => 'AP',
        };
    }

    /**
     * Rate plan codes follow `{room}-{occupancy}-{mealplan}`; AioSell's no_of_meals does not always agree with the suffix.
     */
    public static function mealCodeFromRateplan(string $rateplanCode): ?string
    {
        $suffix = strtoupper((string) substr((string) strrchr($rateplanCode, '-'), 1));

        return in_array($suffix, ['EP', 'CP', 'MAP', 'AP'], true) ? $suffix : null;
    }

    public static function passionMeal(?string $code): ?string
    {
        return match (strtoupper((string) $code)) {
            'EP' => 'room_only',
            'CP' => 'breakfast',
            'MAP', 'MP' => 'half_board',
            'AP' => 'full_board',
            default => null,
        };
    }
}
