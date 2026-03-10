<?php

namespace App\Http\Controllers;

use App\Models\TableReservation;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TableReservationController extends Controller
{
    public function index(Request $request)
    {
        $query = TableReservation::with('table.category')
            ->orderBy('reservation_date')
            ->orderBy('reservation_time');

        if ($request->has('date')) {
            $query->whereDate('reservation_date', $request->date);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'table_id'        => 'required|exists:restaurant_tables,id',
            'guest_name'      => 'required|string|max:255',
            'guest_phone'     => 'nullable|string|max:50',
            'guest_email'     => 'nullable|email|max:255',
            'party_size'      => 'required|integer|min:1',
            'reservation_date' => 'required|date|after_or_equal:today',
            'reservation_time' => 'required',
            'status'          => 'nullable|in:confirmed,seated,completed,cancelled,no_show',
            'special_requests' => 'nullable|string|max:500',
            'notes'           => 'nullable|string',
        ]);

        $reservation = TableReservation::create($validated);

        // Mark table as reserved on the reservation date
        $this->syncTableStatus($validated['table_id']);

        return response()->json($reservation->load('table.category'), 201);
    }

    public function show(TableReservation $tableReservation)
    {
        return response()->json($tableReservation->load('table.category'));
    }

    public function update(Request $request, TableReservation $tableReservation)
    {
        $validated = $request->validate([
            'table_id'        => 'sometimes|exists:restaurant_tables,id',
            'guest_name'      => 'sometimes|string|max:255',
            'guest_phone'     => 'nullable|string|max:50',
            'guest_email'     => 'nullable|email|max:255',
            'party_size'      => 'sometimes|integer|min:1',
            'reservation_date' => 'sometimes|date',
            'reservation_time' => 'sometimes',
            'status'          => 'sometimes|in:confirmed,seated,completed,cancelled,no_show',
            'special_requests' => 'nullable|string|max:500',
            'notes'           => 'nullable|string',
        ]);

        $tableReservation->update($validated);
        $this->syncTableStatus($tableReservation->table_id);

        return response()->json($tableReservation->load('table.category'));
    }

    public function destroy(TableReservation $tableReservation)
    {
        $tableId = $tableReservation->table_id;
        $tableReservation->delete();
        $this->syncTableStatus($tableId);
        return response()->json(null, 204);
    }

    /**
     * Check-in a guest: change reservation to "seated" and table to "occupied"
     */
    public function checkIn(TableReservation $tableReservation)
    {
        if (!in_array($tableReservation->status, ['confirmed'])) {
            return response()->json(['message' => 'Reservation cannot be checked in from current status.'], 422);
        }

        $tableReservation->update([
            'status'       => 'seated',
            'checked_in_at' => now(),
        ]);

        RestaurantTable::find($tableReservation->table_id)->update(['status' => 'occupied']);

        return response()->json($tableReservation->load('table.category'));
    }

    /**
     * Complete / check-out a reservation and free the table
     */
    public function complete(TableReservation $tableReservation)
    {
        $tableReservation->update(['status' => 'completed']);
        $tableId = $tableReservation->table_id;
        $this->syncTableStatus($tableId);

        return response()->json($tableReservation->load('table.category'));
    }

    /**
     * Cancel a reservation
     */
    public function cancel(TableReservation $tableReservation)
    {
        $tableReservation->update(['status' => 'cancelled']);
        $this->syncTableStatus($tableReservation->table_id);
        return response()->json($tableReservation->load('table.category'));
    }

    /**
     * Automatically sync table status based on active reservations for today
     */
    private function syncTableStatus(int $tableId)
    {
        $today = Carbon::today()->toDateString();
        $table = RestaurantTable::find($tableId);
        if (!$table) return;

        $activeReservation = TableReservation::where('table_id', $tableId)
            ->whereDate('reservation_date', $today)
            ->whereIn('status', ['confirmed', 'seated'])
            ->first();

        if ($activeReservation) {
            $newStatus = $activeReservation->status === 'seated' ? 'occupied' : 'reserved';
            $table->update(['status' => $newStatus]);
        } else {
            // Only revert to available if not manually set to maintenance/cleaning
            if (in_array($table->status, ['reserved', 'occupied'])) {
                $table->update(['status' => 'available']);
            }
        }
    }
}
