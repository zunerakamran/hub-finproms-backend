<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\ActivityLogService;
use App\Services\ActingAdvisorService;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use App\Services\WebsiteCompliance\CpanelSyncService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use App\Support\WebsiteCompliance\BrandColor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TemplateRequestController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly ActivityLogService $activityLogs,
        private readonly ActingAdvisorService $actingAdvisors
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
        return ['advisor', 'assignedAdvisor', 'requestedBy'];
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
            'assigned_advisor_id' => $mustAssignAdvisor || $request->filled('assigned_advisor_id')
                ? $request->assigned_advisor_id
                : null,
            'domain_name' => $request->domain_name,
            'logo_url' => CpanelSyncService::absoluteAssetUrl($request->logo_url),
            'white_logo_url' => CpanelSyncService::absoluteAssetUrl($request->white_logo_url),
            'favicon_url' => CpanelSyncService::absoluteAssetUrl($request->favicon_url),
            'primary_color' => BrandColor::toHex($request->primary_color ?? null, '#0B1B3D'),
            'secondary_color' => BrandColor::toHex($request->secondary_color ?? null, '#C8102E'),
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
            $requests = $query->latest()->get();
        } elseif (
            $this->gate->can($user, 'wc_request_deployments')
            || $this->gate->can($user, 'wc_assign_website_templates')
        ) {
            $scopeId = $this->actingAdvisors->websiteAdvisorId($user) ?? (int) $user->id;
            $requests = $query
                ->where(function ($q) use ($user, $scopeId) {
                    $q->where('advisor_id', $scopeId)
                        ->orWhere('assigned_advisor_id', $scopeId)
                        ->orWhere('requested_by_id', $user->id);
                    if ($scopeId !== (int) $user->id) {
                        $q->orWhere('advisor_id', $user->id)
                            ->orWhere('assigned_advisor_id', $user->id);
                    }
                })
                ->latest()
                ->get();
        } else {
            $scopeId = $this->actingAdvisors->websiteAdvisorId($user) ?? (int) $user->id;
            $requests = $query
                ->where(function ($q) use ($scopeId) {
                    $q->where('advisor_id', $scopeId)
                        ->orWhere('assigned_advisor_id', $scopeId);
                })
                ->latest()
                ->get();
        }

        return response()->json($requests);
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

        $updates = [
            'status' => 'deployed',
            'cpanel_domain' => $request->cpanel_domain,
            'cpanel_db_host' => $request->cpanel_db_host,
            'cpanel_db_name' => $request->cpanel_db_name,
            'cpanel_db_user' => $request->cpanel_db_user,
            'cpanel_db_password' => $request->cpanel_db_password ?? $request->input('cpanel_db_pass'),
            'cpanel_api_key' => $request->cpanel_api_key,
        ];

        $updates = array_merge($updates, $this->brandingUpdatesFromRequest($request));

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
            'action' => 'wc.template_request.deploy',
            'description' => 'Deployed template to cPanel domain: '.$request->cpanel_domain
                .($configSynced ? ' (remote config written)' : ' (remote config sync failed)')
                .($contentSynced ? ' (initial content pushed)' : '')
                .($targetAdvisorId
                    ? " (hub sections for advisor {$targetAdvisorId}: {$hubSectionsCount})"
                    : ' (NO advisor_id — hub sections were not created)'),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        $message = $configSynced
            ? 'Template deployed and remote cPanel config written successfully!'
            : 'Template marked deployed, but remote cPanel config could not be verified. Check Laravel logs and that api.php is reachable.';

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
            'description' => 'Template deployment request rejected: '.$request->rejection_reason,
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
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $advisorId = $templateRequest->assigned_advisor_id ?? $templateRequest->advisor_id;

        if (! $advisorId) {
            return response()->json([
                'template_request' => $templateRequest,
                'sections' => [],
                'message' => 'No advisor assigned to this deployment.',
            ]);
        }

        // Do not materialize hub sections before Power Admin deploys.
        if ($templateRequest->status !== 'deployed') {
            return response()->json([
                'template_request' => $templateRequest,
                'sections' => [],
                'message' => 'Sections are created after this site is deployed.',
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

        if ($templateRequest->status !== 'deployed') {
            return response()->json(['message' => 'Can only publish content for deployed sites.'], 422);
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

        $this->activityLogs->log([
            'action' => 'wc.template_request.publish_content',
            'description' => 'Published '.count($updatedSections).' section(s) directly to live'
                .($cpanelSynced ? ' (cPanel synced)' : ' (cPanel sync skipped or failed)'),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        return response()->json([
            'message' => $cpanelSynced
                ? 'Content published directly to the live site.'
                : 'Content saved in the hub database, but the live site was not updated. Check Laravel logs and cPanel configuration.',
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
        $updates = $this->brandingUpdatesFromRequest($request, true);

        if ($updates === []) {
            return response()->json([
                'message' => 'No branding fields were provided.',
            ], 422);
        }

        $templateRequest->update($updates);
        $templateRequest->refresh();

        $configSynced = false;
        $targetAdvisorId = $templateRequest->assigned_advisor_id ?? $templateRequest->advisor_id;

        if ($templateRequest->status === 'deployed' && filled($templateRequest->cpanel_domain)) {
            $configSynced = CpanelSyncService::pushDeployConfig($templateRequest, $targetAdvisorId);
        }

        $this->activityLogs->log([
            'action' => 'wc.template_request.update_branding',
            'description' => 'Updated branding for domain: '.($templateRequest->domain_name ?: $templateRequest->cpanel_domain)
                .($configSynced ? ' (remote config written)' : ''),
            'user' => $user,
            'subject' => $templateRequest,
            'request' => $request,
        ]);

        $message = 'Branding updated successfully.';
        if ($templateRequest->status === 'deployed') {
            $message = $configSynced
                ? 'Branding updated and pushed to the live site.'
                : 'Branding saved on the hub, but the live site sync could not be verified. Check Laravel logs and cPanel configuration.';
        }

        return response()->json([
            'message' => $message,
            'config_synced' => $configSynced,
            'template_request' => $templateRequest->load($this->requestRelations()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function brandingUpdatesFromRequest(Request $request, bool $requirePresence = false): array
    {
        $updates = [];

        foreach (['logo_url', 'white_logo_url', 'favicon_url'] as $key) {
            if ($requirePresence && ! $request->exists($key)) {
                continue;
            }
            if (! $requirePresence && ! $request->exists($key)) {
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
}
