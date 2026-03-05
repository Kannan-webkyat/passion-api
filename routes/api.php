<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Room Types
    Route::apiResource('room-types', RoomTypeController::class);

    // Rooms
    Route::apiResource('rooms', RoomController::class);

    // Users & Roles
    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::get('permissions', [RoleController::class, 'permissions']);

    // Bookings & Room Chart
    Route::get('bookings/chart',   [BookingController::class, 'chart']);
    Route::get('bookings/summary', [BookingController::class, 'summary']);
    Route::apiResource('bookings', BookingController::class);
});
