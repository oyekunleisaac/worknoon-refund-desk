<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\RefundRequestController;
use App\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public (customer) API
|--------------------------------------------------------------------------
*/
Route::get('health', [SupportController::class, 'health']);
Route::get('policy', [SupportController::class, 'policy']);
Route::get('demo/scenarios', [SupportController::class, 'scenarios']);

Route::post('orders/lookup', [RefundRequestController::class, 'lookup'])->middleware('throttle:lookup');
Route::post('refund-requests', [RefundRequestController::class, 'store'])->middleware('throttle:refunds');
Route::get('refund-requests/{reference}', [RefundRequestController::class, 'show'])->middleware('throttle:lookup');

/*
|--------------------------------------------------------------------------
| Support dashboard API (bearer token)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['admin', 'throttle:admin'])->group(function () {
    Route::get('stats', [Admin\RefundRequestController::class, 'stats']);
    Route::get('refund-requests', [Admin\RefundRequestController::class, 'index']);
    Route::get('refund-requests/{refundRequest}', [Admin\RefundRequestController::class, 'show']);
    Route::post('refund-requests/{refundRequest}/review', [Admin\RefundRequestController::class, 'review']);
    Route::get('customers', [Admin\CustomerController::class, 'index']);
    Route::post('demo/reset', [Admin\DemoController::class, 'reset']);
});
