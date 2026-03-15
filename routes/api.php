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
use App\Http\Controllers\MenuCategoryController;
use App\Http\Controllers\MenuSubCategoryController;
use App\Http\Controllers\MenuItemController;
use App\Http\Controllers\DietaryTypeController;
use App\Http\Controllers\ComboController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RestaurantMasterController;
use App\Http\Controllers\TableCategoryController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableReservationController;
use App\Http\Controllers\PosController;

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

    // F&B Module (Restaurant Master)
    Route::apiResource('restaurant-masters', RestaurantMasterController::class);

    // F&B Module (Table Master)
    Route::apiResource('table-categories', TableCategoryController::class);
    Route::apiResource('tables', TableController::class);
    Route::post('table-reservations/{tableReservation}/check-in', [TableReservationController::class, 'checkIn']);
    Route::post('table-reservations/{tableReservation}/complete',  [TableReservationController::class, 'complete']);
    Route::post('table-reservations/{tableReservation}/cancel',    [TableReservationController::class, 'cancel']);
    Route::apiResource('table-reservations', TableReservationController::class);

    // POS Module
    Route::get('pos/restaurants',              [PosController::class, 'restaurants']);
    Route::get('pos/tables',                   [PosController::class, 'tables']);
    Route::get('pos/menu',                     [PosController::class, 'menu']);
    Route::post('pos/orders',                  [PosController::class, 'openOrder']);
    Route::get('pos/orders/{order}',           [PosController::class, 'getOrder']);
    Route::put('pos/orders/{order}/items',     [PosController::class, 'syncItems']);
    Route::post('pos/orders/{order}/kot',      [PosController::class, 'sendKot']);
    Route::post('pos/orders/{order}/settle',   [PosController::class, 'settle']);
    Route::post('pos/orders/{order}/void',     [PosController::class, 'void']);

    // F&B Module (Menu Configuration)
    Route::apiResource('menu-categories', MenuCategoryController::class);
    Route::apiResource('menu-sub-categories', MenuSubCategoryController::class);
    Route::apiResource('menu-items', MenuItemController::class);
    Route::apiResource('menu-dietary-types', DietaryTypeController::class);
    Route::apiResource('menu-combos', ComboController::class);

    // BOM / Recipe Module
    Route::get('recipes',                            [RecipeController::class, 'index']);
    Route::put('recipes/menu-item/{menuItemId}',     [RecipeController::class, 'upsert']);
    Route::post('recipes/{recipe}/produce',          [RecipeController::class, 'produce']);
    Route::get('production-logs',                    [RecipeController::class, 'productionLogs']);
    Route::get('production-logs/{log}/details',      [RecipeController::class, 'productionLogDetails']);

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
});
