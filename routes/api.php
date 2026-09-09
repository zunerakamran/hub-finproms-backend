<?php

use App\Http\Controllers\Api\AdvisorController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContentTypeController;
use App\Http\Controllers\Api\HubController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PowerAdminHubController;
use App\Http\Controllers\Api\PowerAdminPaymentMethodController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TagController;
use App\Http\Middleware\EnsureUserIsClientAdmin;
use App\Http\Middleware\EnsureUserIsPowerAdmin;
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

Route::get('/hub', [HubController::class, 'current']);
Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/types', [ContentTypeController::class, 'index']);
Route::get('/tags', [TagController::class, 'index']);
Route::get('/settings', [SettingController::class, 'publicIndex']);
Route::get('/posts/categories', [PostController::class, 'categories']);
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/subscription-plans/{plan}/checkout', [SubscriptionController::class, 'checkout']);
    Route::post('/subscriptions/confirm', [SubscriptionController::class, 'confirm']);
    Route::get('/my-subscriptions', [SubscriptionController::class, 'mySubscriptions']);

    Route::post('/posts/{post}/purchase', [PurchaseController::class, 'purchasePost']);
    Route::get('/my-purchases', [PurchaseController::class, 'myPurchases']);
    Route::get('/my-invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);

    // client_admin management API (also aliased under /admin for older clients)
    $clientAdminRoutes = function () {
        Route::post('/posts', [PostController::class, 'store']);
        Route::post('/posts/{post}', [PostController::class, 'update']);
        Route::put('/posts/{post}', [PostController::class, 'update']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/types', [ContentTypeController::class, 'store']);
        Route::put('/types/{contentType}', [ContentTypeController::class, 'update']);
        Route::delete('/types/{contentType}', [ContentTypeController::class, 'destroy']);

        Route::post('/tags', [TagController::class, 'store']);
        Route::put('/tags/{tag}', [TagController::class, 'update']);
        Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

        Route::get('/subscription-plans', [SubscriptionController::class, 'adminPlans']);
        Route::post('/subscription-plans', [SubscriptionController::class, 'storePlan']);
        Route::put('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
        Route::delete('/subscription-plans/{plan}', [SubscriptionController::class, 'destroyPlan']);

        Route::get('/settings', [SettingController::class, 'index']);
        Route::put('/settings', [SettingController::class, 'update']);

        Route::get('/advisors', [AdvisorController::class, 'index']);
        Route::post('/advisors/import', [AdvisorController::class, 'import']);
        Route::get('/advisors/template', [AdvisorController::class, 'template']);

        // TEMPORARY: bank transfer admin — remove with BANK_TRANSFER_ENABLED
        Route::get('/bank-transfers/pending', [SubscriptionController::class, 'pendingBankTransfers']);
        Route::post('/bank-transfers/{subscription}/confirm', [SubscriptionController::class, 'confirmBankTransfer']);
    };

    Route::middleware(EnsureUserIsClientAdmin::class)->prefix('client-admin')->group($clientAdminRoutes);
    Route::middleware(EnsureUserIsClientAdmin::class)->prefix('admin')->group($clientAdminRoutes);

    // power_admin control plane (hubs / checklist / platform settings)
    Route::middleware(EnsureUserIsPowerAdmin::class)->prefix('power-admin')->group(function () {
        Route::get('/ping', fn () => response()->json([
            'ok' => true,
            'role' => 'power_admin',
        ]));

        Route::get('/payment-methods', [PowerAdminPaymentMethodController::class, 'index']);
        Route::put('/payment-methods', [PowerAdminPaymentMethodController::class, 'update']);

        Route::get('/hubs', [PowerAdminHubController::class, 'index']);
        Route::post('/hubs', [PowerAdminHubController::class, 'store']);
        Route::get('/hubs/{hub}', [PowerAdminHubController::class, 'show']);
        Route::put('/hubs/{hub}', [PowerAdminHubController::class, 'update']);
        Route::put('/hubs/{hub}/checklist', [PowerAdminHubController::class, 'updateChecklist']);
    });
});
