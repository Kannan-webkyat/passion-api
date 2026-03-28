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
        return RestaurantMaster::where('id', $rid)->where('is_active', true)->exists();
    }

    $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
    if (count($assigned) > 0) {
        return in_array($rid, $assigned, true);
    }

    $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
    if (count($deptIds) > 0) {
        return RestaurantMaster::where('id', $rid)
            ->where('is_active', true)
            ->where(function ($q) use ($deptIds) {
                $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
            })
            ->exists();
    }

    return false;
});
