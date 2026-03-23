<?php

namespace App\Http\Controllers;

use App\Models\RestaurantTable;
use App\Models\TableReservation;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TableReservationController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $this->checkPermission('reservation');
        $query = TableReservation::with('table');

        if ($request->has('date')) {
            $query->whereDate('reservation_date', $request->date);
        }

        return response()->json($query->orderBy('reservation_time')->get());
    }

    public function store(Request $request)
    {
        $this->checkPermission('reservation');
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

        $overlap = TableReservation::where('table_id', $validated['table_id'])
            ->where('reservation_date', $validated['reservation_date'])
            ->where('reservation_time', $validated['reservation_time'])
            ->whereIn('status', ['pending', 'confirmed', 'seated'])
            ->exists();

        if ($overlap) {
            return response()->json(['message' => 'Table is already reserved for this date and time.'], 422);
        }

        $reservation = TableReservation::create($validated);

        return response()->json($reservation->load('table'), 201);
    }

    public function show(TableReservation $tableReservation)
    {
        $this->checkPermission('reservation');
        return response()->json($tableReservation->load('table'));
    }

    public function update(Request $request, TableReservation $tableReservation)
    {
        $this->checkPermission('reservation');
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
        $this->checkPermission('reservation');
        $tableReservation->delete();

        return response()->json(null, 204);
    }

    public function checkIn(TableReservation $tableReservation)
    {
        $this->checkPermission('reservation');
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
        $this->checkPermission('reservation');
        $tableReservation->update(['status' => 'completed']);

        RestaurantTable::where('id', $tableReservation->table_id)
            ->update(['status' => 'available']);

        return response()->json($tableReservation->load('table'));
    }

    public function cancel(TableReservation $tableReservation)
    {
        $this->checkPermission('reservation');
        $tableReservation->update(['status' => 'cancelled']);

        if ($tableReservation->status === 'seated') {
            RestaurantTable::where('id', $tableReservation->table_id)
                ->update(['status' => 'available']);
        }

        return response()->json($tableReservation->load('table'));
    }
}
