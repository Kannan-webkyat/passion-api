<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ComboController;
use App\Http\Controllers\DayClosingController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DietaryTypeController;
use App\Http\Controllers\MenuCategoryController;
use App\Http\Controllers\MenuItemController;
use App\Http\Controllers\MenuPricingController;
use App\Http\Controllers\MenuSubCategoryController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\QzSignController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RestaurantMasterController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\HousekeepingController;
use App\Http\Controllers\RoomStatusBlockController;
use App\Http\Controllers\RoomTypeController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TableCategoryController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableReservationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Room Types
    Route::apiResource('room-types', RoomTypeController::class);

    // Rooms
    Route::apiResource('rooms', RoomController::class);
    Route::apiResource('room-status-blocks', RoomStatusBlockController::class);

    // Users, Roles & Departments
    Route::apiResource('users', UserController::class);
    Route::apiResource('roles', RoleController::class);
    Route::apiResource('departments', DepartmentController::class);
    Route::get('permissions', [RoleController::class, 'permissions']);

    // Bookings & Room Chart
    Route::get('housekeeping', [HousekeepingController::class, 'index']);
    Route::post('housekeeping/blocks/{roomStatusBlock}/start-cleaning', [HousekeepingController::class, 'startCleaning']);
    Route::post('housekeeping/blocks/{roomStatusBlock}/mark-cleaned', [HousekeepingController::class, 'markCleaned']);

    Route::get('bookings/chart', [BookingController::class, 'chart']);
    Route::get('bookings/summary', [BookingController::class, 'summary']);
    Route::post('bookings/{booking}/early-checkin', [BookingController::class, 'earlyCheckin']);
    Route::post('bookings/{booking}/late-checkout', [BookingController::class, 'lateCheckout']);
    Route::post('bookings/{booking}/extend', [BookingController::class, 'extendReservation']);
    Route::post('bookings/{booking}/extend-hours', [BookingController::class, 'extendHourlyReservation']);
    Route::get('bookings/{booking}/voucher', [BookingController::class, 'reservationVoucher']);
    Route::get('bookings/{booking}/billing', [BookingController::class, 'reservationBilling']);
    Route::post('bookings/{booking}/split-stay', [BookingController::class, 'splitStay']);
    Route::get('bookings/available-rooms', [BookingController::class, 'getAvailableRooms']);
    Route::post('booking-groups', [BookingController::class, 'storeGroup']);
    Route::apiResource('bookings', BookingController::class);

    // F&B Module (Restaurant Master)
    Route::apiResource('restaurant-masters', RestaurantMasterController::class);
    Route::post('restaurant-masters/{restaurantMaster}/logo', [RestaurantMasterController::class, 'uploadLogo']);

    // QZ Tray signing (for silent receipt printing)
    Route::get('qz/sign', [QzSignController::class, 'sign']);
    Route::get('qz/certificate', [QzSignController::class, 'certificate']);

    // Settings (receipt defaults)
    Route::get('settings/receipt', [SettingController::class, 'receiptDefaults']);
    Route::match(['put', 'post'], 'settings/receipt', [SettingController::class, 'updateReceiptDefaults']);

    // F&B Module (Table Master)
    Route::apiResource('table-categories', TableCategoryController::class);
    Route::apiResource('tables', TableController::class);
    Route::post('table-reservations/{tableReservation}/check-in', [TableReservationController::class, 'checkIn']);
    Route::post('table-reservations/{tableReservation}/complete', [TableReservationController::class, 'complete']);
    Route::post('table-reservations/{tableReservation}/cancel', [TableReservationController::class, 'cancel']);
    Route::apiResource('table-reservations', TableReservationController::class);

    // POS Module
    Route::get('pos/restaurants', [PosController::class, 'restaurants']);
    Route::get('pos/receipt-config/{restaurant}', [PosController::class, 'receiptConfig']);
    Route::get('pos/waiters', [PosController::class, 'waiters']);
    Route::get('pos/tables', [PosController::class, 'tables']);
    Route::get('pos/rooms', [PosController::class, 'rooms']);
    Route::get('pos/active-orders', [PosController::class, 'activeOrders']);
    Route::get('pos/menu', [PosController::class, 'menu']);
    Route::post('pos/orders', [PosController::class, 'openOrder']);
    Route::get('pos/orders/history', [PosController::class, 'orderHistory']);
    Route::get('pos/orders/{order}', [PosController::class, 'getOrder']);
    Route::patch('pos/orders/{order}', [PosController::class, 'updateOrder']);
    Route::post('pos/orders/{order}/transfer-table', [PosController::class, 'transferTable']);
    Route::put('pos/orders/{order}/items', [PosController::class, 'syncItems']);
    Route::post('pos/orders/{order}/items/void', [PosController::class, 'voidItems']);
    Route::post('pos/orders/{order}/kot', [PosController::class, 'sendKot']);
    Route::post('pos/orders/{order}/open-bill', [PosController::class, 'openBill']);
    Route::post('pos/orders/{order}/reopen', [PosController::class, 'reopen']);
    Route::post('pos/orders/{order}/settle', [PosController::class, 'settle']);
    Route::post('pos/orders/{order}/void', [PosController::class, 'void']);
    Route::post('pos/orders/{order}/refund', [PosController::class, 'refund']);

    // Day Closing
    Route::get('pos/day-closing/preview', [DayClosingController::class, 'preview']);
    Route::post('pos/day-closing', [DayClosingController::class, 'close']);
    Route::get('pos/day-closings', [DayClosingController::class, 'index']);

    // Kitchen Display
    Route::get('kitchen/display', [PosController::class, 'kitchenDisplay']);
    Route::patch('pos/orders/{order}/kitchen-status', [PosController::class, 'updateKitchenStatus']);
    Route::post('pos/orders/{order}/start-kot-prep', [PosController::class, 'startKotPrep']);
    Route::post('pos/orders/{order}/mark-batch-ready', [PosController::class, 'markBatchReady']);
    Route::post('pos/orders/{order}/mark-batch-delivered', [PosController::class, 'markBatchDelivered']);

    // F&B Module (Menu Configuration)
    Route::apiResource('menu-categories', MenuCategoryController::class);
    Route::apiResource('menu-sub-categories', MenuSubCategoryController::class);
    Route::apiResource('menu-items', MenuItemController::class);
    Route::get('menu-pricing', [MenuPricingController::class, 'index']);
    Route::put('menu-pricing/{menuItem}', [MenuPricingController::class, 'update']);
    Route::apiResource('menu-dietary-types', DietaryTypeController::class);
    Route::apiResource('menu-combos', ComboController::class);

    // BOM / Recipe Module
    Route::get('recipes', [RecipeController::class, 'index']);
    Route::put('recipes/menu-item/{menuItemId}', [RecipeController::class, 'upsert']);
    Route::post('recipes/{recipe}/produce', [RecipeController::class, 'produce']);
    Route::get('production-logs', [RecipeController::class, 'productionLogs']);
    Route::get('production-logs/{log}/details', [RecipeController::class, 'productionLogDetails']);

    // Inventory Module
    Route::get('inventory/stats', [\App\Http\Controllers\InventoryController::class, 'stats']);
    Route::post('inventory/issue', [\App\Http\Controllers\InventoryController::class, 'issue']);

    // Inventory Reports
    Route::get('inventory/reports/summary', [\App\Http\Controllers\InventoryReportController::class, 'dashboardSummary']);
    Route::get('inventory/reports/status', [\App\Http\Controllers\InventoryReportController::class, 'stockStatus']);
    Route::get('inventory/reports/reorder', [\App\Http\Controllers\InventoryReportController::class, 'reorderReport']);
    Route::get('inventory/reports/overstock', [\App\Http\Controllers\InventoryReportController::class, 'overstockReport']);
    Route::get('inventory/reports/slow-moving', [\App\Http\Controllers\InventoryReportController::class, 'slowMovingReport']);
    Route::get('inventory/reports/ledger', [\App\Http\Controllers\InventoryReportController::class, 'stockLedger']);
    Route::get('inventory/reports/consumption', [\App\Http\Controllers\InventoryReportController::class, 'consumption']);
    Route::get('inventory/reports/adjustments', [\App\Http\Controllers\InventoryReportController::class, 'adjustments']);
    Route::get('inventory/reports/purchase-history', [\App\Http\Controllers\InventoryReportController::class, 'purchaseHistory']);
    Route::apiResource('inventory/items', \App\Http\Controllers\InventoryController::class);
    Route::apiResource('inventory/categories', \App\Http\Controllers\InventoryCategoryController::class);
    Route::apiResource('inventory/uoms', \App\Http\Controllers\InventoryUomController::class);
    Route::apiResource('inventory/taxes', \App\Http\Controllers\InventoryTaxController::class);
    Route::apiResource('inventory/vendors', \App\Http\Controllers\VendorController::class);
    Route::apiResource('inventory/locations', \App\Http\Controllers\InventoryLocationController::class);
    Route::apiResource('inventory/store-requests', \App\Http\Controllers\StoreRequestController::class);
    Route::post('inventory/adjust-stock', [\App\Http\Controllers\InventoryController::class, 'adjustStock']);
    Route::post('inventory/store-requests/{storeRequest}/approve', [\App\Http\Controllers\StoreRequestController::class, 'approve']);
    Route::post('inventory/store-requests/{storeRequest}/issue', [\App\Http\Controllers\StoreRequestController::class, 'issue']);
    Route::post('inventory/store-requests/{storeRequest}/accept', [\App\Http\Controllers\StoreRequestController::class, 'accept']);
    Route::post('inventory/store-requests/{storeRequest}/recall-issue', [\App\Http\Controllers\StoreRequestController::class, 'recallIssue']);
    Route::post('inventory/store-requests/{storeRequest}/reject', [\App\Http\Controllers\StoreRequestController::class, 'reject']);
    Route::post('inventory/store-requests/{storeRequest}/cancel', [\App\Http\Controllers\StoreRequestController::class, 'cancel']);

    Route::apiResource('inventory/purchase-orders', \App\Http\Controllers\PurchaseOrderController::class);
    Route::post('inventory/purchase-orders/{purchaseOrder}/receive', [\App\Http\Controllers\PurchaseOrderController::class, 'receive']);
    Route::post('inventory/purchase-orders/{purchaseOrder}/pay', [\App\Http\Controllers\PurchaseOrderController::class, 'pay']);
    Route::apiResource('payment-methods', \App\Http\Controllers\PaymentMethodController::class);
    Route::get('inventory/movements', [\App\Http\Controllers\StockMovementController::class, 'index']);
});
