<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DepartmentController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Room Types
    Route::apiResource('room-types', RoomTypeController::class);

    // Rooms
    Route::apiResource('rooms', RoomController::class);

    // Users, Roles & Departments
    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('departments', DepartmentController::class);
    Route::get('permissions', [RoleController::class, 'permissions']);

    // Bookings & Room Chart
    Route::get('bookings/chart',   [BookingController::class, 'chart']);
    Route::get('bookings/summary', [BookingController::class, 'summary']);
    Route::post('bookings/{booking}/early-checkin',  [BookingController::class, 'earlyCheckin']);
    Route::post('bookings/{booking}/late-checkout',  [BookingController::class, 'lateCheckout']);
    Route::post('bookings/{booking}/extend',         [BookingController::class, 'extendReservation']);
    Route::apiResource('bookings', BookingController::class);

    // Inventory Module
    Route::get('inventory/stats',  [\App\Http\Controllers\InventoryController::class, 'stats']);
    Route::post('inventory/issue', [\App\Http\Controllers\InventoryController::class, 'issue']);
    Route::apiResource('inventory/items',      \App\Http\Controllers\InventoryController::class);
    Route::apiResource('inventory/categories', \App\Http\Controllers\InventoryCategoryController::class);
    Route::apiResource('inventory/uoms',       \App\Http\Controllers\InventoryUomController::class);
    Route::apiResource('inventory/taxes',      \App\Http\Controllers\InventoryTaxController::class);
    Route::apiResource('inventory/vendors',    \App\Http\Controllers\VendorController::class);
    Route::apiResource('inventory/locations',  \App\Http\Controllers\InventoryLocationController::class);
    Route::apiResource('inventory/store-requests', \App\Http\Controllers\StoreRequestController::class);
    Route::post('inventory/store-requests/{storeRequest}/approve', [\App\Http\Controllers\StoreRequestController::class, 'approve']);
    Route::post('inventory/store-requests/{storeRequest}/issue',   [\App\Http\Controllers\StoreRequestController::class, 'issue']);
    Route::post('inventory/store-requests/{storeRequest}/reject',  [\App\Http\Controllers\StoreRequestController::class, 'reject']);

    Route::apiResource('inventory/purchase-orders', \App\Http\Controllers\PurchaseOrderController::class);
    Route::post('inventory/purchase-orders/{purchaseOrder}/receive', [\App\Http\Controllers\PurchaseOrderController::class, 'receive']);
    Route::post('inventory/purchase-orders/{purchaseOrder}/pay', [\App\Http\Controllers\PurchaseOrderController::class, 'pay']);
    Route::apiResource('payment-methods', \App\Http\Controllers\PaymentMethodController::class);
    Route::get('inventory/movements', [\App\Http\Controllers\StockMovementController::class, 'index']);

    // Restaurant Tables
    Route::apiResource('table-categories', \App\Http\Controllers\TableCategorieController::class);
    Route::patch('tables/{table}/status', [\App\Http\Controllers\TableController::class, 'changeStatus']);
    Route::apiResource('tables', \App\Http\Controllers\TableController::class);
});
