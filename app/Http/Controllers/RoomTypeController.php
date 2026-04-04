<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RoomTypeController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $query = RoomType::with(['tax', 'ratePlans', 'seasons']);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Validate that capacity == base_occupancy + extra_bed_capacity + child_sharing_limit
     */
    private function validateCapacity(array $data): void
    {
        $base = (int) ($data['base_occupancy'] ?? 0);
        $extraBed = (int) ($data['extra_bed_capacity'] ?? 0);
        $child = (int) ($data['child_sharing_limit'] ?? 0);
        $expected = $base + $extraBed + $child;

        if ((int) $data['capacity'] !== $expected) {
            throw ValidationException::withMessages([
                'capacity' => "Max Capacity must equal Base Occupancy ({$base}) + ExBed Limit ({$extraBed}) + Child Sharing Limit ({$child}) = {$expected}.",
            ]);
        }
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-rooms');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'breakfast_price' => 'nullable|numeric|min:0',
            'child_breakfast_price' => 'nullable|numeric|min:0',
            'adult_lunch_price' => 'nullable|numeric|min:0',
            'child_lunch_price' => 'nullable|numeric|min:0',
            'adult_dinner_price' => 'nullable|numeric|min:0',
            'child_dinner_price' => 'nullable|numeric|min:0',
            'child_age_limit' => 'nullable|integer|min:1',
            'extra_bed_cost' => 'required|numeric|min:0',
            'child_extra_bed_cost' => 'nullable|numeric|min:0',
            'early_check_in_fee' => 'nullable|numeric|min:0',
            'early_check_in_type' => 'nullable|string|in:per_hour,per_minute,flat_fee,percentage',
            'early_check_in_buffer_minutes' => 'nullable|integer|min:0',
            'late_check_out_fee' => 'nullable|numeric|min:0',
            'late_check_out_type' => 'nullable|string|in:per_hour,per_minute,flat_fee,percentage',
            'late_check_out_buffer_minutes' => 'nullable|integer|min:0',
            'base_occupancy' => 'required|integer|min:1',
            'capacity' => 'required|integer|min:1',
            'extra_bed_capacity' => 'required|integer|min:0',
            'child_sharing_limit' => 'required|integer|min:0',
            'bed_config' => 'nullable|string|max:255',
            'amenities' => 'nullable|array',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'seasonal_prices' => 'nullable|array',
            'seasonal_prices.*.season_name' => 'required_with:seasonal_prices|string|max:255',
            'seasonal_prices.*.start_date' => 'required_with:seasonal_prices|date',
            'seasonal_prices.*.end_date' => 'required_with:seasonal_prices|date',
            'seasonal_prices.*.adjustment_type' => 'nullable|string|in:override,add_fixed,add_percent,discount',
            'seasonal_prices.*.price_adjustment' => 'required_with:seasonal_prices|numeric',
            'rate_plans' => 'nullable|array',
            'rate_plans.*.name' => 'required_with:rate_plans|string|max:255',
            'rate_plans.*.base_price' => 'required_with:rate_plans|numeric|min:0',
            'rate_plans.*.meal_plan_type' => 'nullable|string|in:room_only,breakfast,half_board,full_board',
            // Hourly package extensions (backward compatible)
            'rate_plans.*.billing_unit' => 'nullable|in:day,hour_package',
            'rate_plans.*.package_hours' => 'nullable|integer|min:1',
            'rate_plans.*.package_price' => 'nullable|numeric|min:0',
            'rate_plans.*.grace_minutes' => 'nullable|integer|min:0',
            'rate_plans.*.overtime_step_minutes' => 'nullable|integer|min:1',
            'rate_plans.*.overtime_hour_price' => 'nullable|numeric|min:0',
        ]);

        $this->validateCapacity($validated);

        $ratePlans = $validated['rate_plans'] ?? [];
        $seasonalPrices = $validated['seasonal_prices'] ?? [];
        unset($validated['rate_plans'], $validated['seasonal_prices']);

        $roomType = RoomType::create($validated);

        if (! empty($ratePlans)) {
            $roomType->ratePlans()->createMany($ratePlans);
        }

        if (! empty($seasonalPrices)) {
            $roomType->seasons()->createMany($seasonalPrices);
        }

        return response()->json($roomType->load(['tax', 'ratePlans', 'seasons']), 201);
    }

    public function show(RoomType $roomType)
    {
        return $roomType->load(['tax', 'ratePlans', 'seasons']);
    }

    public function update(Request $request, RoomType $roomType)
    {
        $this->checkPermission('manage-rooms');
        $validated = $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'breakfast_price' => 'nullable|numeric|min:0',
            'child_breakfast_price' => 'nullable|numeric|min:0',
            'adult_lunch_price' => 'nullable|numeric|min:0',
            'child_lunch_price' => 'nullable|numeric|min:0',
            'adult_dinner_price' => 'nullable|numeric|min:0',
            'child_dinner_price' => 'nullable|numeric|min:0',
            'child_age_limit' => 'nullable|integer|min:0|max:18',
            'extra_bed_cost' => 'numeric|min:0',
            'child_extra_bed_cost' => 'nullable|numeric|min:0',
            'base_occupancy' => 'integer|min:1',
            'capacity' => 'integer|min:1',
            'extra_bed_capacity' => 'integer|min:0',
            'child_sharing_limit' => 'integer|min:0',
            'bed_config' => 'nullable|string|max:255',
            'amenities' => 'nullable|array',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'seasonal_prices' => 'nullable|array',
            'seasonal_prices.*.id' => 'nullable|exists:room_type_seasons,id',
            'seasonal_prices.*.season_name' => 'required_with:seasonal_prices|string|max:255',
            'seasonal_prices.*.start_date' => 'required_with:seasonal_prices|date',
            'seasonal_prices.*.end_date' => 'required_with:seasonal_prices|date',
            'seasonal_prices.*.adjustment_type' => 'nullable|string|in:override,add_fixed,add_percent,discount',
            'seasonal_prices.*.price_adjustment' => 'required_with:seasonal_prices|numeric',
            'rate_plans' => 'nullable|array',
            'rate_plans.*.id' => 'nullable|exists:rate_plans,id',
            'rate_plans.*.name' => 'required_with:rate_plans|string|max:255',
            'rate_plans.*.base_price' => 'required_with:rate_plans|numeric|min:0',
            'rate_plans.*.meal_plan_type' => 'nullable|string|in:room_only,breakfast,half_board,full_board',
            'rate_plans.*.includes_breakfast' => 'nullable|boolean',
            'rate_plans.*.includes_lunch' => 'nullable|boolean',
            'rate_plans.*.includes_dinner' => 'nullable|boolean',
            'early_check_in_fee' => 'nullable|numeric|min:0',
            'early_check_in_type' => 'nullable|string|in:per_hour,per_minute,flat_fee,percentage',
            'early_check_in_buffer_minutes' => 'nullable|integer|min:0',
            'late_check_out_fee' => 'nullable|numeric|min:0',
            'late_check_out_type' => 'nullable|string|in:per_hour,per_minute,flat_fee,percentage',
            'late_check_out_buffer_minutes' => 'nullable|integer|min:0',
            // Hourly package extensions (backward compatible)
            'rate_plans.*.billing_unit' => 'nullable|in:day,hour_package',
            'rate_plans.*.package_hours' => 'nullable|integer|min:1',
            'rate_plans.*.package_price' => 'nullable|numeric|min:0',
            'rate_plans.*.grace_minutes' => 'nullable|integer|min:0',
            'rate_plans.*.overtime_step_minutes' => 'nullable|integer|min:1',
            'rate_plans.*.overtime_hour_price' => 'nullable|numeric|min:0',
        ]);

        // Merge with existing values to handle partial updates
        $merged = array_merge([
            'base_occupancy' => $roomType->base_occupancy,
            'extra_bed_capacity' => $roomType->extra_bed_capacity,
            'child_sharing_limit' => $roomType->child_sharing_limit,
            'capacity' => $roomType->capacity,
        ], $validated);

        $this->validateCapacity($merged);

        $syncRatePlans = array_key_exists('rate_plans', $validated);
        $syncSeasonalPrices = array_key_exists('seasonal_prices', $validated);
        $ratePlansPayload = $validated['rate_plans'] ?? [];
        $seasonalPricesPayload = $validated['seasonal_prices'] ?? [];
        unset($validated['rate_plans'], $validated['seasonal_prices']);

        $roomType->update($validated);

        if ($syncRatePlans) {
            $incomingPlans = is_array($ratePlansPayload) ? $ratePlansPayload : [];
            $incomingIds = collect($incomingPlans)->pluck('id')->filter()->toArray();
            $roomType->ratePlans()->whereNotIn('id', $incomingIds)->delete();

            foreach ($incomingPlans as $planData) {
                if (! empty($planData['id'])) {
                    $roomType->ratePlans()->where('id', $planData['id'])->update([
                        'name' => $planData['name'],
                        'base_price' => $planData['base_price'],
                        'meal_plan_type' => $planData['meal_plan_type'] ?? 'room_only',
                        'billing_unit' => $planData['billing_unit'] ?? 'day',
                        'package_hours' => $planData['package_hours'] ?? null,
                        'package_price' => $planData['package_price'] ?? null,
                        'grace_minutes' => $planData['grace_minutes'] ?? 0,
                        'overtime_step_minutes' => $planData['overtime_step_minutes'] ?? 60,
                        'overtime_hour_price' => $planData['overtime_hour_price'] ?? null,
                    ]);
                } else {
                    $roomType->ratePlans()->create([
                        'name' => $planData['name'],
                        'base_price' => $planData['base_price'],
                        'meal_plan_type' => $planData['meal_plan_type'] ?? 'room_only',
                        'billing_unit' => $planData['billing_unit'] ?? 'day',
                        'package_hours' => $planData['package_hours'] ?? null,
                        'package_price' => $planData['package_price'] ?? null,
                        'grace_minutes' => $planData['grace_minutes'] ?? 0,
                        'overtime_step_minutes' => $planData['overtime_step_minutes'] ?? 60,
                        'overtime_hour_price' => $planData['overtime_hour_price'] ?? null,
                    ]);
                }
            }
        }

        if ($syncSeasonalPrices) {
            $incomingSeasons = is_array($seasonalPricesPayload) ? $seasonalPricesPayload : [];
            $incomingSeasonIds = collect($incomingSeasons)->pluck('id')->filter()->toArray();
            $roomType->seasons()->whereNotIn('id', $incomingSeasonIds)->delete();

            foreach ($incomingSeasons as $seasonData) {
                if (! empty($seasonData['id'])) {
                    $roomType->seasons()->where('id', $seasonData['id'])->update([
                        'season_name' => $seasonData['season_name'],
                        'start_date' => $seasonData['start_date'],
                        'end_date' => $seasonData['end_date'],
                        'adjustment_type' => $seasonData['adjustment_type'] ?? 'override',
                        'price_adjustment' => $seasonData['price_adjustment'],
                    ]);
                } else {
                    $roomType->seasons()->create([
                        'season_name' => $seasonData['season_name'],
                        'start_date' => $seasonData['start_date'],
                        'end_date' => $seasonData['end_date'],
                        'adjustment_type' => $seasonData['adjustment_type'] ?? 'override',
                        'price_adjustment' => $seasonData['price_adjustment'],
                    ]);
                }
            }
        }

        return response()->json($roomType->load(['tax', 'ratePlans', 'seasons']));
    }

    public function destroy(RoomType $roomType)
    {
        $this->checkPermission('manage-rooms');
        try {
            $roomType->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete room type as it has existing rooms assigned to it.'], 409);
            }
            throw $e;
        }
    }
}
