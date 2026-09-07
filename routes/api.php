<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TagController;
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
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/tags', [TagController::class, 'index']);
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

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/tags', [TagController::class, 'store']);
        Route::put('/tags/{tag}', [TagController::class, 'update']);
        Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

        Route::get('/subscription-plans', [SubscriptionController::class, 'adminPlans']);
        Route::post('/subscription-plans', [SubscriptionController::class, 'storePlan']);
        Route::put('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
        Route::delete('/subscription-plans/{plan}', [SubscriptionController::class, 'destroyPlan']);

        // TEMPORARY: bank transfer admin — remove with BANK_TRANSFER_ENABLED
        Route::get('/bank-transfers/pending', [SubscriptionController::class, 'pendingBankTransfers']);
        Route::post('/bank-transfers/{subscription}/confirm', [SubscriptionController::class, 'confirmBankTransfer']);
    });
});