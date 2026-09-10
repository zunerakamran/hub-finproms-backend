<?php

use App\Http\Controllers\Api\AdvisorBillingController;
use App\Http\Controllers\Api\AdvisorController;
use App\Http\Controllers\Api\AdvisorPaymentCardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContentTypeController;
use App\Http\Controllers\Api\HubController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PowerAdminAdvisorPricingController;
use App\Http\Controllers\Api\PowerAdminCapabilityController;
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
Route::get('/settings', [SettingController::class, 'publicIndex']);

Route::middleware('hub_can:member_browse_catalog')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/types', [ContentTypeController::class, 'index']);
    Route::get('/tags', [TagController::class, 'index']);
    Route::get('/posts/categories', [PostController::class, 'categories']);
    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/{post}', [PostController::class, 'show']);
});

Route::middleware('hub_can:member_view_plans')->group(function () {
    Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('hub_can:member_view_plans')->group(function () {
        Route::post('/subscription-plans/{plan}/checkout', [SubscriptionController::class, 'checkout']);
        Route::post('/subscriptions/confirm', [SubscriptionController::class, 'confirm']);
        Route::get('/my-subscriptions', [SubscriptionController::class, 'mySubscriptions']);
    });

    Route::middleware('hub_can:member_purchase_content')->group(function () {
        Route::post('/posts/{post}/purchase', [PurchaseController::class, 'purchasePost']);
    });

    Route::middleware('hub_can:member_view_purchases')->group(function () {
        Route::get('/my-purchases', [PurchaseController::class, 'myPurchases']);
    });

    Route::middleware('hub_can:member_view_invoices')->group(function () {
        Route::get('/my-invoices', [InvoiceController::class, 'index']);
    });

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);

    // client_admin management API (also aliased under /admin for older clients)
    $clientAdminRoutes = function () {
        Route::middleware('hub_can:dashboard_manage_posts')->group(function () {
            Route::post('/posts', [PostController::class, 'store']);
            Route::post('/posts/{post}', [PostController::class, 'update']);
            Route::put('/posts/{post}', [PostController::class, 'update']);
            Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_categories')->group(function () {
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_types')->group(function () {
            Route::post('/types', [ContentTypeController::class, 'store']);
            Route::put('/types/{contentType}', [ContentTypeController::class, 'update']);
            Route::delete('/types/{contentType}', [ContentTypeController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_tags')->group(function () {
            Route::post('/tags', [TagController::class, 'store']);
            Route::put('/tags/{tag}', [TagController::class, 'update']);
            Route::delete('/tags/{tag}', [TagController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_plans')->group(function () {
            Route::get('/subscription-plans', [SubscriptionController::class, 'adminPlans']);
            Route::post('/subscription-plans', [SubscriptionController::class, 'storePlan']);
            Route::put('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
            Route::delete('/subscription-plans/{plan}', [SubscriptionController::class, 'destroyPlan']);
        });

        Route::middleware('hub_can:dashboard_manage_settings')->group(function () {
            Route::get('/settings', [SettingController::class, 'index']);
            Route::put('/settings', [SettingController::class, 'update']);
        });

        Route::get('/advisors', [AdvisorController::class, 'index']);

        Route::middleware('hub_can:advisor_excel_import')->group(function () {
            Route::post('/advisors/import', [AdvisorController::class, 'import']);
            Route::get('/advisors/template', [AdvisorController::class, 'template']);
            Route::get('/advisor-billings/{billing}', [AdvisorBillingController::class, 'show']);
            Route::post('/advisor-billings/{billing}/checkout', [AdvisorBillingController::class, 'checkout']);
            Route::post('/advisor-billings/confirm', [AdvisorBillingController::class, 'confirm']);
            Route::post('/advisor-billings/{billing}/confirm-bank-transfer', [AdvisorBillingController::class, 'confirmBankTransfer']);
        });

        Route::middleware('hub_can:advisor_discontinue')->group(function () {
            Route::post('/advisors/{advisor}/discontinue', [AdvisorController::class, 'discontinue']);
        });

        Route::middleware('hub_can:dashboard_view_advisor_invoices')->group(function () {
            Route::get('/advisor-invoices', [AdvisorBillingController::class, 'invoices']);
        });

        Route::middleware('hub_can:dashboard_manage_advisor_renewal')->group(function () {
            Route::get('/advisor-billing-renewal', [AdvisorBillingController::class, 'renewalSettings']);
            Route::put('/advisor-billing-renewal', [AdvisorBillingController::class, 'updateRenewalSettings']);
        });

        Route::middleware('hub_can:dashboard_manage_advisor_pricing')->group(function () {
            Route::get('/advisor-pricing', [PowerAdminAdvisorPricingController::class, 'index']);
            Route::post('/advisor-pricing', [PowerAdminAdvisorPricingController::class, 'store']);
            Route::put('/advisor-pricing/{tier}', [PowerAdminAdvisorPricingController::class, 'update']);
            Route::delete('/advisor-pricing/{tier}', [PowerAdminAdvisorPricingController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_bank_transfers')->group(function () {
            Route::get('/bank-transfers/pending', [SubscriptionController::class, 'pendingBankTransfers']);
            Route::post('/bank-transfers/{subscription}/confirm', [SubscriptionController::class, 'confirmBankTransfer']);
        });

        // Card settings — always available to hub admins when advisor billing is on
        // (not gated by the capabilities matrix).
        Route::get('/payment-card', [AdvisorPaymentCardController::class, 'show']);
        Route::post('/payment-card/setup', [AdvisorPaymentCardController::class, 'setup']);
        Route::post('/payment-card/confirm', [AdvisorPaymentCardController::class, 'confirm']);
    };

    Route::middleware(EnsureUserIsClientAdmin::class)->prefix('client-admin')->group($clientAdminRoutes);
    Route::middleware(EnsureUserIsClientAdmin::class)->prefix('admin')->group($clientAdminRoutes);

    // power_admin control plane (gated by Power Admin capabilities — section 5.3)
    Route::middleware(EnsureUserIsPowerAdmin::class)->prefix('power-admin')->group(function () {
        Route::get('/ping', fn () => response()->json([
            'ok' => true,
            'role' => 'power_admin',
        ]));

        // Any power_admin can read their resolved capabilities (for nav gating)
        Route::get('/capabilities/me', [PowerAdminCapabilityController::class, 'index']);

        // Role × capability matrix (all roles including Power Admin)
        Route::get('/capabilities/matrix', [PowerAdminCapabilityController::class, 'matrix']);

        Route::middleware('pa_can:pa_manage_power_capabilities')->group(function () {
            Route::put('/capabilities', [PowerAdminCapabilityController::class, 'update']);
            Route::put('/capabilities/matrix', [PowerAdminCapabilityController::class, 'updateMatrix']);
        });

        Route::middleware('pa_can:pa_manage_payment_methods')->group(function () {
            Route::get('/payment-methods', [PowerAdminPaymentMethodController::class, 'index']);
            Route::put('/payment-methods', [PowerAdminPaymentMethodController::class, 'update']);
        });

        // Read hubs if managing hubs or checklists; writes split by capability
        Route::get('/hubs', [PowerAdminHubController::class, 'index']);
        Route::get('/hubs/{hub}', [PowerAdminHubController::class, 'show']);

        Route::middleware('pa_can:pa_manage_hubs')->group(function () {
            Route::post('/hubs', [PowerAdminHubController::class, 'store']);
            Route::put('/hubs/{hub}', [PowerAdminHubController::class, 'update']);
        });

        Route::middleware('pa_can:pa_manage_hub_checklists')->group(function () {
            Route::put('/hubs/{hub}/checklist', [PowerAdminHubController::class, 'updateChecklist']);
        });

        // Advisor import / billing when enabled for power_admin on the current hub
        Route::get('/advisors', [AdvisorController::class, 'index']);

        Route::middleware('hub_can:advisor_excel_import')->group(function () {
            Route::post('/advisors/import', [AdvisorController::class, 'import']);
            Route::get('/advisors/template', [AdvisorController::class, 'template']);
            Route::get('/advisor-billings/{billing}', [AdvisorBillingController::class, 'show']);
            Route::post('/advisor-billings/{billing}/checkout', [AdvisorBillingController::class, 'checkout']);
            Route::post('/advisor-billings/confirm', [AdvisorBillingController::class, 'confirm']);
            Route::post('/advisor-billings/{billing}/confirm-bank-transfer', [AdvisorBillingController::class, 'confirmBankTransfer']);
        });

        Route::middleware('hub_can:advisor_discontinue')->group(function () {
            Route::post('/advisors/{advisor}/discontinue', [AdvisorController::class, 'discontinue']);
        });

        Route::middleware('hub_can:dashboard_view_advisor_invoices')->group(function () {
            Route::get('/advisor-invoices', [AdvisorBillingController::class, 'invoices']);
        });

        Route::middleware('hub_can:dashboard_manage_advisor_renewal')->group(function () {
            Route::get('/advisor-billing-renewal', [AdvisorBillingController::class, 'renewalSettings']);
            Route::put('/advisor-billing-renewal', [AdvisorBillingController::class, 'updateRenewalSettings']);
        });

        Route::middleware('hub_can:dashboard_manage_advisor_pricing')->group(function () {
            Route::get('/advisor-pricing', [PowerAdminAdvisorPricingController::class, 'index']);
            Route::post('/advisor-pricing', [PowerAdminAdvisorPricingController::class, 'store']);
            Route::put('/advisor-pricing/{tier}', [PowerAdminAdvisorPricingController::class, 'update']);
            Route::delete('/advisor-pricing/{tier}', [PowerAdminAdvisorPricingController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_plans')->group(function () {
            Route::get('/subscription-plans', [SubscriptionController::class, 'adminPlans']);
            Route::post('/subscription-plans', [SubscriptionController::class, 'storePlan']);
            Route::put('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
            Route::delete('/subscription-plans/{plan}', [SubscriptionController::class, 'destroyPlan']);
        });
    });
});
