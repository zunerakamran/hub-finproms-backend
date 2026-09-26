<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\ActingAdvisorController;
use App\Http\Controllers\Api\ActingHubController;
use App\Http\Controllers\Api\AdvisorBillingController;
use App\Http\Controllers\Api\AdvisorController;
use App\Http\Controllers\Api\AdvisorPaymentCardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BundleController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\FirmController;
use App\Http\Controllers\Api\SocialMediaComplianceController;
use App\Http\Controllers\Api\GeneralComplianceController;
use App\Http\Controllers\Api\WebsiteCompliance\ChangeRequestController as WcChangeRequestController;
use App\Http\Controllers\Api\WebsiteCompliance\PageController as WcPageController;
use App\Http\Controllers\Api\WebsiteCompliance\PublicController as WcPublicController;
use App\Http\Controllers\Api\WebsiteCompliance\ReportController as WcReportController;
use App\Http\Controllers\Api\WebsiteCompliance\SchedulerController as WcSchedulerController;
use App\Http\Controllers\Api\WebsiteCompliance\SectionController as WcSectionController;
use App\Http\Controllers\Api\WebsiteCompliance\TemplateController as WcTemplateController;
use App\Http\Controllers\Api\WebsiteCompliance\TemplateRequestController as WcTemplateRequestController;
use App\Http\Controllers\Api\WebsiteCompliance\UploadController as WcUploadController;
use App\Http\Controllers\Api\WebsiteCompliance\WebsiteComplianceAdvisorController;
use App\Http\Controllers\Api\ContentPushController;
use App\Http\Controllers\Api\ContentTypeController;
use App\Http\Controllers\Api\HubContentController;
use App\Http\Controllers\Api\HubController;
use App\Http\Controllers\Api\HubModulesController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MyDashboardController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PowerAdminAdvisorPricingController;
use App\Http\Controllers\Api\PowerAdminCapabilityController;
use App\Http\Controllers\Api\PowerAdminHubController;
use App\Http\Controllers\Api\PowerAdminPaymentMethodController;
use App\Http\Controllers\Api\PowerAdminUserController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\RoleDisplayNameController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\ComplianceStatusDisplayNameController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SubscriberCreditsController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\PublicStorageController;
use App\Http\Middleware\EnsureUserIsClientAdmin;
use App\Http\Middleware\EnsureUserIsPowerAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::post('/stripe/webhook', StripeWebhookController::class);

Route::get('/hub', [HubController::class, 'current']);
Route::get('/settings', [SettingController::class, 'publicIndex']);

// Public uploads (logos/favicons) — works without public/storage symlink
Route::get('/media/{path}', [PublicStorageController::class, 'show'])
    ->where('path', '.*');

// Website Compliance — public endpoints for live templates / scheduler cron
Route::get('/website-compliance/public/pages', [WcPublicController::class, 'getAllPages']);
Route::get('/website-compliance/public/pages/{slug}', [WcPublicController::class, 'getPage']);
Route::get('/website-compliance/public/templates/{slug}', [WcPublicController::class, 'getTemplateShowcase']);
Route::get('/website-compliance/pages/home', [WcPublicController::class, 'getHomePageByAdvisor']);
Route::get('/website-compliance/uploaded-images/{filename}', [WcUploadController::class, 'show'])
    ->where('filename', '[A-Za-z0-9._-]+');
Route::get('/website-compliance/scheduler/publish-scheduled', [WcSchedulerController::class, 'publishScheduled']);

// Hub iframe preview of live advisor sites (bypasses X-Frame-Options on cPanel).
Route::match(['GET', 'HEAD'], '/website-compliance/embed-site/{templateRequestId}/{path?}', [WcPublicController::class, 'embedAdvisorSite'])
    ->whereNumber('templateRequestId')
    ->where('path', '.*');

Route::middleware('hub_can:member_browse_catalog')->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/types', [ContentTypeController::class, 'index']);
    Route::get('/tags', [TagController::class, 'index']);
    Route::get('/posts/categories', [PostController::class, 'categories']);
    Route::get('/posts', [PostController::class, 'index']);
    Route::post('/posts/reach', [PostController::class, 'recordReach']);
    Route::get('/posts/{post}', [PostController::class, 'show']);
    Route::get('/bundles', [BundleController::class, 'index']);
    Route::get('/bundles/{bundle}', [BundleController::class, 'show']);
});

Route::middleware('hub_can:member_view_plans')->group(function () {
    Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);
    Route::get('/subscription-plans/{plan}', [SubscriptionController::class, 'showPlan']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/my-dashboard', [MyDashboardController::class, 'show']);
    Route::get('/my-credits', [MyDashboardController::class, 'credits']);

    Route::get('/acting-advisor', [ActingAdvisorController::class, 'show']);
    Route::put('/acting-advisor', [ActingAdvisorController::class, 'update']);

    Route::middleware('hub_can:member_view_plans')->group(function () {
        Route::post('/subscription-plans/{plan}/checkout', [SubscriptionController::class, 'checkout']);
        Route::post('/subscriptions/confirm', [SubscriptionController::class, 'confirm']);
        Route::get('/my-subscriptions', [SubscriptionController::class, 'mySubscriptions']);
    });

    Route::middleware('hub_can:member_purchase_content')->group(function () {
        Route::post('/posts/{post}/purchase', [PurchaseController::class, 'purchasePost']);
        Route::post('/bundles/{bundle}/purchase', [PurchaseController::class, 'purchaseBundle']);
        Route::post('/content-purchases/confirm', [PurchaseController::class, 'confirm']);
    });

    // Member list pages — also reachable from General options (dashboard).
    Route::get('/my-purchases', [PurchaseController::class, 'myPurchases']);
    Route::get('/my-invoices', [InvoiceController::class, 'index']);

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);

    // Social Media Compliance — member / role-gated by smc_* capabilities (not hard-coded roles).
    // acting_wl_wc_db: when hub switcher is on a white-label, read/write that hub's SMC tables.
    Route::middleware('acting_wl_wc_db')->group(function () {
        Route::middleware('hub_can:smc_submit_request')->group(function () {
            Route::post('/social-media-compliance/requests', [SocialMediaComplianceController::class, 'store']);
            Route::post('/social-media-compliance/requests/{socialMediaComplianceRequest}/resubmit', [SocialMediaComplianceController::class, 'resubmit']);
            Route::post('/social-media-compliance/requests/{socialMediaComplianceRequest}/confirm-feedback', [SocialMediaComplianceController::class, 'confirmFeedback']);
        });

        // Own history/detail: submit OR view-own (checked in controller against matrix).
        Route::get('/social-media-compliance/requests/mine', [SocialMediaComplianceController::class, 'mine']);
        Route::get('/social-media-compliance/requests/{socialMediaComplianceRequest}', [SocialMediaComplianceController::class, 'show']);
    });

    // General Compliance — member / role-gated by gc_* capabilities (not hard-coded roles).
    // acting_wl_wc_db: when hub switcher is on a white-label, read/write that hub's GC tables.
    Route::middleware('acting_wl_wc_db')->group(function () {
        Route::middleware('hub_can:gc_submit_request')->group(function () {
            Route::post('/general-compliance/requests', [GeneralComplianceController::class, 'store']);
            Route::post('/general-compliance/requests/{generalComplianceRequest}/resubmit', [GeneralComplianceController::class, 'resubmit']);
            Route::post('/general-compliance/requests/{generalComplianceRequest}/confirm-feedback', [GeneralComplianceController::class, 'confirmFeedback']);
        });

        Route::get('/general-compliance/requests/mine', [GeneralComplianceController::class, 'mine']);
        Route::get('/general-compliance/requests/{generalComplianceRequest}', [GeneralComplianceController::class, 'show']);
    });

    // Website Compliance — authenticated domain (module + capability gated in controllers / hub_can)
    // acting_wl_wc_db: when hub switcher is on a white-label, read/write that hub's wc_* tables.
    Route::prefix('website-compliance')->middleware('acting_wl_wc_db')->group(function () {
        Route::post('/upload-image', [WcUploadController::class, 'uploadImage']);

        Route::middleware('hub_can:wc_edit_sections,wc_submit_change_requests,wc_manage_templates,wc_request_deployments,wc_view_all_deployments,wc_publish_live_content')->group(function () {
            Route::get('/templates', [WcTemplateController::class, 'index']);
            Route::get('/templates/{id}', [WcTemplateController::class, 'show'])->whereNumber('id');
            Route::get('/templates/{id}/pages', [WcTemplateController::class, 'getTemplatePages'])->whereNumber('id');
            Route::get('/pages', [WcPageController::class, 'index']);
            Route::get('/pages/{id}', [WcPageController::class, 'show'])->whereNumber('id');
            Route::get('/pages/{pageId}/sections', [WcSectionController::class, 'index'])->whereNumber('pageId');
            Route::get('/sections/{id}', [WcSectionController::class, 'show'])->whereNumber('id');
        });

        Route::middleware('hub_can:wc_manage_templates')->group(function () {
            Route::post('/templates', [WcTemplateController::class, 'store']);
            Route::put('/templates/{id}', [WcTemplateController::class, 'update'])->whereNumber('id');
            Route::delete('/templates/{id}', [WcTemplateController::class, 'destroy'])->whereNumber('id');
            Route::post('/pages', [WcPageController::class, 'store']);
            Route::put('/pages/{id}', [WcPageController::class, 'update'])->whereNumber('id');
            Route::delete('/pages/{id}', [WcPageController::class, 'destroy'])->whereNumber('id');
        });

        Route::middleware('hub_can:wc_edit_sections,wc_publish_live_content')->group(function () {
            Route::post('/sections', [WcSectionController::class, 'store']);
            Route::post('/sections/{id}/lock', [WcSectionController::class, 'lock'])->whereNumber('id');
            Route::post('/sections/{id}/unlock', [WcSectionController::class, 'unlock'])->whereNumber('id');
        });

        Route::middleware('hub_can:wc_manage_deployment_sections')->group(function () {
            Route::put('/sections/{id}', [WcSectionController::class, 'update'])->whereNumber('id');
        });

        Route::middleware('hub_can:wc_submit_change_requests')->group(function () {
            Route::post('/change-requests', [WcChangeRequestController::class, 'store']);
            Route::post('/change-requests/{id}/resubmit', [WcChangeRequestController::class, 'resubmit'])->whereNumber('id');
            Route::post('/change-requests/{id}/confirm-feedback', [WcChangeRequestController::class, 'confirmFeedback'])->whereNumber('id');
        });

        Route::get('/change-requests', [WcChangeRequestController::class, 'index']);
        Route::get('/change-requests/{id}', [WcChangeRequestController::class, 'show'])->whereNumber('id');
        Route::get('/change-requests/{id}/preview', [WcChangeRequestController::class, 'preview'])->whereNumber('id');

        Route::middleware('hub_can:wc_review_change_requests')->group(function () {
            Route::post('/change-requests/{id}/assign', [WcChangeRequestController::class, 'assign'])->whereNumber('id');
            Route::post('/change-requests/{id}/approve', [WcChangeRequestController::class, 'approve'])->whereNumber('id');
            Route::post('/change-requests/{id}/reject', [WcChangeRequestController::class, 'reject'])->whereNumber('id');
            Route::post('/change-requests/{id}/approve-with-feedback', [WcChangeRequestController::class, 'approveWithFeedback'])->whereNumber('id');
        });

        Route::middleware('hub_can:wc_change_request_status')->group(function () {
            Route::post('/change-requests/{id}/change-status', [WcChangeRequestController::class, 'changeStatus'])->whereNumber('id');
        });

        Route::middleware('hub_can:wc_assign_change_requests')->group(function () {
            Route::post('/change-requests/{id}/assign-to-approver', [WcChangeRequestController::class, 'assignToApprover'])->whereNumber('id');
        });

        Route::get('/template-requests', [WcTemplateRequestController::class, 'index']);
        Route::middleware('hub_can:wc_request_deployments,wc_assign_website_templates')->group(function () {
            Route::post('/template-requests', [WcTemplateRequestController::class, 'store']);
        });
        Route::middleware('hub_can:wc_deploy_websites')->group(function () {
            Route::post('/template-requests/{id}/deploy', [WcTemplateRequestController::class, 'deploy'])->whereNumber('id');
            Route::put('/template-requests/{id}/branding', [WcTemplateRequestController::class, 'updateBranding'])->whereNumber('id');
            Route::post('/template-requests/{id}/reject', [WcTemplateRequestController::class, 'reject'])->whereNumber('id');
        });
        Route::middleware('hub_can:wc_assign_website_templates')->group(function () {
            Route::post('/template-requests/{id}/assign-advisor', [WcTemplateRequestController::class, 'assignAdvisor'])->whereNumber('id');
        });
        Route::get('/template-requests/{id}/sections', [WcTemplateRequestController::class, 'sections'])->whereNumber('id');
        Route::middleware('hub_can:wc_manage_deployment_sections')->group(function () {
            Route::put('/template-requests/{id}/sections', [WcTemplateRequestController::class, 'updateSections'])->whereNumber('id');
        });
        Route::middleware('hub_can:wc_publish_live_content')->group(function () {
            Route::post('/template-requests/{id}/publish-content', [WcTemplateRequestController::class, 'publishContent'])->whereNumber('id');
        });

        Route::middleware('hub_can:wc_view_platform_report')->group(function () {
            Route::get('/reports/summary', [WcReportController::class, 'summary']);
            Route::post('/reports/summary/refresh', [WcReportController::class, 'refresh']);
            Route::get('/reports', [WcReportController::class, 'index']);
        });

        Route::middleware('hub_can:wc_request_deployments,wc_assign_website_templates,wc_view_all_deployments,wc_deploy_websites')->group(function () {
            Route::get('/advisors', [WebsiteComplianceAdvisorController::class, 'advisors']);
        });
        Route::middleware('hub_can:wc_assign_change_requests,wc_view_all_change_requests,wc_review_change_requests')->group(function () {
            Route::get('/reviewers', [WebsiteComplianceAdvisorController::class, 'reviewers']);
        });
    });

    // client_admin management API (also aliased under /admin for older clients)
    $clientAdminRoutes = function () {
        Route::middleware('hub_can:dashboard_manage_posts')->group(function () {
            Route::post('/posts', [PostController::class, 'store']);
            Route::post('/posts/{post}', [PostController::class, 'update']);
            Route::put('/posts/{post}', [PostController::class, 'update']);
            Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_control_white_label_hubs')->group(function () {
            Route::get('/acting-hub', [ActingHubController::class, 'show']);
            Route::put('/acting-hub', [ActingHubController::class, 'update']);

            Route::get('/hub-content/targets', [HubContentController::class, 'targets']);
            Route::get('/hub-content/posts', [HubContentController::class, 'posts']);
            Route::post('/hub-content/posts', [HubContentController::class, 'storePost']);
            Route::put('/hub-content/posts/{post}', [HubContentController::class, 'updatePost']);
            Route::delete('/hub-content/posts/{post}', [HubContentController::class, 'destroyPost']);
            Route::get('/hub-content/types', [HubContentController::class, 'types']);
            Route::post('/hub-content/types', [HubContentController::class, 'storeType']);
            Route::put('/hub-content/types/{type}', [HubContentController::class, 'updateType']);
            Route::delete('/hub-content/types/{type}', [HubContentController::class, 'destroyType']);
            Route::get('/hub-content/categories', [HubContentController::class, 'categories']);
            Route::post('/hub-content/categories', [HubContentController::class, 'storeCategory']);
            Route::put('/hub-content/categories/{category}', [HubContentController::class, 'updateCategory']);
            Route::delete('/hub-content/categories/{category}', [HubContentController::class, 'destroyCategory']);
            Route::get('/hub-content/tags', [HubContentController::class, 'tags']);
            Route::post('/hub-content/tags', [HubContentController::class, 'storeTag']);
            Route::put('/hub-content/tags/{tag}', [HubContentController::class, 'updateTag']);
            Route::delete('/hub-content/tags/{tag}', [HubContentController::class, 'destroyTag']);
            Route::get('/hub-content/bundles', [HubContentController::class, 'bundles']);
            Route::post('/hub-content/bundles', [HubContentController::class, 'storeBundle']);
            Route::put('/hub-content/bundles/{bundle}', [HubContentController::class, 'updateBundle']);
            Route::delete('/hub-content/bundles/{bundle}', [HubContentController::class, 'destroyBundle']);

            Route::get('/content-push/targets', [ContentPushController::class, 'targets']);
            Route::get('/content-push/posts', [ContentPushController::class, 'posts']);
            Route::get('/content-push/recent', [ContentPushController::class, 'recent']);
            Route::post('/content-push', [ContentPushController::class, 'push']);
            Route::post('/content-push/hubs/{hub}/test-connection', [ContentPushController::class, 'testConnection']);
        });

        Route::middleware('hub_can:dashboard_manage_bundles')->group(function () {
            Route::get('/bundles', [BundleController::class, 'index']);
            Route::get('/bundles/{bundle}', [BundleController::class, 'show']);
            Route::post('/bundles', [BundleController::class, 'store']);
            Route::post('/bundles/{bundle}', [BundleController::class, 'update']);
            Route::put('/bundles/{bundle}', [BundleController::class, 'update']);
            Route::delete('/bundles/{bundle}', [BundleController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_categories')->group(function () {
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_firms')->group(function () {
            Route::get('/firms', [FirmController::class, 'index']);
            Route::post('/firms', [FirmController::class, 'store']);
            Route::put('/firms/{firm}', [FirmController::class, 'update']);
            Route::delete('/firms/{firm}', [FirmController::class, 'destroy']);
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
            Route::post('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
            Route::put('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
            Route::delete('/subscription-plans/{plan}', [SubscriptionController::class, 'destroyPlan']);
        });

        Route::middleware('hub_can:dashboard_manage_settings')->group(function () {
            Route::get('/settings', [SettingController::class, 'index']);
            // POST accepts multipart logo / favicon uploads (PHP does not populate files on PUT).
            Route::match(['put', 'post'], '/settings', [SettingController::class, 'update']);
        });

        Route::middleware('hub_can:dashboard_manage_role_display_names')->group(function () {
            Route::get('/role-display-names', [RoleDisplayNameController::class, 'index']);
            Route::put('/role-display-names', [RoleDisplayNameController::class, 'update']);
        });

        Route::middleware('hub_can:dashboard_manage_email_templates')->group(function () {
            Route::get('/email-templates', [EmailTemplateController::class, 'index']);
            Route::get('/email-templates/{event}', [EmailTemplateController::class, 'show']);
            Route::put('/email-templates/{event}/{audience}', [EmailTemplateController::class, 'update']);
            Route::post('/email-templates/{event}/{audience}/reset', [EmailTemplateController::class, 'reset']);
        });

        Route::middleware('hub_can:dashboard_manage_compliance_status_display_names')->group(function () {
            Route::get('/compliance-status-display-names', [ComplianceStatusDisplayNameController::class, 'index']);
            Route::put('/compliance-status-display-names', [ComplianceStatusDisplayNameController::class, 'update']);
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

        Route::middleware('hub_can:dashboard_manage_subscriber_credits')->group(function () {
            Route::get('/subscriber-credits', [SubscriberCreditsController::class, 'show']);
            Route::put('/subscriber-credits', [SubscriberCreditsController::class, 'update']);
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
            Route::post('/content-bank-transfers/{checkout}/confirm', [PurchaseController::class, 'confirmContentBankTransfer']);
        });

        Route::middleware('hub_can:dashboard_view_activity_logs')->group(function () {
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::get('/activity-logs/report', [ActivityLogController::class, 'report']);
        });

        Route::middleware('hub_can:dashboard_manage_modules')->group(function () {
            Route::get('/modules', [HubModulesController::class, 'show']);
            Route::put('/modules', [HubModulesController::class, 'update']);
        });

        // Social Media Compliance — queue / assign / review / reports
        // acting_wl_wc_db: when hub switcher is on a white-label, read/write that hub's SMC tables.
        Route::middleware('acting_wl_wc_db')->group(function () {
            Route::middleware('hub_can:smc_view_all_requests,smc_assign_requests,smc_review_requests,smc_change_request_status')->group(function () {
                Route::get('/social-media-compliance/requests', [SocialMediaComplianceController::class, 'index']);
                Route::get('/social-media-compliance/queue', [SocialMediaComplianceController::class, 'index']);
                Route::get('/social-media-compliance/requests/{socialMediaComplianceRequest}', [SocialMediaComplianceController::class, 'show']);
            });
            Route::middleware('hub_can:smc_review_requests')->group(function () {
                Route::post('/social-media-compliance/requests/{socialMediaComplianceRequest}/review', [SocialMediaComplianceController::class, 'review']);
            });
            Route::middleware('hub_can:smc_change_request_status')->group(function () {
                Route::post('/social-media-compliance/requests/{socialMediaComplianceRequest}/change-status', [SocialMediaComplianceController::class, 'changeStatus']);
            });
            Route::middleware('hub_can:smc_assign_requests,smc_review_requests')->group(function () {
                Route::post('/social-media-compliance/requests/{socialMediaComplianceRequest}/assign', [SocialMediaComplianceController::class, 'assign']);
            });
            Route::middleware('hub_can:smc_assign_requests')->group(function () {
                Route::get('/social-media-compliance/reviewers', [SocialMediaComplianceController::class, 'reviewers']);
            });
            Route::middleware('hub_can:smc_view_reports')->group(function () {
                Route::get('/social-media-compliance/reports', [SocialMediaComplianceController::class, 'report']);
                Route::get('/social-media-compliance/reports/export', [SocialMediaComplianceController::class, 'export']);
                Route::get('/social-media-compliance/charts/approver-workload', [SocialMediaComplianceController::class, 'approverWorkload']);
                Route::get('/social-media-compliance/charts/advisor-comparison', [SocialMediaComplianceController::class, 'advisorComparison']);
            });
        });

        // General Compliance — queue / assign / review / reports
        // acting_wl_wc_db: when hub switcher is on a white-label, read/write that hub's GC tables.
        Route::middleware('acting_wl_wc_db')->group(function () {
            Route::middleware('hub_can:gc_view_all_requests,gc_assign_requests,gc_review_requests,gc_change_request_status')->group(function () {
                Route::get('/general-compliance/requests', [GeneralComplianceController::class, 'index']);
                Route::get('/general-compliance/queue', [GeneralComplianceController::class, 'index']);
                Route::get('/general-compliance/requests/{generalComplianceRequest}', [GeneralComplianceController::class, 'show']);
            });
            Route::middleware('hub_can:gc_review_requests')->group(function () {
                Route::post('/general-compliance/requests/{generalComplianceRequest}/review', [GeneralComplianceController::class, 'review']);
            });
            Route::middleware('hub_can:gc_change_request_status')->group(function () {
                Route::post('/general-compliance/requests/{generalComplianceRequest}/change-status', [GeneralComplianceController::class, 'changeStatus']);
            });
            Route::middleware('hub_can:gc_assign_requests,gc_review_requests')->group(function () {
                Route::post('/general-compliance/requests/{generalComplianceRequest}/assign', [GeneralComplianceController::class, 'assign']);
            });
            Route::middleware('hub_can:gc_assign_requests')->group(function () {
                Route::get('/general-compliance/reviewers', [GeneralComplianceController::class, 'reviewers']);
            });
            Route::middleware('hub_can:gc_view_reports')->group(function () {
                Route::get('/general-compliance/reports', [GeneralComplianceController::class, 'report']);
                Route::get('/general-compliance/reports/export', [GeneralComplianceController::class, 'export']);
                Route::get('/general-compliance/charts/approver-workload', [GeneralComplianceController::class, 'approverWorkload']);
                Route::get('/general-compliance/charts/advisor-comparison', [GeneralComplianceController::class, 'advisorComparison']);
            });
        });

        // Website Compliance — queue / assign / review / reports / deployments
        Route::prefix('website-compliance')->middleware('acting_wl_wc_db')->group(function () {
            Route::middleware('hub_can:wc_view_all_change_requests,wc_assign_change_requests,wc_review_change_requests,wc_change_request_status')->group(function () {
                Route::get('/change-requests', [WcChangeRequestController::class, 'index']);
                Route::get('/change-requests/{id}', [WcChangeRequestController::class, 'show'])->whereNumber('id');
                Route::get('/change-requests/{id}/preview', [WcChangeRequestController::class, 'preview'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_submit_change_requests')->group(function () {
                Route::post('/change-requests/{id}/resubmit', [WcChangeRequestController::class, 'resubmit'])->whereNumber('id');
                Route::post('/change-requests/{id}/confirm-feedback', [WcChangeRequestController::class, 'confirmFeedback'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_change_request_status')->group(function () {
                Route::post('/change-requests/{id}/change-status', [WcChangeRequestController::class, 'changeStatus'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_review_change_requests')->group(function () {
                Route::post('/change-requests/{id}/assign', [WcChangeRequestController::class, 'assign'])->whereNumber('id');
                Route::post('/change-requests/{id}/approve', [WcChangeRequestController::class, 'approve'])->whereNumber('id');
                Route::post('/change-requests/{id}/reject', [WcChangeRequestController::class, 'reject'])->whereNumber('id');
                Route::post('/change-requests/{id}/approve-with-feedback', [WcChangeRequestController::class, 'approveWithFeedback'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_assign_change_requests')->group(function () {
                Route::post('/change-requests/{id}/assign-to-approver', [WcChangeRequestController::class, 'assignToApprover'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_view_all_deployments,wc_request_deployments,wc_deploy_websites')->group(function () {
                Route::get('/template-requests', [WcTemplateRequestController::class, 'index']);
            });
            Route::middleware('hub_can:wc_deploy_websites')->group(function () {
                Route::post('/template-requests/{id}/deploy', [WcTemplateRequestController::class, 'deploy'])->whereNumber('id');
                Route::put('/template-requests/{id}/branding', [WcTemplateRequestController::class, 'updateBranding'])->whereNumber('id');
                Route::post('/template-requests/{id}/reject', [WcTemplateRequestController::class, 'reject'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_manage_templates')->group(function () {
                Route::get('/templates', [WcTemplateController::class, 'index']);
                Route::post('/templates', [WcTemplateController::class, 'store']);
                Route::put('/templates/{id}', [WcTemplateController::class, 'update'])->whereNumber('id');
                Route::delete('/templates/{id}', [WcTemplateController::class, 'destroy'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_view_platform_report')->group(function () {
                Route::get('/reports/summary', [WcReportController::class, 'summary']);
                Route::post('/reports/summary/refresh', [WcReportController::class, 'refresh']);
                Route::get('/reports', [WcReportController::class, 'index']);
            });
            Route::middleware('hub_can:wc_request_deployments,wc_assign_website_templates,wc_view_all_deployments,wc_deploy_websites')->group(function () {
                Route::get('/advisors', [WebsiteComplianceAdvisorController::class, 'advisors']);
            });
            Route::middleware('hub_can:wc_assign_change_requests,wc_view_all_change_requests,wc_review_change_requests')->group(function () {
                Route::get('/reviewers', [WebsiteComplianceAdvisorController::class, 'reviewers']);
            });
        });

        // Card settings — client_admin payer only when advisor billing is on
        // (not gated by the capabilities matrix; controller enforces role).
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

        Route::middleware('pa_can:pa_manage_users_roles')->group(function () {
            Route::get('/users', [PowerAdminUserController::class, 'index']);
            Route::post('/users', [PowerAdminUserController::class, 'store']);
            Route::get('/users/{user}', [PowerAdminUserController::class, 'show']);
            Route::put('/users/{user}', [PowerAdminUserController::class, 'update']);
            Route::delete('/users/{user}', [PowerAdminUserController::class, 'destroy']);
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

        // Capability is checked in the controller against hub_id (may differ from current hub).
        Route::get('/subscriber-credits', [SubscriberCreditsController::class, 'show']);
        Route::put('/subscriber-credits', [SubscriberCreditsController::class, 'update']);

        Route::middleware('hub_can:dashboard_manage_advisor_pricing')->group(function () {
            Route::get('/advisor-pricing', [PowerAdminAdvisorPricingController::class, 'index']);
            Route::post('/advisor-pricing', [PowerAdminAdvisorPricingController::class, 'store']);
            Route::put('/advisor-pricing/{tier}', [PowerAdminAdvisorPricingController::class, 'update']);
            Route::delete('/advisor-pricing/{tier}', [PowerAdminAdvisorPricingController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_plans')->group(function () {
            Route::get('/subscription-plans', [SubscriptionController::class, 'adminPlans']);
            Route::post('/subscription-plans', [SubscriptionController::class, 'storePlan']);
            Route::post('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
            Route::put('/subscription-plans/{plan}', [SubscriptionController::class, 'updatePlan']);
            Route::delete('/subscription-plans/{plan}', [SubscriptionController::class, 'destroyPlan']);
        });

        Route::middleware('hub_can:dashboard_manage_posts')->group(function () {
            Route::post('/posts', [PostController::class, 'store']);
            Route::post('/posts/{post}', [PostController::class, 'update']);
            Route::put('/posts/{post}', [PostController::class, 'update']);
            Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_control_white_label_hubs')->group(function () {
            Route::get('/acting-hub', [ActingHubController::class, 'show']);
            Route::put('/acting-hub', [ActingHubController::class, 'update']);

            Route::get('/hub-content/targets', [HubContentController::class, 'targets']);
            Route::get('/hub-content/posts', [HubContentController::class, 'posts']);
            Route::post('/hub-content/posts', [HubContentController::class, 'storePost']);
            Route::put('/hub-content/posts/{post}', [HubContentController::class, 'updatePost']);
            Route::delete('/hub-content/posts/{post}', [HubContentController::class, 'destroyPost']);
            Route::get('/hub-content/types', [HubContentController::class, 'types']);
            Route::post('/hub-content/types', [HubContentController::class, 'storeType']);
            Route::put('/hub-content/types/{type}', [HubContentController::class, 'updateType']);
            Route::delete('/hub-content/types/{type}', [HubContentController::class, 'destroyType']);
            Route::get('/hub-content/categories', [HubContentController::class, 'categories']);
            Route::post('/hub-content/categories', [HubContentController::class, 'storeCategory']);
            Route::put('/hub-content/categories/{category}', [HubContentController::class, 'updateCategory']);
            Route::delete('/hub-content/categories/{category}', [HubContentController::class, 'destroyCategory']);
            Route::get('/hub-content/tags', [HubContentController::class, 'tags']);
            Route::post('/hub-content/tags', [HubContentController::class, 'storeTag']);
            Route::put('/hub-content/tags/{tag}', [HubContentController::class, 'updateTag']);
            Route::delete('/hub-content/tags/{tag}', [HubContentController::class, 'destroyTag']);
            Route::get('/hub-content/bundles', [HubContentController::class, 'bundles']);
            Route::post('/hub-content/bundles', [HubContentController::class, 'storeBundle']);
            Route::put('/hub-content/bundles/{bundle}', [HubContentController::class, 'updateBundle']);
            Route::delete('/hub-content/bundles/{bundle}', [HubContentController::class, 'destroyBundle']);

            Route::get('/content-push/targets', [ContentPushController::class, 'targets']);
            Route::get('/content-push/posts', [ContentPushController::class, 'posts']);
            Route::get('/content-push/recent', [ContentPushController::class, 'recent']);
            Route::post('/content-push', [ContentPushController::class, 'push']);
            Route::post('/content-push/hubs/{hub}/test-connection', [ContentPushController::class, 'testConnection']);
        });

        Route::middleware('hub_can:dashboard_manage_bundles')->group(function () {
            Route::get('/bundles', [BundleController::class, 'index']);
            Route::get('/bundles/{bundle}', [BundleController::class, 'show']);
            Route::post('/bundles', [BundleController::class, 'store']);
            Route::post('/bundles/{bundle}', [BundleController::class, 'update']);
            Route::put('/bundles/{bundle}', [BundleController::class, 'update']);
            Route::delete('/bundles/{bundle}', [BundleController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_categories')->group(function () {
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        });

        Route::middleware('hub_can:dashboard_manage_firms')->group(function () {
            Route::get('/firms', [FirmController::class, 'index']);
            Route::post('/firms', [FirmController::class, 'store']);
            Route::put('/firms/{firm}', [FirmController::class, 'update']);
            Route::delete('/firms/{firm}', [FirmController::class, 'destroy']);
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

        Route::middleware('hub_can:dashboard_view_activity_logs')->group(function () {
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::get('/activity-logs/report', [ActivityLogController::class, 'report']);
        });

        // Capability is checked in the controller against hub_id (may differ from current hub).
        Route::get('/modules', [HubModulesController::class, 'show']);
        Route::put('/modules', [HubModulesController::class, 'update']);

        // Social Media Compliance — acting_wl_wc_db remounts onto selected white-label DB
        Route::middleware('acting_wl_wc_db')->group(function () {
            Route::middleware('hub_can:smc_view_all_requests,smc_assign_requests,smc_review_requests,smc_change_request_status')->group(function () {
                Route::get('/social-media-compliance/requests', [SocialMediaComplianceController::class, 'index']);
                Route::get('/social-media-compliance/queue', [SocialMediaComplianceController::class, 'index']);
                Route::get('/social-media-compliance/requests/{socialMediaComplianceRequest}', [SocialMediaComplianceController::class, 'show']);
            });
            Route::middleware('hub_can:smc_review_requests')->group(function () {
                Route::post('/social-media-compliance/requests/{socialMediaComplianceRequest}/review', [SocialMediaComplianceController::class, 'review']);
            });
            Route::middleware('hub_can:smc_change_request_status')->group(function () {
                Route::post('/social-media-compliance/requests/{socialMediaComplianceRequest}/change-status', [SocialMediaComplianceController::class, 'changeStatus']);
            });
            Route::middleware('hub_can:smc_assign_requests,smc_review_requests')->group(function () {
                Route::post('/social-media-compliance/requests/{socialMediaComplianceRequest}/assign', [SocialMediaComplianceController::class, 'assign']);
            });
            Route::middleware('hub_can:smc_assign_requests')->group(function () {
                Route::get('/social-media-compliance/reviewers', [SocialMediaComplianceController::class, 'reviewers']);
            });
            Route::middleware('hub_can:smc_view_reports')->group(function () {
                Route::get('/social-media-compliance/reports', [SocialMediaComplianceController::class, 'report']);
                Route::get('/social-media-compliance/reports/export', [SocialMediaComplianceController::class, 'export']);
                Route::get('/social-media-compliance/charts/approver-workload', [SocialMediaComplianceController::class, 'approverWorkload']);
                Route::get('/social-media-compliance/charts/advisor-comparison', [SocialMediaComplianceController::class, 'advisorComparison']);
            });
        });

        // General Compliance — acting_wl_wc_db remounts onto selected white-label DB
        Route::middleware('acting_wl_wc_db')->group(function () {
            Route::middleware('hub_can:gc_view_all_requests,gc_assign_requests,gc_review_requests,gc_change_request_status')->group(function () {
                Route::get('/general-compliance/requests', [GeneralComplianceController::class, 'index']);
                Route::get('/general-compliance/queue', [GeneralComplianceController::class, 'index']);
                Route::get('/general-compliance/requests/{generalComplianceRequest}', [GeneralComplianceController::class, 'show']);
            });
            Route::middleware('hub_can:gc_review_requests')->group(function () {
                Route::post('/general-compliance/requests/{generalComplianceRequest}/review', [GeneralComplianceController::class, 'review']);
            });
            Route::middleware('hub_can:gc_change_request_status')->group(function () {
                Route::post('/general-compliance/requests/{generalComplianceRequest}/change-status', [GeneralComplianceController::class, 'changeStatus']);
            });
            Route::middleware('hub_can:gc_assign_requests,gc_review_requests')->group(function () {
                Route::post('/general-compliance/requests/{generalComplianceRequest}/assign', [GeneralComplianceController::class, 'assign']);
            });
            Route::middleware('hub_can:gc_assign_requests')->group(function () {
                Route::get('/general-compliance/reviewers', [GeneralComplianceController::class, 'reviewers']);
            });
            Route::middleware('hub_can:gc_view_reports')->group(function () {
                Route::get('/general-compliance/reports', [GeneralComplianceController::class, 'report']);
                Route::get('/general-compliance/reports/export', [GeneralComplianceController::class, 'export']);
                Route::get('/general-compliance/charts/approver-workload', [GeneralComplianceController::class, 'approverWorkload']);
                Route::get('/general-compliance/charts/advisor-comparison', [GeneralComplianceController::class, 'advisorComparison']);
            });
        });

        // Website Compliance — queue / assign / review / reports / deployments
        Route::prefix('website-compliance')->middleware('acting_wl_wc_db')->group(function () {
            Route::middleware('hub_can:wc_view_all_change_requests,wc_assign_change_requests,wc_review_change_requests,wc_change_request_status')->group(function () {
                Route::get('/change-requests', [WcChangeRequestController::class, 'index']);
                Route::get('/change-requests/{id}', [WcChangeRequestController::class, 'show'])->whereNumber('id');
                Route::get('/change-requests/{id}/preview', [WcChangeRequestController::class, 'preview'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_submit_change_requests')->group(function () {
                Route::post('/change-requests/{id}/resubmit', [WcChangeRequestController::class, 'resubmit'])->whereNumber('id');
                Route::post('/change-requests/{id}/confirm-feedback', [WcChangeRequestController::class, 'confirmFeedback'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_change_request_status')->group(function () {
                Route::post('/change-requests/{id}/change-status', [WcChangeRequestController::class, 'changeStatus'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_review_change_requests')->group(function () {
                Route::post('/change-requests/{id}/assign', [WcChangeRequestController::class, 'assign'])->whereNumber('id');
                Route::post('/change-requests/{id}/approve', [WcChangeRequestController::class, 'approve'])->whereNumber('id');
                Route::post('/change-requests/{id}/reject', [WcChangeRequestController::class, 'reject'])->whereNumber('id');
                Route::post('/change-requests/{id}/approve-with-feedback', [WcChangeRequestController::class, 'approveWithFeedback'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_assign_change_requests')->group(function () {
                Route::post('/change-requests/{id}/assign-to-approver', [WcChangeRequestController::class, 'assignToApprover'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_view_all_deployments,wc_request_deployments,wc_deploy_websites')->group(function () {
                Route::get('/template-requests', [WcTemplateRequestController::class, 'index']);
            });
            Route::middleware('hub_can:wc_deploy_websites')->group(function () {
                Route::post('/template-requests/{id}/deploy', [WcTemplateRequestController::class, 'deploy'])->whereNumber('id');
                Route::put('/template-requests/{id}/branding', [WcTemplateRequestController::class, 'updateBranding'])->whereNumber('id');
                Route::post('/template-requests/{id}/reject', [WcTemplateRequestController::class, 'reject'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_manage_templates')->group(function () {
                Route::get('/templates', [WcTemplateController::class, 'index']);
                Route::post('/templates', [WcTemplateController::class, 'store']);
                Route::put('/templates/{id}', [WcTemplateController::class, 'update'])->whereNumber('id');
                Route::delete('/templates/{id}', [WcTemplateController::class, 'destroy'])->whereNumber('id');
            });
            Route::middleware('hub_can:wc_view_platform_report')->group(function () {
                Route::get('/reports/summary', [WcReportController::class, 'summary']);
                Route::post('/reports/summary/refresh', [WcReportController::class, 'refresh']);
                Route::get('/reports', [WcReportController::class, 'index']);
            });
            Route::middleware('hub_can:wc_request_deployments,wc_assign_website_templates,wc_view_all_deployments,wc_deploy_websites')->group(function () {
                Route::get('/advisors', [WebsiteComplianceAdvisorController::class, 'advisors']);
            });
            Route::middleware('hub_can:wc_assign_change_requests,wc_view_all_change_requests,wc_review_change_requests')->group(function () {
                Route::get('/reviewers', [WebsiteComplianceAdvisorController::class, 'reviewers']);
            });
        });
    });
});
