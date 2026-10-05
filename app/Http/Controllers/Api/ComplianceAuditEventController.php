<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComplianceAuditEvent;
use App\Services\ComplianceAuditTrailService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hub-wide compliance audit trail (SMC / GC / WC) for the dedicated dashboard page.
 * Gated by dashboard_view_compliance_audit_trail.
 */
class ComplianceAuditEventController extends Controller
{
    public function __construct(
        private readonly ComplianceAuditTrailService $auditTrail,
        private readonly HubService $hubs
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['required', 'string', Rule::in([
                ComplianceAuditEvent::MODULE_SMC,
                ComplianceAuditEvent::MODULE_GC,
                ComplianceAuditEvent::MODULE_WC,
            ])],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'event_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $hub = $this->hubs->current();
        $module = (string) $validated['module'];

        $listed = $this->auditTrail->paginateForHub($module, $hub, [
            'q' => $validated['q'] ?? null,
            'event_type' => $validated['event_type'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'per_page' => $validated['per_page'] ?? 100,
            'page' => $validated['page'] ?? 1,
        ]);

        return response()->json([
            'module' => $module,
            'events' => $listed['events'],
            'meta' => $listed['meta'],
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'modules' => [
                [
                    'key' => ComplianceAuditEvent::MODULE_SMC,
                    'label' => 'Social Media Compliance',
                    'module_capability' => 'module_social_media_compliance',
                ],
                [
                    'key' => ComplianceAuditEvent::MODULE_GC,
                    'label' => 'General Compliance',
                    'module_capability' => 'module_general_compliance',
                ],
                [
                    'key' => ComplianceAuditEvent::MODULE_WC,
                    'label' => 'Website Content Pre Approval',
                    'module_capability' => 'module_website_compliance',
                ],
            ],
        ]);
    }
}
