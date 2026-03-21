<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoomTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = RoomType::with(['tax', 'ratePlans']);
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'base_price' => 'required|numeric|min:0',
            'breakfast_price' => 'nullable|numeric|min:0',
            'child_breakfast_price' => 'nullable|numeric|min:0',
            'extra_bed_cost' => 'required|numeric|min:0',
            'base_occupancy' => 'required|integer|min:1',
            'capacity' => 'required|integer|min:1',
            'extra_bed_capacity' => 'required|integer|min:0',
            'child_sharing_limit' => 'required|integer|min:0',
            'bed_config' => 'nullable|string|max:255',
            'amenities' => 'nullable|array',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'rate_plans' => 'nullable|array',
            'rate_plans.*.name' => 'required_with:rate_plans|string|max:255',
            'rate_plans.*.base_price' => 'required_with:rate_plans|numeric|min:0',
            'rate_plans.*.includes_breakfast' => 'nullable|boolean',
            // Hourly package extensions (backward compatible)
            'rate_plans.*.billing_unit' => 'nullable|in:day,hour_package',
            'rate_plans.*.package_hours' => 'nullable|integer|min:1',
            'rate_plans.*.package_price' => 'nullable|numeric|min:0',
            'rate_plans.*.grace_minutes' => 'nullable|integer|min:0',
            'rate_plans.*.overtime_step_minutes' => 'nullable|integer|min:1',
            'rate_plans.*.overtime_hour_price' => 'nullable|numeric|min:0',
        ]);

        $this->validateCapacity($validated);

        $roomType = RoomType::create($validated);

        if (! empty($validated['rate_plans'])) {
            $roomType->ratePlans()->createMany($validated['rate_plans']);
        }

        return response()->json($roomType->load(['tax', 'ratePlans']), 201);
    }

    public function show(RoomType $roomType)
    {
        return $roomType->load(['tax', 'ratePlans']);
    }

    public function update(Request $request, RoomType $roomType)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'base_price' => 'numeric|min:0',
            'breakfast_price' => 'nullable|numeric|min:0',
            'child_breakfast_price' => 'nullable|numeric|min:0',
            'extra_bed_cost' => 'numeric|min:0',
            'base_occupancy' => 'integer|min:1',
            'capacity' => 'integer|min:1',
            'extra_bed_capacity' => 'integer|min:0',
            'child_sharing_limit' => 'integer|min:0',
            'bed_config' => 'nullable|string|max:255',
            'amenities' => 'nullable|array',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'rate_plans' => 'nullable|array',
            'rate_plans.*.id' => 'nullable|exists:rate_plans,id',
            'rate_plans.*.name' => 'required_with:rate_plans|string|max:255',
            'rate_plans.*.base_price' => 'required_with:rate_plans|numeric|min:0',
            'rate_plans.*.includes_breakfast' => 'nullable|boolean',
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

        $roomType->update($validated);

        if (array_key_exists('rate_plans', $validated)) {
            $incomingPlans = $validated['rate_plans'] ?? [];
            $incomingIds = collect($incomingPlans)->pluck('id')->filter()->toArray();
            $roomType->ratePlans()->whereNotIn('id', $incomingIds)->delete();

            foreach ($incomingPlans as $planData) {
                if (! empty($planData['id'])) {
                    $roomType->ratePlans()->where('id', $planData['id'])->update([
                        'name' => $planData['name'],
                        'base_price' => $planData['base_price'],
                        'includes_breakfast' => $planData['includes_breakfast'] ?? false,
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
                        'includes_breakfast' => $planData['includes_breakfast'] ?? false,
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

        return response()->json($roomType->load(['tax', 'ratePlans']));
    }

    public function destroy(RoomType $roomType)
    {
        $roomType->delete();

        return response()->json(null, 204);
    }
}
