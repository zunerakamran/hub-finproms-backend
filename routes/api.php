<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::post('/stripe/webhook', StripeWebhookController::class);

Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);
Route::get('/posts/categories', [PostController::class, 'categories']);
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/subscription-plans/{plan}/checkout', [SubscriptionController::class, 'checkout']);
    Route::post('/subscriptions/confirm', [SubscriptionController::class, 'confirm']);
    Route::get('/my-subscriptions', [SubscriptionController::class, 'mySubscriptions']);

    Route::post('/posts/{post}/purchase', [PurchaseController::class, 'purchasePost']);
    Route::get('/my-purchases', [PurchaseController::class, 'myPurchases']);

    Route::middleware(EnsureUserIsAdmin::class)->prefix('admin')->group(function () {
        Route::post('/posts', [PostController::class, 'store']);
        Route::post('/posts/{post}', [PostController::class, 'update']);
        Route::put('/posts/{post}', [PostController::class, 'update']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);
    });
});
