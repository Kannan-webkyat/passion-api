<?php

namespace App\Http\Controllers;

use App\Models\RestaurantTable;
use App\Models\TableReservation;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TableReservationController extends Controller
{
    public function index(Request $request)
    {
        $query = TableReservation::with('table');

        if ($request->has('date')) {
            $query->whereDate('reservation_date', $request->date);
        }

        return response()->json($query->orderBy('reservation_time')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'table_id' => 'required|exists:restaurant_tables,id',
            'guest_name' => 'required|string|max:255',
            'guest_phone' => 'nullable|string|max:50',
            'guest_email' => 'nullable|email|max:255',
            'party_size' => 'required|integer|min:1',
            'reservation_date' => 'required|date',
            'reservation_time' => 'required|date_format:H:i',
            'special_requests' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
        ]);

        $reservation = TableReservation::create($validated);

        return response()->json($reservation->load('table'), 201);
    }

    public function show(TableReservation $tableReservation)
    {
        return response()->json($tableReservation->load('table'));
    }

    public function update(Request $request, TableReservation $tableReservation)
    {
        $validated = $request->validate([
            'table_id' => 'sometimes|required|exists:restaurant_tables,id',
            'guest_name' => 'sometimes|required|string|max:255',
            'guest_phone' => 'nullable|string|max:50',
            'guest_email' => 'nullable|email|max:255',
            'party_size' => 'sometimes|required|integer|min:1',
            'reservation_date' => 'sometimes|required|date',
            'reservation_time' => 'sometimes|required|date_format:H:i',
            'special_requests' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
        ]);

        $tableReservation->update($validated);

        return response()->json($tableReservation->load('table'));
    }

    public function destroy(TableReservation $tableReservation)
    {
        $tableReservation->delete();

        return response()->json(null, 204);
    }

    public function checkIn(TableReservation $tableReservation)
    {
        $tableReservation->update([
            'status' => 'seated',
            'checked_in_at' => Carbon::now(),
        ]);

        RestaurantTable::where('id', $tableReservation->table_id)
            ->update(['status' => 'occupied']);

        return response()->json($tableReservation->load('table'));
    }

    public function complete(TableReservation $tableReservation)
    {
        $tableReservation->update(['status' => 'completed']);

        RestaurantTable::where('id', $tableReservation->table_id)
            ->update(['status' => 'available']);

        return response()->json($tableReservation->load('table'));
    }

    public function cancel(TableReservation $tableReservation)
    {
        $tableReservation->update(['status' => 'cancelled']);

        if ($tableReservation->status === 'seated') {
            RestaurantTable::where('id', $tableReservation->table_id)
                ->update(['status' => 'available']);
        }

        return response()->json($tableReservation->load('table'));
    }
}
