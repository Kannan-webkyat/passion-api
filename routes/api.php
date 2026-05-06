<?php

use App\Http\Controllers\{
    AuthController,
    BookingController,
    ComboController,
    DayClosingController,
    DepartmentController,
    DietaryTypeController,
    HousekeepingController,
    InventoryCategoryController,
    InventoryController,
    InventoryLocationController,
    InventoryReportController,
    InventoryTaxController,
    InventoryUomController,
    MenuCategoryController,
    MenuItemController,
    MenuPricingController,
    MenuSubCategoryController,
    PaymentMethodController,
    PosController,
    ProcurementRequisitionController,
    PurchaseOrderController,
    QzSignController,
    RecipeController,
    RestaurantMasterController,
    RoleController,
    RoomController,
    RoomStatusBlockController,
    RoomTypeController,
    SettingController,
    StockMovementController,
    StoreRequestController,
    TableCategoryController,
    TableController,
    TableReservationController,
    UserController,
    VendorController,
};
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

    Route::get('bookings/guest-search', [BookingController::class, 'guestSearch']);
    Route::get('bookings/chart', [BookingController::class, 'chart']);
    Route::get('bookings/summary', [BookingController::class, 'summary']);
    Route::post('bookings/{booking}/early-checkin', [BookingController::class, 'earlyCheckin']);
    Route::post('bookings/{booking}/late-checkout', [BookingController::class, 'lateCheckout']);
    Route::post('bookings/{booking}/extend', [BookingController::class, 'extendReservation']);
    Route::post('bookings/{booking}/extend-hours', [BookingController::class, 'extendHourlyReservation']);
    Route::get('bookings/{booking}/voucher', [BookingController::class, 'reservationVoucher']);
    Route::get('bookings/{booking}/billing', [BookingController::class, 'reservationBilling']);
    Route::get('bookings/{booking}/folio-postings', [BookingController::class, 'folioPostings']);
    Route::get('bookings/{booking}/folio-orders/{order}', [BookingController::class, 'folioOrderDetail'])->whereNumber('order');
    Route::post('bookings/{booking}/split-stay', [BookingController::class, 'splitStay']);
    Route::get('bookings/available-rooms', [BookingController::class, 'getAvailableRooms']);
    Route::post('booking-groups', [BookingController::class, 'storeGroup']);
    Route::apiResource('bookings', BookingController::class);

    // F&B Module (Restaurant Master)
    Route::apiResource('restaurant-masters', RestaurantMasterController::class);
    Route::post('restaurant-masters/{restaurantMaster}/logo', [RestaurantMasterController::class, 'uploadLogo']);

    // QZ Tray signing (for silent receipt printing)
    Route::prefix('qz')->group(function () {
        Route::get('sign', [QzSignController::class, 'sign']);
        Route::get('certificate', [QzSignController::class, 'certificate']);
    });

    // Settings (receipt defaults + company profile for procurement / accounts / reports)
    Route::get('settings/receipt', [SettingController::class, 'receiptDefaults']);
    Route::get('settings/company-profile', [SettingController::class, 'companyProfile']);
    Route::match(['put', 'post'], 'settings/receipt', [SettingController::class, 'updateReceiptDefaults']);
    Route::get('settings/global', [SettingController::class, 'globalConfig']);
    Route::put('settings/global', [SettingController::class, 'updateGlobalConfig']);
    Route::get('settings/invoice-profile', [SettingController::class, 'invoiceProfile']);
    Route::put('settings/invoice-profile', [SettingController::class, 'updateInvoiceProfile']);
    Route::get('settings/invoice-bank', [SettingController::class, 'invoiceBank']);
    Route::put('settings/invoice-bank', [SettingController::class, 'updateInvoiceBank']);

    // F&B Module (Table Master)
    Route::apiResource('table-categories', TableCategoryController::class);
    Route::apiResource('tables', TableController::class);
    Route::post('table-reservations/{tableReservation}/check-in', [TableReservationController::class, 'checkIn']);
    Route::post('table-reservations/{tableReservation}/complete', [TableReservationController::class, 'complete']);
    Route::post('table-reservations/{tableReservation}/cancel', [TableReservationController::class, 'cancel']);
    Route::apiResource('table-reservations', TableReservationController::class);

    // POS Module
    Route::prefix('pos')->group(function () {
        Route::get('restaurants', [PosController::class, 'restaurants']);
        Route::get('receipt-config/{restaurant}', [PosController::class, 'receiptConfig']);
        Route::get('waiters', [PosController::class, 'waiters']);
        Route::get('tables', [PosController::class, 'tables']);
        Route::get('rooms', [PosController::class, 'rooms']);
        Route::get('active-orders', [PosController::class, 'activeOrders']);
        Route::get('menu', [PosController::class, 'menu']);
        Route::post('orders', [PosController::class, 'openOrder']);
        Route::get('orders/history', [PosController::class, 'orderHistory']);
        Route::get('orders/{order}', [PosController::class, 'getOrder']);
        Route::patch('orders/{order}', [PosController::class, 'updateOrder']);
        Route::post('orders/{order}/transfer-table', [PosController::class, 'transferTable']);
        Route::post('orders/{order}/merge', [PosController::class, 'merge']);
        Route::put('orders/{order}/items', [PosController::class, 'syncItems']);
        Route::post('orders/{order}/items/void', [PosController::class, 'voidItems']);
        Route::post('orders/{order}/kot', [PosController::class, 'sendKot']);
        Route::post('orders/{order}/kot-hold-items', [PosController::class, 'setKotHoldItems']);
        Route::post('orders/{order}/kot-fire-items', [PosController::class, 'fireKotItems']);
        Route::post('orders/{order}/open-bill', [PosController::class, 'openBill']);
        Route::post('orders/{order}/reopen', [PosController::class, 'reopen']);
        Route::post('orders/{order}/settle', [PosController::class, 'settle']);
        Route::post('orders/{order}/void', [PosController::class, 'void']);
        Route::post('orders/{order}/refund', [PosController::class, 'refund']);
        Route::get('reports/sales', [PosController::class, 'salesReport']);
        Route::get('reports/sales/export', [PosController::class, 'salesReportExport']);
        Route::get('reports/sales/orders', [PosController::class, 'salesReportOrders']);
        Route::get('reports/liquor-sales', [PosController::class, 'liquorSalesReport']);
        Route::get('reports/liquor-sales/export', [PosController::class, 'liquorSalesExport']);
        Route::get('reports/food-sales', [PosController::class, 'foodSalesReport']);
        Route::get('reports/food-sales/export', [PosController::class, 'foodSalesExport']);
        Route::get('reports/order-type-mix/export', [PosController::class, 'orderTypeMixExport']);
        Route::get('reports/order-type-mix', [PosController::class, 'orderTypeMixReport']);
        Route::get('reports/menu-performance/export', [PosController::class, 'menuPerformanceExport']);
        Route::get('reports/menu-performance', [PosController::class, 'menuPerformanceReport']);
        Route::get('reports/tax-gst-summary/export', [PosController::class, 'taxGstSummaryExport']);
        Route::get('reports/tax-gst-summary', [PosController::class, 'taxGstSummaryReport']);
        Route::get('reports/refunds-adjustments/export', [PosController::class, 'refundsAdjustmentsExport']);
        Route::get('reports/refunds-adjustments', [PosController::class, 'refundsAdjustmentsReport']);
        Route::get('reports/voids-discounts/export', [PosController::class, 'voidsDiscountsExport']);
        Route::get('reports/voids-discounts', [PosController::class, 'voidsDiscountsReport']);
        Route::get('reports/b2b-sales/export', [PosController::class, 'b2bSalesReportExport']);

        // Day Closing
        Route::get('day-closing/preview', [DayClosingController::class, 'preview']);
        Route::post('day-closing', [DayClosingController::class, 'close']);
        Route::get('day-closings', [DayClosingController::class, 'index']);
        Route::get('day-closings/export', [DayClosingController::class, 'export']);
    });

    // Kitchen Display
    Route::get('kitchen/display', [PosController::class, 'kitchenDisplay']);

    Route::prefix('pos')->group(function () {
        Route::patch('orders/{order}/kitchen-status', [PosController::class, 'updateKitchenStatus']);
        Route::post('orders/{order}/start-kot-prep', [PosController::class, 'startKotPrep']);
        Route::post('orders/{order}/mark-batch-ready', [PosController::class, 'markBatchReady']);
        Route::post('orders/{order}/mark-order-item-ready', [PosController::class, 'markOrderItemReady']);
        Route::post('orders/{order}/mark-order-item-served', [PosController::class, 'markOrderItemServed']);
        Route::post('orders/{order}/mark-batch-delivered', [PosController::class, 'markBatchDelivered']);
    });

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
    Route::prefix('inventory')->group(function () {
        Route::get('stats', [InventoryController::class, 'stats']);
        Route::post('issue', [InventoryController::class, 'issue']);

        // Inventory Reports
        Route::get('reports/summary', [InventoryReportController::class, 'dashboardSummary']);
        Route::get('reports/status', [InventoryReportController::class, 'stockStatus']);
        Route::get('reports/reorder', [InventoryReportController::class, 'reorderReport']);
        Route::get('reports/overstock', [InventoryReportController::class, 'overstockReport']);
        Route::get('reports/slow-moving', [InventoryReportController::class, 'slowMovingReport']);
        Route::get('reports/ledger', [InventoryReportController::class, 'stockLedger']);
        Route::get('reports/consumption', [InventoryReportController::class, 'consumption']);
        Route::get('reports/adjustments', [InventoryReportController::class, 'adjustments']);
        Route::get('reports/purchase-history', [InventoryReportController::class, 'purchaseHistory']);
        Route::apiResource('items', InventoryController::class);
        Route::apiResource('categories', InventoryCategoryController::class);
        Route::apiResource('uoms', InventoryUomController::class);
        Route::apiResource('taxes', InventoryTaxController::class);
        Route::apiResource('vendors', VendorController::class);
        Route::apiResource('locations', InventoryLocationController::class);
        Route::apiResource('store-requests', StoreRequestController::class);
        Route::post('adjust-stock', [InventoryController::class, 'adjustStock']);
        Route::post('store-requests/{storeRequest}/approve', [StoreRequestController::class, 'approve']);
        Route::post('store-requests/{storeRequest}/issue', [StoreRequestController::class, 'issue']);
        Route::post('store-requests/{storeRequest}/accept', [StoreRequestController::class, 'accept']);
        Route::post('store-requests/{storeRequest}/recall-issue', [StoreRequestController::class, 'recallIssue']);
        Route::post('store-requests/{storeRequest}/reject', [StoreRequestController::class, 'reject']);
        Route::post('store-requests/{storeRequest}/cancel', [StoreRequestController::class, 'cancel']);

        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);
        Route::post('purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send']);
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
        Route::post('purchase-orders/{purchaseOrder}/pay', [PurchaseOrderController::class, 'pay']);

        Route::apiResource('procurement-requisitions', ProcurementRequisitionController::class);
        Route::post('procurement-requisitions/{procurement_requisition}/request-quotes', [ProcurementRequisitionController::class, 'requestQuotes']);
        Route::post('procurement-requisitions/{procurement_requisition}/start-comparison', [ProcurementRequisitionController::class, 'startComparison']);
        Route::get('procurement-requisitions/{procurement_requisition}/quote-slips', [ProcurementRequisitionController::class, 'quoteSlips']);
        Route::post('procurement-requisitions/{procurement_requisition}/generate-purchase-orders', [ProcurementRequisitionController::class, 'generatePurchaseOrders']);
        Route::delete('procurement-requisition-items/{procurement_requisition_item}/vendors/{vendor}', [ProcurementRequisitionController::class, 'removeVendor']);
        Route::patch('procurement-requisition-items/{procurement_requisition_item}/price', [ProcurementRequisitionController::class, 'updateItemPrice']);
    });

    Route::apiResource('payment-methods', PaymentMethodController::class);

    Route::prefix('inventory')->group(function () {
        Route::get('movements', [StockMovementController::class, 'index']);
    });
});
