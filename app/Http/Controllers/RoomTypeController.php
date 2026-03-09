<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoomTypeController extends Controller
{
    public function index()
    {
        return RoomType::with('tax')->get();
    }

    /**
     * Validate that capacity == base_occupancy + extra_bed_capacity + child_sharing_limit
     */
    private function validateCapacity(array $data): void
    {
        $base     = (int) ($data['base_occupancy']     ?? 0);
        $extraBed = (int) ($data['extra_bed_capacity'] ?? 0);
        $child    = (int) ($data['child_sharing_limit'] ?? 0);
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
            'name'                  => 'required|string|max:255',
            'description'           => 'nullable|string',
            'base_price'            => 'required|numeric|min:0',
            'breakfast_price'       => 'nullable|numeric|min:0',
            'child_breakfast_price' => 'nullable|numeric|min:0',
            'extra_bed_cost'        => 'required|numeric|min:0',
            'base_occupancy'        => 'required|integer|min:1',
            'capacity'              => 'required|integer|min:1',
            'extra_bed_capacity'    => 'required|integer|min:0',
            'child_sharing_limit'   => 'required|integer|min:0',
            'bed_config'            => 'nullable|string|max:255',
            'amenities'             => 'nullable|array',
            'tax_id'                => 'nullable|exists:inventory_taxes,id',
        ]);

        $this->validateCapacity($validated);

        $roomType = RoomType::create($validated);

        return response()->json($roomType->load('tax'), 201);
    }

    public function show(RoomType $roomType)
    {
        return $roomType->load('tax');
    }

    public function update(Request $request, RoomType $roomType)
    {
        $validated = $request->validate([
            'name'                  => 'string|max:255',
            'description'           => 'nullable|string',
            'base_price'            => 'numeric|min:0',
            'breakfast_price'       => 'nullable|numeric|min:0',
            'child_breakfast_price' => 'nullable|numeric|min:0',
            'extra_bed_cost'        => 'numeric|min:0',
            'base_occupancy'        => 'integer|min:1',
            'capacity'              => 'integer|min:1',
            'extra_bed_capacity'    => 'integer|min:0',
            'child_sharing_limit'   => 'integer|min:0',
            'bed_config'            => 'nullable|string|max:255',
            'amenities'             => 'nullable|array',
            'tax_id'                => 'nullable|exists:inventory_taxes,id',
        ]);

        // Merge with existing values to handle partial updates
        $merged = array_merge([
            'base_occupancy'      => $roomType->base_occupancy,
            'extra_bed_capacity'  => $roomType->extra_bed_capacity,
            'child_sharing_limit' => $roomType->child_sharing_limit,
            'capacity'            => $roomType->capacity,
        ], $validated);

        $this->validateCapacity($merged);

        $roomType->update($validated);

        return response()->json($roomType->load('tax'));
    }

    public function destroy(RoomType $roomType)
    {
        $roomType->delete();
        return response()->json(null, 204);
    }
}
