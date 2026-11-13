<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PromoController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:3,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::get('courses', [CourseController::class, 'index']);
Route::get('courses/{course}', [CourseController::class, 'show']);

// Called by Midtrans, so it cannot use token auth; it is protected by the payload signature instead.
Route::post('payments/midtrans/notification', [PaymentWebhookController::class, 'handle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('courses/{course}/materials', [CourseController::class, 'materials']);
    Route::get('courses/{course}/tryouts', [CourseController::class, 'tryouts']);

    Route::post('promos/validate', [PromoController::class, 'validateCode']);

    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order:order_code}', [OrderController::class, 'show']);

    if (app()->environment('local')) {
        Route::post('dev/orders/{order:order_code}/simulate-paid', [OrderController::class, 'simulatePaid']);
    }
});
