<?php

use App\Models\RestaurantMaster;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/** POS / kitchen live updates: same access rules as PosController::restaurants(). */
Broadcast::channel('pos.restaurant.{restaurantId}', function ($user, $restaurantId) {
    $rid = (int) $restaurantId;

    if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
        return RestaurantMaster::where('id', '=', $rid, 'and')->where('is_active', '=', true, 'and')->exists();
    }

    $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn($id) => (int) $id)->all();
    if (count($assigned) > 0) {
        return in_array($rid, $assigned, true);
    }

    $deptIds = $user->departments()->pluck('departments.id')->map(fn($id) => (int) $id)->all();
    if (count($deptIds) > 0) {
        return RestaurantMaster::where('id', '=', $rid, 'and')
            ->where('is_active', '=', true, 'and')
            ->where(function ($q) use ($deptIds) {
                $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
            })
            ->exists();
    }

    return false;
});

/** Housekeeping daily cleaning → front desk toast (occupied room service complete). */
Broadcast::channel('reception.housekeeping', function ($user) {
    if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
        return true;
    }
    if (method_exists($user, 'can') && ($user->can('view-rooms') || $user->can('reservation'))) {
        return true;
    }

    return false;
});

/** Room PAR stock levels (inventory restock, HK refill, store request to room). */
Broadcast::channel('inventory.room-par', function ($user) {
    if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
        return true;
    }
    if (! method_exists($user, 'can')) {
        return false;
    }

    return $user->can('manage-inventory')
        || $user->can('housekeeping-room-stock')
        || $user->can('view-rooms')
        || $user->can('reservation-view')
        || $user->can('reservation');
});

/** Reception live billing updates for a booking (checkout inspection charges). */
Broadcast::channel('reception.booking.{bookingId}', function ($user, $bookingId) {
    // Same security intent as reception screens: allow staff who can view rooms / reservations.
    if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
        return true;
    }
    if (method_exists($user, 'can') && ($user->can('view-rooms') || $user->can('reservation'))) {
        return true;
    }

    return false;
});
