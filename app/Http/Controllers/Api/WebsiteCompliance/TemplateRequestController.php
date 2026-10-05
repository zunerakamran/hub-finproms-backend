<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\ActivityLogService;
use App\Services\ActingAdvisorService;
use App\Services\ActingHubService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\ModuleBillingService;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use App\Services\WebsiteCompliance\CpanelSyncService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use App\Support\ApiListResponse;
use App\Support\PlainText;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use App\Support\WebsiteCompliance\BrandColor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TemplateRequestController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly ActivityLogService $activityLogs,
        private readonly ActingAdvisorService $actingAdvisors,
        private readonly ActingHubService $actingHubs,
        private readonly ModuleBillingService $moduleBilling,
        private readonly FirmComplianceVisibilityService $firmVisibility
    ) {}

    private function resolveTemplateName(?string $requested): ?string
    {
        $safe = HubTemplateCatalog::sanitizeSlug((string) ($requested ?: ''));
        if ($safe !== '' && HubTemplateCatalog::allows($safe)) {
            return $safe;
        }

        return HubTemplateCatalog::defaultSlug();
    }

    /** @return list<string> */
    private function requestRelations(): array
    {
        return [
            'advisor',
            'advisor.firm',
            'assignedAdvisor',
            'assignedAdvisor.firm',
            'requestedBy',
            'requestedBy.firm',
            'goLiveRequestedBy',
        ];
    }

    /**
     * Ensure assigned advisor is allowed for the submitter firm's visibility settings.
     *
     * @throws ValidationException
     */
    private function assertAssignableAdvisor(User $actor, ?int $advisorId, ?User $submitter): void
    {
        if (! $advisorId) {
            return;
        }

        $advisor = User::query()->with('firm:id,name,is_central')->find($advisorId);
        if (! $advisor) {
            return;
        }

        $submitter?->loadMissing([
            'firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
        ]);
        $submitterFirm = $submitter?->firm;

        if ($this->firmVisibility->userIsEligibleAssignee($advisor, $submitterFirm)) {
            return;
        }

        // Power / FinProms admin creating without a firm may assign any advisor.
        if ($this->firmVisibility->actorBypassesFirmScope($actor) && ! $submitterFirm) {
            return;
        }

        throw ValidationException::withMessages([
            'assigned_advisor_id' => ['Selected advisor is not allowed for this firm’s visibility settings.'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (
            ! $this->gate->can($user, 'wc_request_deployments')
            && ! $this->gate->can($user, 'wc_assign_website_templates')
        ) {
            $this->gate->assertCan($user, 'wc_request_deployments');
        }

        // Managers with "Assign website templates" must pick an advisor on create.
        // Admin-staff acting as an advisor behave like that advisor (no assign required).
        $operatingAsAdvisor = $this->actingAdvisors->isOperatingAsAdvisor($user);
        $mustAssignAdvisor = ! $operatingAsAdvisor
            && $this->gate->can($user, 'wc_assign_website_templates');

        $request->validate([
            'domain_name' => 'required|string|max:255',
            'template_name' => 'nullable|string|max:255',
            'request_type' => 'nullable|in:advisor_website,hub_main_website',
            'logo_url' => 'nullable|string|max:1000',
            'white_logo_url' => 'nullable|string|max:1000',
            'favicon_url' => 'nullable|string|max:1000',
            'primary_color' => 'nullable|string|max:50',
            'secondary_color' => 'nullable|string|max:50',
            'services' => 'nullable|array|max:40',
            'services.*.name' => 'nullable|string|max:150',
            'services.*.attachment_url' => 'nullable|string|max:1000',
            'services.*.attachment_name' => 'nullable|string|max:255',
            'images' => 'nullable|array|max:40',
            'images.*.url' => 'nullable|string|max:1000',
            'images.*.label' => 'nullable|string|max:150',
            'contact_details' => 'nullable|array',
            'contact_details.phone' => 'nullable|string|max:80',
            'contact_details.email' => 'nullable|string|max:255',
            'contact_details.address' => 'nullable|string|max:500',
            'contact_details.website' => 'nullable|string|max:255',
            'policies' => 'nullable|array|max:20',
            'policies.*.name' => 'nullable|string|max:150',
            'policies.*.attachment_url' => 'nullable|string|max:1000',
            'policies.*.attachment_name' => 'nullable|string|max:255',
            'selected_pages' => 'nullable|array|max:40',
            'selected_pages.*' => 'nullable|string|max:150',
            'page_contents' => 'nullable|array',
            'page_contents.*.url' => 'nullable|string|max:1000',
            'page_contents.*.name' => 'nullable|string|max:255',
            'assigned_advisor_id' => [
                $mustAssignAdvisor ? 'required' : 'nullable',
                Rule::exists(User::class, 'id'),
            ],
        ]);

        $templateName = $this->resolveTemplateName($request->template_name);
        if (! $templateName) {
            return response()->json([
                'message' => 'No showcase template is available on hub ['.HubTemplateCatalog::currentHubSlug().'].',
                'allowed_templates' => HubTemplateCatalog::allowedSlugs(),
            ], 422);
        }

        $assignedAdvisorId = $mustAssignAdvisor || $request->filled('assigned_advisor_id')
            ? ($request->assigned_advisor_id ? (int) $request->assigned_advisor_id : null)
            : null;
        $user->loadMissing([
            'firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
        ]);
        $this->assertAssignableAdvisor($user, $assignedAdvisorId, $user);

        $requestType = $request->request_type
            ?? ($this->gate->can($user, 'wc_deploy_websites') ? 'hub_main_website' : 'advisor_website');

        $tenantUserId = $this->gate->tenantUserIdOrNull($user);
        $websiteAdvisorId = $this->actingAdvisors->websiteAdvisorId($user);
        $onBehalfById = $websiteAdvisorId && $this->actingAdvisors->canActOnBehalf($user)
            ? (int) $user->id
            : null;

        // Persist the pending request only. Hub sections are created on deploy
        // (same path for advisor self-request and manager-assigned deployments).
        $templateRequest = TemplateRequest::create([
            'advisor_id' => $operatingAsAdvisor ? ($websiteAdvisorId ?? $tenantUserId) : null,
            'requested_by_id' => $tenantUserId,
            'template_name' => $templateName,
            'request_type' => $requestType,
            'assigned_advisor_id' => $assignedAdvisorId,
            'domain_name' => $request->domain_name,
            'logo_url' => CpanelSyncService::absoluteAssetUrl($request->logo_url),
            'white_logo_url' => CpanelSyncService::absoluteAssetUrl($request->white_logo_url),
            'favicon_url' => CpanelSyncService::absoluteAssetUrl($request->favicon_url),
            'primary_color' => BrandColor::toHex($request->primary_color ?? null, '#0B1B3D'),
            'secondary_color' => BrandColor::toHex($request->secondary_color ?? null, '#C8102E'),
            'services' => $this->normalizeServices($request->input('services')),
            'images' => $this->normalizeImages($request->input('images')),
            'contact_details' => $this->normalizeContactDetails($request->input('contact_details')),
            'policies' => $this->normalizePolicies($request->input('policies')),
            'selected_pages' => $this->normalizeSelectedPages($request->input('selected_pages')),
            'page_contents' => $this->normalizePageContents($request->input('page_contents')),
            'status' => 'pending',
        ]);

        $this->activityLogs->log([
            'action' => 'wc.template_request.submit',
            'description' => "Requested template ({$templateRequest->template_name}) deployment [{$requestType}] for domain: ".$request->domain_name
                .($onBehalfById && $websiteAdvisorId ? ' on behalf of user #'.$websiteAdvisorId : ''),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        return response()->json($templateRequest->load($this->requestRelations()), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        $query = TemplateRequest::with($this->requestRelations());

        // Control-plane operators (and anyone with view-all) see every deployment on the acting hub.
        if ($this->gate->can($user, 'wc_view_all_deployments')
            || $this->gate->can($user, 'wc_publish_live_content')
            || $this->gate->isRemoteControlPlaneOperator($user)) {
            $query->latest();
        } elseif (
            $this->gate->can($user, 'wc_request_deployments')
            || $this->gate->can($user, 'wc_assign_website_templates')
        ) {
            $scopeId = $this->actingAdvisors->websiteAdvisorId($user) ?? (int) $user->id;
            $query
                ->where(function ($q) use ($user, $scopeId) {
                    $q->where('advisor_id', $scopeId)
                        ->orWhere('assigned_advisor_id', $scopeId)
                        ->orWhere('requested_by_id', $user->id);
                    if ($scopeId !== (int) $user->id) {
                        $q->orWhere('advisor_id', $user->id)
                            ->orWhere('assigned_advisor_id', $user->id);
                    }
                })
                ->latest();
        } else {
            $scopeId = $this->actingAdvisors->websiteAdvisorId($user) ?? (int) $user->id;
            $query
                ->where(function ($q) use ($scopeId) {
                    $q->where('advisor_id', $scopeId)
                        ->orWhere('assigned_advisor_id', $scopeId);
                })
                ->latest();
        }

        if ($request->filled('status')) {
            $statuses = collect(explode(',', (string) $request->input('status')))
                ->map(fn ($s) => trim((string) $s))
                ->filter()
                ->values()
                ->all();
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        return ApiListResponse::fromPaginator(
            $query->paginate(ApiListResponse::perPage($request))
        );
    }

    public function deploy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_deploy_websites');

        $request->validate([
            'cpanel_domain' => 'required|string|max:255',
            'cpanel_db_host' => 'nullable|string|max:255',
            'cpanel_db_name' => 'nullable|string|max:255',
            'cpanel_db_user' => 'nullable|string|max:255',
            'cpanel_db_password' => 'nullable|string|max:255',
            'cpanel_api_key' => 'nullable|string|max:255',
            'logo_url' => 'nullable|string|max:1000',
            'white_logo_url' => 'nullable|string|max:1000',
            'favicon_url' => 'nullable|string|max:1000',
            'primary_color' => 'nullable|string|max:50',
            'secondary_color' => 'nullable|string|max:50',
        ]);

        $templateRequest = TemplateRequest::findOrFail($id);

        if ((string) $templateRequest->status === TemplateRequest::STATUS_REJECTED) {
            return response()->json([
                'message' => 'Rejected deployments cannot be deployed. Submit a new request instead.',
            ], 422);
        }

        $targetDomain = trim((string) $request->cpanel_domain);
        $isLiveUpdate = $templateRequest->isLive();
        $wasPending = (string) $templateRequest->status === TemplateRequest::STATUS_PENDING;
        $isLegacyDeployed = (string) $templateRequest->status === TemplateRequest::STATUS_DEPLOYED;

        // Pending → staging. Staging/ready_for_live/legacy deployed → refresh staging host.
        // Live → update live host (compliance keeps pointing at cpanel_domain).
        if ($isLiveUpdate) {
            $updates = [
                'status' => TemplateRequest::STATUS_LIVE,
                'cpanel_domain' => $targetDomain,
            ];
        } else {
            $updates = [
                'status' => ($wasPending || $isLegacyDeployed)
                    ? TemplateRequest::STATUS_STAGING
                    : (string) $templateRequest->status,
                'staging_domain' => $targetDomain,
                'cpanel_domain' => $targetDomain,
            ];
        }

        $updates = array_merge($updates, [
            'cpanel_db_host' => $request->cpanel_db_host,
            'cpanel_db_name' => $request->cpanel_db_name,
            'cpanel_db_user' => $request->cpanel_db_user,
        ], $this->brandingUpdatesFromRequest($request));

        // Never wipe stored secrets when the deploy form leaves password/API key blank.
        if ($request->filled('cpanel_db_password') || $request->filled('cpanel_db_pass')) {
            $updates['cpanel_db_password'] = $request->cpanel_db_password ?? $request->input('cpanel_db_pass');
        }
        if ($request->filled('cpanel_api_key')) {
            $updates['cpanel_api_key'] = $request->cpanel_api_key;
        }

        $templateRequest->update($updates);
        $templateRequest->refresh();

        $targetAdvisorId = $templateRequest->assigned_advisor_id ?? $templateRequest->advisor_id;
        $hubSectionsCreated = 0;
        $hubSectionsCount = 0;

        if ($targetAdvisorId) {
            $hubSectionsCreated = AdvisorSectionService::ensureForAdvisor(
                (int) $targetAdvisorId,
                $templateRequest->template_name ?: HubTemplateCatalog::defaultSlug(),
                true,
                (int) $templateRequest->id
            );
            $hubSectionsCount = Section::where('advisor_id', $targetAdvisorId)
                ->where('template_request_id', $templateRequest->id)
                ->count();
        }

        $configSynced = CpanelSyncService::pushDeployConfig($templateRequest, $targetAdvisorId);
        $contentSynced = false;

        if ($targetAdvisorId) {
            $contentSynced = CpanelSyncService::pushToTemplateRequestCpanel($templateRequest);
        }

        $this->activityLogs->log([
            'action' => $isLiveUpdate ? 'wc.template_request.update_live' : 'wc.template_request.deploy_staging',
            'description' => ($isLiveUpdate
                ? 'Updated live hosting for domain: '
                : 'Deployed template to staging URL: ').$targetDomain
                .(! $isLiveUpdate ? ' (intended live: '.($templateRequest->domain_name ?: 'n/a').')' : '')
                .($configSynced ? ' (remote config written)' : ' (remote config sync failed)')
                .($contentSynced ? ' (initial content pushed)' : '')
                .($targetAdvisorId
                    ? " (hub sections for advisor {$targetAdvisorId}: {$hubSectionsCount})"
                    : ' (NO advisor_id — hub sections were not created)'),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        // One-time £/website invoice (idempotent per template request) when charging is on.
        $moduleInvoice = null;
        if (! $isLiveUpdate) {
            try {
                $hub = $this->actingHubs->actingHub($user);
                $moduleInvoice = $this->moduleBilling->invoiceWebsiteDeploy($hub, $templateRequest, $user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($isLiveUpdate) {
            $message = $configSynced
                ? 'Live deployment settings updated and synced. Compliance continues on the live URL.'
                : 'Live settings saved, but remote cPanel config could not be verified. Check Laravel logs and that api.php is reachable.';
        } else {
            $message = $configSynced
                ? 'Site deployed to the temporary (staging) URL. Compliance can run there until the requester asks to go live.'
                : 'Site marked as staging, but remote cPanel config could not be verified. Check Laravel logs and that api.php is reachable.';
        }

        if (! $targetAdvisorId) {
            $message .= ' No advisor is linked to this request, so hub sections were not created — the advisor will have nothing to edit in the dashboard.';
        } elseif ($hubSectionsCount === 0) {
            $message .= ' Hub sections were not created for this advisor. Check Laravel logs (AdvisorSectionService).';
        }

        return response()->json([
            'message' => $message,
            'config_synced' => $configSynced,
            'content_synced' => $contentSynced,
            'advisor_id' => $targetAdvisorId,
            'hub_sections_created' => $hubSectionsCreated,
            'hub_sections_count' => $hubSectionsCount,
            'module_invoice' => $moduleInvoice ? [
                'id' => $moduleInvoice->id,
                'invoice_number' => $moduleInvoice->invoice_number,
                'type' => $moduleInvoice->type,
                'types' => $moduleInvoice->types,
                'amount' => $moduleInvoice->amount,
                'status' => $moduleInvoice->status,
                'description' => $moduleInvoice->description,
            ] : null,
            'template_request' => $templateRequest->load($this->requestRelations()),
        ]);
    }

    /**
     * Requester submits a go-live request for a staging site.
     * Power Admin sees this as a new deployable request for the main/live URL.
     */
    public function requestGoLive(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        if (
            ! $this->gate->can($user, 'wc_request_deployments')
            && ! $this->gate->can($user, 'wc_assign_website_templates')
        ) {
            $this->gate->assertCan($user, 'wc_request_deployments');
        }

        $request->validate([
            'domain_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);

        $templateRequest = TemplateRequest::findOrFail($id);
        $tenantUserId = (int) ($this->gate->tenantUserIdOrNull($user) ?? $user->id);

        if ((int) ($templateRequest->requested_by_id ?? 0) !== $tenantUserId) {
            return response()->json([
                'message' => 'Only the original requester can submit a go-live request for this site.',
            ], 403);
        }

        if (! $templateRequest->canRequestGoLive()) {
            return response()->json([
                'message' => $templateRequest->status === TemplateRequest::STATUS_READY_FOR_LIVE
                    ? 'A go-live request has already been submitted. Power Admin will deploy this site to the main URL.'
                    : 'Go-live requests can only be submitted while the site is on its temporary (staging) URL.',
            ], 422);
        }

        $liveDomain = trim((string) ($request->input('domain_name') ?: $templateRequest->domain_name));
        if ($liveDomain === '') {
            return response()->json([
                'message' => 'Please confirm the main/live domain for this go-live request.',
            ], 422);
        }

        $notes = trim((string) $request->input('notes', ''));

        $templateRequest->update([
            'status' => TemplateRequest::STATUS_READY_FOR_LIVE,
            'domain_name' => $liveDomain,
            'go_live_requested_at' => now(),
            'go_live_requested_by_id' => $tenantUserId,
            'go_live_notes' => $notes !== '' ? $notes : null,
        ]);
        $templateRequest->refresh();

        $this->activityLogs->log([
            'action' => 'wc.template_request.request_go_live',
            'description' => 'Requester submitted go-live request. Staging: '
                .($templateRequest->staging_domain ?: $templateRequest->cpanel_domain)
                .' → requested live: '.$liveDomain
                .($notes !== '' ? ' | notes: '.$notes : ''),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        return response()->json([
            'message' => 'Go-live request submitted. Power Admin will see this as a new request and can deploy it to the main URL.',
            'template_request' => $templateRequest->load($this->requestRelations()),
        ], 201);
    }

    /**
     * Power Admin deploys a submitted go-live request onto the main/live URL.
     */
    public function promoteToLive(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_deploy_websites');

        $request->validate([
            'cpanel_domain' => 'nullable|string|max:255',
            'cpanel_db_host' => 'nullable|string|max:255',
            'cpanel_db_name' => 'nullable|string|max:255',
            'cpanel_db_user' => 'nullable|string|max:255',
            'cpanel_db_password' => 'nullable|string|max:255',
            'cpanel_api_key' => 'nullable|string|max:255',
            'logo_url' => 'nullable|string|max:1000',
            'white_logo_url' => 'nullable|string|max:1000',
            'favicon_url' => 'nullable|string|max:1000',
            'primary_color' => 'nullable|string|max:50',
            'secondary_color' => 'nullable|string|max:50',
        ]);

        $templateRequest = TemplateRequest::findOrFail($id);

        if (! $templateRequest->canPromoteToLive()) {
            return response()->json([
                'message' => $templateRequest->isLive()
                    ? 'This site is already live.'
                    : 'Deploy to live is only available after the requester submits a go-live request.',
            ], 422);
        }

        $liveDomain = trim((string) (
            $request->input('cpanel_domain')
            ?: $templateRequest->domain_name
            ?: $templateRequest->cpanel_domain
        ));

        if ($liveDomain === '') {
            return response()->json([
                'message' => 'A main/live domain is required to deploy this site live.',
            ], 422);
        }

        $updates = [
            'status' => TemplateRequest::STATUS_LIVE,
            'domain_name' => $liveDomain,
            'cpanel_domain' => $liveDomain,
            'live_promoted_at' => now(),
        ];

        if ($request->filled('cpanel_db_host')) {
            $updates['cpanel_db_host'] = $request->cpanel_db_host;
        }
        if ($request->filled('cpanel_db_name')) {
            $updates['cpanel_db_name'] = $request->cpanel_db_name;
        }
        if ($request->filled('cpanel_db_user')) {
            $updates['cpanel_db_user'] = $request->cpanel_db_user;
        }
        if ($request->filled('cpanel_db_password') || $request->filled('cpanel_db_pass')) {
            $updates['cpanel_db_password'] = $request->cpanel_db_password ?? $request->input('cpanel_db_pass');
        }
        if ($request->filled('cpanel_api_key')) {
            $updates['cpanel_api_key'] = $request->cpanel_api_key;
        }

        $updates = array_merge($updates, $this->brandingUpdatesFromRequest($request));

        $templateRequest->update($updates);
        $templateRequest->refresh();

        $targetAdvisorId = $templateRequest->assigned_advisor_id ?? $templateRequest->advisor_id;
        $configSynced = CpanelSyncService::pushDeployConfig($templateRequest, $targetAdvisorId);
        $contentSynced = false;
        if ($targetAdvisorId) {
            $contentSynced = CpanelSyncService::pushToTemplateRequestCpanel($templateRequest);
        }

        $this->activityLogs->log([
            'action' => 'wc.template_request.deploy_live',
            'description' => 'Deployed go-live request to main URL: '.$liveDomain
                .(filled($templateRequest->staging_domain) ? ' (from staging '.$templateRequest->staging_domain.')' : '')
                .($configSynced ? ' (remote config written)' : ' (remote config sync failed)')
                .($contentSynced ? ' (content pushed)' : ''),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        $message = $configSynced
            ? 'Go-live request deployed to the main URL. Compliance continues against the live site.'
            : 'Site marked live, but remote cPanel config could not be verified. Check Laravel logs and that api.php is reachable.';

        return response()->json([
            'message' => $message,
            'config_synced' => $configSynced,
            'content_synced' => $contentSynced,
            'template_request' => $templateRequest->load($this->requestRelations()),
        ]);
    }

    public function assignAdvisor(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_assign_website_templates');

        $request->validate([
            'assigned_advisor_id' => ['required', Rule::exists(User::class, 'id')],
        ]);

        $templateRequest = TemplateRequest::with($this->requestRelations())->findOrFail($id);

        $requester = $templateRequest->requestedBy;
        $requestedByAdvisor = $requester
            ? ($requester->isAdvisor() || $requester->role === 'editor')
            : (bool) $templateRequest->advisor_id;

        if ($requestedByAdvisor) {
            return response()->json([
                'message' => 'This deployment was requested by an advisor and cannot be reassigned.',
            ], 422);
        }

        $this->firmVisibility->assertActorCanActOnRequest(
            $user,
            $templateRequest->requested_by_id ? (int) $templateRequest->requested_by_id : null,
            $requester?->firm_id ? (int) $requester->firm_id : null,
            $templateRequest->assigned_advisor_id ? (int) $templateRequest->assigned_advisor_id : null
        );

        $this->assertAssignableAdvisor(
            $user,
            (int) $request->assigned_advisor_id,
            $requester
        );

        // Only store the assignment. Sections are created when Power Admin deploys.
        $templateRequest->update([
            'assigned_advisor_id' => $request->assigned_advisor_id,
        ]);

        $this->activityLogs->log([
            'action' => 'wc.template_request.assign_advisor',
            'description' => 'Advisor ID '.$request->assigned_advisor_id.' assigned to deployment for domain: '.$templateRequest->domain_name,
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        return response()->json($templateRequest->load($this->requestRelations()));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_deploy_websites');

        $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        $templateRequest = TemplateRequest::findOrFail($id);

        $templateRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);

        $this->activityLogs->log([
            'action' => 'wc.template_request.reject',
            'description' => 'Template deployment request rejected: '
                .PlainText::fromHtml($request->rejection_reason),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        return response()->json(['message' => 'Template deployment request rejected.']);
    }

    public function sections(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        $templateRequest = TemplateRequest::with($this->requestRelations())->findOrFail($id);

        $isPowerAdminAccess = $this->gate->can($user, 'wc_manage_deployment_sections')
            || $this->gate->can($user, 'wc_publish_live_content');
        $websiteAdvisorId = $this->actingAdvisors->websiteAdvisorId($user);
        $isOwnerAdvisor = $websiteAdvisorId && (
            (int) ($templateRequest->advisor_id ?? 0) === (int) $websiteAdvisorId
            || (int) ($templateRequest->assigned_advisor_id ?? 0) === (int) $websiteAdvisorId
        );

        if (! $isPowerAdminAccess && ! $isOwnerAdvisor) {
            // Staff who assign / view deployments may read section metadata (not edit).
            $isStaffViewer = $this->gate->can($user, 'wc_assign_website_templates')
                || $this->gate->can($user, 'wc_view_all_deployments')
                || $this->gate->can($user, 'wc_request_deployments');

            if (! $isStaffViewer) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $advisorId = $templateRequest->assigned_advisor_id ?? $templateRequest->advisor_id;

        if (! $advisorId) {
            return response()->json([
                'template_request' => $templateRequest,
                'sections' => [],
                'message' => 'No advisor assigned to this deployment.',
            ]);
        }

        // Do not materialize hub sections before Power Admin deploys to staging/live.
        if (! $templateRequest->isOnSite()) {
            return response()->json([
                'template_request' => $templateRequest,
                'sections' => [],
                'message' => 'Sections are created after this site is deployed to staging.',
            ]);
        }

        $page = Page::where('slug', 'home')->first();
        if (! $page) {
            return response()->json([
                'template_request' => $templateRequest,
                'sections' => [],
            ]);
        }

        AdvisorSectionService::ensureForAdvisor(
            (int) $advisorId,
            $templateRequest->template_name ?: HubTemplateCatalog::defaultSlug(),
            false,
            (int) $templateRequest->id
        );

        $sections = Section::where('page_id', $page->id)
            ->where('advisor_id', $advisorId)
            ->where('template_request_id', $templateRequest->id)
            ->orderBy('id')
            ->get()
            ->unique('name')
            ->values();

        return response()->json([
            'template_request' => $templateRequest,
            'sections' => $sections,
        ]);
    }

    public function updateSections(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_manage_deployment_sections');

        $request->validate([
            'sections' => 'required|array|min:1',
            'sections.*.id' => ['required', Rule::exists(Section::class, 'id')],
            'sections.*.display_name' => 'nullable|string|max:255',
            'sections.*.is_visible' => 'nullable|boolean',
        ]);

        $templateRequest = TemplateRequest::findOrFail($id);
        $advisorId = $templateRequest->assigned_advisor_id ?? $templateRequest->advisor_id;

        if (! $advisorId) {
            return response()->json(['message' => 'No advisor assigned to this deployment.'], 422);
        }

        $updated = [];

        foreach ($request->sections as $item) {
            $section = Section::findOrFail($item['id']);

            if ((int) $section->advisor_id !== (int) $advisorId) {
                return response()->json([
                    'message' => "Section \"{$section->name}\" does not belong to this deployment.",
                ], 422);
            }

            if ((int) ($section->template_request_id ?? 0) !== (int) $templateRequest->id) {
                return response()->json([
                    'message' => "Section \"{$section->name}\" does not belong to this template request.",
                ], 422);
            }

            $updates = [];
            if (array_key_exists('display_name', $item)) {
                $updates['display_name'] = $item['display_name'] ?: null;
            }
            if (array_key_exists('is_visible', $item)) {
                // Avoid PHP (bool)"false" === true — accept real bools and common string forms.
                $raw = $item['is_visible'];
                $updates['is_visible'] = ! in_array($raw, [false, 0, '0', 'false', 'no', 'off'], true);
            }

            if ($updates !== []) {
                $section->update($updates);
                $updated[] = $section->fresh();
            }
        }

        $cpanelSynced = false;
        $syncDetails = null;
        if ($updated !== []) {
            $syncDetails = CpanelSyncService::pushToTemplateRequestCpanelWithDetails($templateRequest);
            $cpanelSynced = (bool) ($syncDetails['ok'] ?? false);

            $this->activityLogs->log([
                'action' => 'wc.template_request.sections_update',
                'description' => 'Updated '.count($updated).' section(s) for deployment'
                    .($cpanelSynced ? ' (cPanel synced)' : ' (cPanel sync skipped or failed)'),
                'user' => $user,
                'subject' => $templateRequest,
                'request' => $request,
            ]);
        }

        if ($cpanelSynced) {
            return response()->json([
                'message' => 'Section settings saved and synced to the deployed site.',
                'cpanel_synced' => true,
                'cpanel_endpoint' => $syncDetails['endpoint'] ?? null,
                'sections' => $updated,
            ]);
        }

        $detail = is_array($syncDetails) ? trim((string) ($syncDetails['message'] ?? '')) : '';
        $endpoint = is_array($syncDetails) ? ($syncDetails['endpoint'] ?? null) : null;
        $httpStatus = is_array($syncDetails) ? ($syncDetails['http_status'] ?? null) : null;

        $message = 'Section settings saved in the hub, but the live advisor site was not updated.';
        if ($detail !== '') {
            $message .= ' '.$detail;
        }
        if ($endpoint) {
            $message .= ' (tried: '.$endpoint.($httpStatus ? ', HTTP '.$httpStatus : '').')';
        } else {
            $message .= ' Check cPanel domain / API key on the deployment.';
        }

        return response()->json([
            'message' => $message,
            'cpanel_synced' => false,
            'cpanel_endpoint' => $endpoint,
            'cpanel_http_status' => $httpStatus,
            'sections' => $updated,
        ], 502);
    }

    public function publishContent(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_publish_live_content');

        $request->validate([
            'section_edits' => 'required|array|min:1',
            'section_edits.*.section_id' => ['required', Rule::exists(Section::class, 'id')],
            'section_edits.*.content' => 'required|string',
        ]);

        $templateRequest = TemplateRequest::findOrFail($id);

        if (! $templateRequest->isOnSite()) {
            return response()->json(['message' => 'Can only publish content for staging or live sites.'], 422);
        }

        $advisorId = $templateRequest->assigned_advisor_id ?? $templateRequest->advisor_id;

        if (! $advisorId) {
            return response()->json(['message' => 'No advisor assigned to this deployment.'], 422);
        }

        $updatedSections = [];

        foreach ($request->section_edits as $edit) {
            $section = Section::findOrFail($edit['section_id']);

            if ((int) $section->advisor_id !== (int) $advisorId) {
                return response()->json([
                    'message' => "Section \"{$section->name}\" does not belong to this deployment.",
                ], 422);
            }

            if ((int) ($section->template_request_id ?? 0) !== (int) $templateRequest->id) {
                $scoped = Section::where('advisor_id', $advisorId)
                    ->where('template_request_id', $templateRequest->id)
                    ->where('name', $section->name)
                    ->first();

                if (! $scoped) {
                    return response()->json([
                        'message' => "Section \"{$section->name}\" does not belong to this template request.",
                    ], 422);
                }

                $section = $scoped;
            }

            $section->update([
                'content' => $edit['content'],
                'is_locked' => false,
                'locked_by' => null,
            ]);

            $updatedSections[] = [
                'name' => $section->name,
                'display_name' => $section->display_name ?: $section->name,
                'is_visible' => $section->is_visible !== false,
                'content' => $edit['content'],
            ];
        }

        $cpanelSynced = CpanelSyncService::pushToTemplateRequestCpanel($templateRequest, $updatedSections);
        $targetLabel = $templateRequest->isLive() ? 'live' : 'staging';

        $this->activityLogs->log([
            'action' => 'wc.template_request.publish_content',
            'description' => 'Published '.count($updatedSections)." section(s) directly to {$targetLabel}"
                .' ('.$templateRequest->cpanel_domain.')'
                .($cpanelSynced ? ' (cPanel synced)' : ' (cPanel sync skipped or failed)'),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        return response()->json([
            'message' => $cpanelSynced
                ? "Content published directly to the {$targetLabel} site."
                : "Content saved in the hub database, but the {$targetLabel} site was not updated. Check Laravel logs and cPanel configuration.",
            'cpanel_synced' => $cpanelSynced,
        ]);
    }

    /**
     * Power Admin: update site branding (logo / white logo / favicon / colours)
     * during or after deployment. When the site is already deployed, push branding
     * to the advisor cPanel site_settings.
     */
    public function updateBranding(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_deploy_websites');

        $request->validate([
            'logo_url' => 'nullable|string|max:1000',
            'white_logo_url' => 'nullable|string|max:1000',
            'favicon_url' => 'nullable|string|max:1000',
            'primary_color' => 'nullable|string|max:50',
            'secondary_color' => 'nullable|string|max:50',
        ]);

        $templateRequest = TemplateRequest::findOrFail($id);
        $updates = $this->brandingUpdatesFromRequest($request);

        if ($updates === []) {
            return response()->json([
                'message' => 'No branding fields were provided.',
            ], 422);
        }

        $templateRequest->update($updates);
        $templateRequest->refresh();

        $configSynced = false;
        $syncDetail = null;
        $targetAdvisorId = $templateRequest->assigned_advisor_id ?? $templateRequest->advisor_id;

        if ($templateRequest->isOnSite() && filled($templateRequest->cpanel_domain)) {
            $syncDetail = CpanelSyncService::pushBrandingToCpanel($templateRequest, $targetAdvisorId);
            $configSynced = (bool) ($syncDetail['ok'] ?? false);
        }

        $this->activityLogs->log([
            'action' => 'wc.template_request.update_branding',
            'description' => 'Updated branding for domain: '.($templateRequest->domain_name ?: $templateRequest->cpanel_domain)
                .($configSynced
                    ? ' (remote config written)'
                    : ' (remote sync failed: '.($syncDetail['message'] ?? 'unknown').')'),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        $message = 'Branding updated successfully.';
        if ($templateRequest->isOnSite()) {
            if ($configSynced) {
                $message = 'Branding updated and pushed to the deployed site.';
            } else {
                $reason = is_array($syncDetail) && filled($syncDetail['message'] ?? null)
                    ? (string) $syncDetail['message']
                    : 'Check Laravel logs and cPanel configuration.';
                $message = 'Branding saved on the hub, but the site sync could not be verified. '.$reason;
            }
        }

        return response()->json([
            'message' => $message,
            'config_synced' => $configSynced,
            'sync' => $syncDetail,
            'template_request' => $templateRequest->load($this->requestRelations()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function brandingUpdatesFromRequest(Request $request): array
    {
        $updates = [];

        foreach (['logo_url', 'white_logo_url', 'favicon_url'] as $key) {
            if (! $request->exists($key)) {
                continue;
            }
            // Skip white_logo_url when the WC DB has not been migrated yet.
            if ($key === 'white_logo_url' && ! $this->templateRequestsHaveWhiteLogoColumn()) {
                continue;
            }
            $raw = $request->input($key);
            $updates[$key] = filled($raw) ? CpanelSyncService::absoluteAssetUrl($raw) : null;
        }

        if ($request->filled('primary_color')) {
            $updates['primary_color'] = BrandColor::toHex($request->primary_color, '#0B1B3D');
        }

        if ($request->filled('secondary_color')) {
            $updates['secondary_color'] = BrandColor::toHex($request->secondary_color, '#C8102E');
        }

        return $updates;
    }

    private function templateRequestsHaveWhiteLogoColumn(): bool
    {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }

        try {
            $connection = (new TemplateRequest)->getConnectionName()
                ?: config('database.default');
            $hasColumn = \Illuminate\Support\Facades\Schema::connection($connection)
                ->hasColumn('wc_template_requests', 'white_logo_url');
        } catch (\Throwable) {
            $hasColumn = false;
        }

        return $hasColumn;
    }

    /**
     * @param  mixed  $raw
     * @return list<array{name: string, attachment_url: string, attachment_name: string}>
     */
    private function normalizeServices(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $services = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $attachment = $this->normalizeAttachmentFields($row);
            if ($name === '' && $attachment['attachment_url'] === '') {
                continue;
            }
            if ($name === '') {
                $name = 'Service '.((int) $index + 1);
            }

            $services[] = array_merge([
                'name' => Str::limit($name, 150, ''),
            ], $attachment);
        }

        return array_values($services);
    }

    /**
     * @param  mixed  $raw
     * @return list<array{url: string, label: string}>
     */
    private function normalizeImages(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $images = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $absolute = CpanelSyncService::absoluteAssetUrl($url) ?: $url;
            $images[] = [
                'url' => Str::limit((string) $absolute, 1000, ''),
                'label' => Str::limit(trim((string) ($row['label'] ?? '')), 150, ''),
            ];
        }

        return array_values($images);
    }

    /**
     * @param  mixed  $raw
     * @return array{phone: string, email: string, address: string, website: string}|null
     */
    private function normalizeContactDetails(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $phone = trim((string) ($raw['phone'] ?? ''));
        $email = trim((string) ($raw['email'] ?? ''));
        $address = trim((string) ($raw['address'] ?? ''));
        $website = trim((string) ($raw['website'] ?? ''));

        if ($phone === '' && $email === '' && $address === '' && $website === '') {
            return null;
        }

        return [
            'phone' => Str::limit($phone, 80, ''),
            'email' => Str::limit($email, 255, ''),
            'address' => Str::limit($address, 500, ''),
            'website' => Str::limit($website, 255, ''),
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array{name: string, attachment_url: string, attachment_name: string}>
     */
    private function normalizePolicies(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $policies = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $attachment = $this->normalizeAttachmentFields($row);
            if ($name === '' && $attachment['attachment_url'] === '') {
                continue;
            }
            if ($name === '') {
                $name = 'Policy '.((int) $index + 1);
            }

            $policies[] = array_merge([
                'name' => Str::limit($name, 150, ''),
            ], $attachment);
        }

        return array_values($policies);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{attachment_url: string, attachment_name: string}
     */
    private function normalizeAttachmentFields(array $row): array
    {
        $url = trim((string) ($row['attachment_url'] ?? $row['url'] ?? ''));
        $name = trim((string) ($row['attachment_name'] ?? $row['original_name'] ?? $row['filename'] ?? ''));
        if ($url === '') {
            return ['attachment_url' => '', 'attachment_name' => ''];
        }

        $absolute = CpanelSyncService::absoluteAssetUrl($url) ?: $url;

        return [
            'attachment_url' => Str::limit((string) $absolute, 1000, ''),
            'attachment_name' => Str::limit($name !== '' ? $name : basename(parse_url($url, PHP_URL_PATH) ?: $url), 255, ''),
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeSelectedPages(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $pages = [];
        $seen = [];
        foreach ($raw as $item) {
            $slug = Str::slug(trim((string) $item));
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $pages[] = Str::limit($slug, 150, '');
        }

        return array_values($pages);
    }

    /**
     * Page content is supplied as document attachments keyed by page slug.
     *
     * @param  mixed  $raw
     * @return array<string, array{url: string, name: string}>
     */
    private function normalizePageContents(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $contents = [];
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $slug = Str::slug(trim((string) ($value['slug'] ?? $key)));
                $url = trim((string) ($value['url'] ?? $value['attachment_url'] ?? ''));
                $name = trim((string) ($value['name'] ?? $value['attachment_name'] ?? $value['original_name'] ?? ''));
            } else {
                // Legacy plain-text entries are ignored — content is document-only now.
                continue;
            }

            if ($slug === '' || $url === '') {
                continue;
            }

            $absolute = CpanelSyncService::absoluteAssetUrl($url) ?: $url;
            $contents[$slug] = [
                'url' => Str::limit((string) $absolute, 1000, ''),
                'name' => Str::limit(
                    $name !== '' ? $name : basename(parse_url($url, PHP_URL_PATH) ?: $url),
                    255,
                    ''
                ),
            ];
        }

        return $contents;
    }
}
