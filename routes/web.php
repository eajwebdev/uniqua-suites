<?php

use App\Http\Controllers\Admin\AdvanceController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CameraRateController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\ClosureController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\FileController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\GuestController;
use App\Http\Controllers\Admin\HousekeepingController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\ParkingController;
use App\Http\Controllers\Admin\QuoteController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GrabCamController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:public')->group(function () {
    Route::get('/', [PublicSiteController::class, 'home']);
    Route::get('/suites', [PublicSiteController::class, 'suites']);
    Route::get('/suites/{slug}', [PublicSiteController::class, 'show']);
    Route::get('/suites/{slug}/check-dates', [PublicSiteController::class, 'checkDates']);
    Route::get('/policies', [PublicSiteController::class, 'policies']);
    Route::get('/sitemap.xml', [PublicSiteController::class, 'sitemap']);
    Route::get('/grabcam', [GrabCamController::class, 'index']);
    Route::get('/grabcam/{slug}/check-dates', [GrabCamController::class, 'checkDates']);
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/login/demo/{role}', [AuthController::class, 'demoLogin'])->middleware('throttle:login');
    Route::get('/forgot-password', [AuthController::class, 'forgotForm'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:password-reset');
    Route::get('/reset-password/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:password-reset');
    Route::get('/two-factor', [TwoFactorController::class, 'form']);
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:two-factor');
});

// auth.session ends a person's other sessions when their password changes.
Route::middleware(['auth', 'auth.session', 'active', 'subscription', 'password.changed', 'mfa.enrolled', \App\Http\Middleware\RememberBusiness::class])->prefix('admin')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/locked', [\App\Http\Controllers\Admin\SubscriptionController::class, 'locked']);
    // Owner and Administrator see it; only the Administrator changes it (checked in the controller's routes below).
    Route::get('/subscription', [\App\Http\Controllers\Admin\SubscriptionController::class, 'index'])->middleware('role:Owner|Operations Manager|Administrator');
    Route::post('/subscription', [\App\Http\Controllers\Admin\SubscriptionController::class, 'update'])->middleware('role:Administrator');
    Route::post('/subscription/bills/{bill}/pay', [\App\Http\Controllers\Admin\SubscriptionController::class, 'pay'])->middleware('role:Administrator');
    Route::get('/account', [AuthController::class, 'account']);
    Route::post('/account/password', [AuthController::class, 'updatePassword'])->middleware('throttle:6,1');
    Route::middleware('throttle:two-factor')->group(function () {
        Route::post('/account/two-factor/setup', [TwoFactorController::class, 'setup']);
        Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        Route::post('/account/two-factor/disable', [TwoFactorController::class, 'disable']);
    });
    Route::post('/account/notifications', [AuthController::class, 'updateNotificationPrefs']);
    Route::post('/notifications/read', [AuthController::class, 'readNotifications']);

    Route::post('/category', [CategoryController::class, 'update']);

    Route::get('/', [DashboardController::class, 'index']);
    Route::get('/calendar', [CalendarController::class, 'index'])->middleware('perm:bookings.view');

    Route::middleware('perm:bookings.view')->group(function () {
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show'])->whereNumber('booking');
    });
    Route::middleware('perm:bookings.manage')->group(function () {
        Route::get('/bookings/create', [BookingController::class, 'create']);
        Route::get('/bookings/form-options', [BookingController::class, 'formOptions']);
        Route::get('/bookings/availability', [BookingController::class, 'availability']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::post('/bookings/{booking}/hold', [BookingController::class, 'hold']);
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('/bookings/{booking}/no-show', [BookingController::class, 'noShow']);
        Route::post('/bookings/{booking}/check-in', [BookingController::class, 'checkIn']);
        Route::post('/bookings/{booking}/check-out', [BookingController::class, 'checkOut']);
        Route::post('/bookings/{booking}/amend', [BookingController::class, 'amend']);
        Route::post('/bookings/{booking}/details', [BookingController::class, 'details']);
        Route::post('/bookings/{booking}/charges', [BookingController::class, 'addCharge']);
        Route::post('/bookings/{booking}/rental-security', [BookingController::class, 'rentalSecurity']);
        Route::post('/charges/{charge}/void', [BookingController::class, 'voidCharge']);
    });
    Route::post('/bookings/{booking}/confirm', [BookingController::class, 'confirm'])->middleware('perm:bookings.confirm');

    Route::get('/guests', [GuestController::class, 'index'])->middleware('perm:guests.view');
    Route::get('/guests/{guest}', [GuestController::class, 'show'])->middleware('perm:guests.view');
    Route::post('/guests', [GuestController::class, 'store'])->middleware('perm:guests.manage');
    Route::post('/guests/{guest}', [GuestController::class, 'update'])->middleware('perm:guests.manage');

    // FAQ: ready answers to paste in Messenger. The same people who send quotes and messages.
    Route::middleware('perm:quotes.manage')->group(function () {
        Route::get('/faqs', [FaqController::class, 'index']);
        Route::post('/faqs', [FaqController::class, 'store']);
        Route::post('/faqs/{faq}', [FaqController::class, 'update']);
        Route::post('/faqs/{faq}/delete', [FaqController::class, 'destroy']);
        Route::post('/faqs/{faq}/copied', [FaqController::class, 'copied']);
    });

    Route::middleware('perm:quotes.manage')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index']);
        Route::get('/quotes/create', [QuoteController::class, 'create']);
        Route::post('/quotes', [QuoteController::class, 'store']);
        Route::get('/quotes/check', [QuoteController::class, 'check']);
        Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
        Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf']);
    });

    Route::get('/rooms', [RoomController::class, 'index'])->middleware('perm:rooms.view');
    Route::get('/camera-rates', [CameraRateController::class, 'index'])->middleware('perm:rooms.view');
    Route::post('/camera-rates/free-day', [CameraRateController::class, 'freeDay'])->middleware('perm:rates.manage');
    Route::post('/camera-models', [CameraRateController::class, 'storeModel'])->middleware('perm:rates.manage');
    Route::post('/cameras', [RoomController::class, 'storeCamera'])->middleware('perm:rooms.manage');
    Route::post('/camera-rates/{type}', [CameraRateController::class, 'update'])->whereNumber('type')->middleware('perm:rates.manage');
    Route::middleware('perm:rooms.manage')->group(function () {
        Route::get('/rooms/{room}/edit', [RoomController::class, 'edit']);
        Route::post('/rooms/{room}', [RoomController::class, 'update']);
        Route::post('/rooms/{room}/approve', [RoomController::class, 'approve']);
        Route::post('/rooms/{room}/publish', [RoomController::class, 'togglePublish']);
        Route::post('/rooms/{room}/images', [RoomController::class, 'addImage']);
        Route::post('/room-images/{image}/delete', [RoomController::class, 'deleteImage']);
    });
    Route::post('/rooms/{room}/rates', [RoomController::class, 'addRate'])->middleware('perm:rates.manage');
    Route::post('/rooms/{room}/overrides', [RoomController::class, 'addOverride'])->middleware('perm:rates.manage');
    Route::post('/rooms/{room}/blocks', [RoomController::class, 'addBlock'])->middleware('perm:bookings.manage');
    Route::post('/blocks/{block}/release', [RoomController::class, 'releaseBlock'])->middleware('perm:bookings.manage');
    Route::post('/closures', [ClosureController::class, 'store'])->middleware('perm:settings.manage');
    Route::post('/closures/{group}/reopen', [ClosureController::class, 'reopen'])->whereUuid('group')->middleware('perm:settings.manage');
    Route::get('/rooms/{room}/secret', [RoomController::class, 'secret'])->middleware('perm:secrets.reveal');
    Route::post('/rooms/{room}/secret', [RoomController::class, 'saveSecret'])->middleware('perm:secrets.manage');

    Route::middleware('perm:payments.view')->group(function () {
        Route::get('/payments', [FinanceController::class, 'receipts']);
        Route::get('/settlements', [FinanceController::class, 'settlements']);
        Route::get('/reconciliation', [FinanceController::class, 'reconciliation']);
    });
    Route::post('/receipts', [FinanceController::class, 'storeReceipt'])->middleware('perm:payments.record');
    Route::post('/receipts/{receipt}/review', [FinanceController::class, 'reviewReceipt'])->middleware('perm:payments.verify');
    Route::post('/receipts/{receipt}/reverse', [FinanceController::class, 'reverseReceipt'])->middleware('perm:payments.verify');
    Route::post('/refunds', [FinanceController::class, 'storeRefund'])->middleware('perm:payments.record');
    Route::post('/refunds/{refund}/decide', [FinanceController::class, 'decideRefund'])->middleware('perm:refunds.approve');
    Route::post('/settlements', [FinanceController::class, 'storeSettlement'])->middleware('perm:settlements.manage');
    Route::post('/settlements/{settlement}/payout', [FinanceController::class, 'recordPayout'])->middleware('perm:settlements.manage');
    Route::post('/periods/lock', [FinanceController::class, 'lockPeriod'])->middleware('perm:periods.lock');

    Route::get('/expenses', [ExpenseController::class, 'index'])->middleware('perm:expenses.view');
    Route::get('/cashbook', [ExpenseController::class, 'cashbook'])->middleware('perm:expenses.view');
    Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('perm:expenses.manage');
    Route::post('/expenses/{expense}/paid', [ExpenseController::class, 'markPaid'])->middleware('perm:expenses.manage');
    Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('perm:expenses.approve');
    Route::post('/expenses/{expense}/reverse', [ExpenseController::class, 'reverse'])->middleware('perm:expenses.manage');

    Route::get('/reports', [ReportController::class, 'index'])->middleware('perm:reports.view');
    Route::get('/reports/export/{type}/{format}', [ReportController::class, 'export'])->middleware('perm:reports.export');

    Route::get('/housekeeping', [HousekeepingController::class, 'index'])->middleware('perm:housekeeping.own|housekeeping.manage|housekeeping.inspect');
    Route::middleware('perm:housekeeping.own|housekeeping.manage')->group(function () {
        Route::post('/tasks/{task}/start', [HousekeepingController::class, 'start']);
        Route::post('/tasks/{task}/complete', [HousekeepingController::class, 'complete']);
        Route::post('/tasks/{task}/photos', [HousekeepingController::class, 'addPhotos']);
    });
    Route::post('/tasks/auto-assign', [HousekeepingController::class, 'autoAssign'])->middleware('perm:housekeeping.manage');
    Route::post('/tasks/plan', [HousekeepingController::class, 'plan'])->middleware('perm:housekeeping.manage');
    Route::post('/tasks', [HousekeepingController::class, 'store'])->middleware('perm:housekeeping.manage');
    Route::post('/tasks/{task}/cancel', [HousekeepingController::class, 'cancel'])->middleware('perm:housekeeping.manage');
    Route::post('/tasks/{task}/assign', [HousekeepingController::class, 'assign'])->middleware('perm:housekeeping.manage');
    Route::post('/tasks/{task}/inspect', [HousekeepingController::class, 'inspect'])->middleware('perm:housekeeping.inspect');
    Route::get('/laundry', [HousekeepingController::class, 'laundry'])->middleware('perm:housekeeping.manage');
    Route::post('/laundry/{job}', [HousekeepingController::class, 'updateLaundry'])->middleware('perm:housekeeping.manage');

    Route::middleware('perm:maintenance.own|maintenance.manage')->group(function () {
        Route::get('/work-orders', [MaintenanceController::class, 'workOrders']);
        Route::post('/work-orders/{order}/status', [MaintenanceController::class, 'updateWorkOrder']);
    });
    Route::post('/work-orders', [MaintenanceController::class, 'storeWorkOrder'])->middleware('perm:maintenance.manage');
    Route::get('/incidents', [MaintenanceController::class, 'incidents'])->middleware('perm:incidents.view');
    Route::get('/incidents/{incident}', [MaintenanceController::class, 'showIncident'])->middleware('perm:incidents.view');
    Route::post('/incidents/{incident}/notes', [MaintenanceController::class, 'storeIncidentNote'])->middleware('perm:incidents.manage');
    Route::post('/incidents', [MaintenanceController::class, 'storeIncident'])->middleware('perm:incidents.manage');
    Route::post('/incidents/{incident}/status', [MaintenanceController::class, 'updateIncident'])->middleware('perm:incidents.manage');
    Route::post('/incidents/{incident}/decide', [MaintenanceController::class, 'decideIncident'])->middleware('perm:incidents.approve');

    // One warehouse: purchases in, stock-outs to suites (cleaners recording supplies count as stock-outs too).
    Route::get('/inventory', [InventoryController::class, 'index'])->middleware('perm:inventory.view|inventory.consume');
    Route::post('/inventory/purchases', [InventoryController::class, 'purchase'])->middleware('perm:inventory.manage');
    Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut'])->middleware('perm:inventory.manage|inventory.consume');
    Route::post('/inventory/items', [InventoryController::class, 'storeItem'])->middleware('perm:inventory.manage');
    Route::post('/inventory/items/{item}', [InventoryController::class, 'updateItem'])->middleware('perm:inventory.manage');
    Route::post('/inventory/items/{item}/count', [InventoryController::class, 'count'])->middleware('perm:inventory.manage');

    Route::get('/parking', [ParkingController::class, 'index'])->middleware('perm:parking.view');
    Route::get('/parking/calendar', [ParkingController::class, 'calendar'])->middleware('perm:parking.view');
    Route::middleware('perm:parking.manage')->group(function () {
        Route::post('/parking/spaces', [ParkingController::class, 'storeSpace']);
        Route::post('/parking/assignments', [ParkingController::class, 'storeAssignment']);
        Route::post('/parking/assignments/{assignment}/cancel', [ParkingController::class, 'cancelAssignment']);
    });

    Route::middleware('perm:advances.manage')->group(function () {
        Route::get('/advances', [AdvanceController::class, 'index']);
        Route::post('/advances/employees', [AdvanceController::class, 'storeEmployee']);
        Route::post('/advances', [AdvanceController::class, 'storeAdvance']);
        Route::post('/advances/repayments', [AdvanceController::class, 'storeRepayment']);
    });

    Route::get('/team', [TeamController::class, 'index'])->middleware('perm:team.manage');
    Route::get('/team/create', [TeamController::class, 'create'])->middleware('perm:team.manage');
    Route::post('/team', [TeamController::class, 'store'])->middleware('perm:team.manage');
    Route::get('/team/{user}/edit', [TeamController::class, 'edit'])->middleware('perm:team.manage');
    Route::post('/team/{user}', [TeamController::class, 'update'])->middleware('perm:team.manage');
    Route::post('/team/{user}/reset-two-factor', [TeamController::class, 'resetTwoFactor'])->middleware('perm:team.manage');
    Route::post('/team/{user}/temporary-password', [TeamController::class, 'temporaryPassword'])->middleware('perm:team.manage');

    Route::middleware('perm:settings.manage')->group(function () {
        Route::get('/imports', [ImportController::class, 'index']);
        Route::post('/imports', [ImportController::class, 'store']);
        Route::get('/imports/{batch}', [ImportController::class, 'show']);
        Route::post('/imports/{batch}/commit', [ImportController::class, 'commit']);
        Route::post('/imports/{batch}/hold-future', [ImportController::class, 'holdFutureDates']);
        Route::post('/imports/{batch}/rollback', [ImportController::class, 'rollback']);
        Route::post('/imports/mappings/{mapping}', [ImportController::class, 'decideMapping']);
        Route::post('/imports/rows/{row}/review', [ImportController::class, 'reviewRow']);
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings', [SettingsController::class, 'update']);
        Route::post('/settings/templates', [SettingsController::class, 'storeTemplate']);
        Route::post('/settings/templates/{template}', [SettingsController::class, 'updateTemplate']);
        Route::post('/settings/templates/{template}/delete', [SettingsController::class, 'destroyTemplate']);
        Route::post('/settings/taxes', [SettingsController::class, 'storeTax']);
        Route::post('/settings/taxes/{rule}/end', [SettingsController::class, 'endTax']);
    });
    Route::get('/audit', [SettingsController::class, 'audit'])->middleware('perm:audit.view');

    Route::get('/files/{kind}/{id}', [FileController::class, 'show']);
});
