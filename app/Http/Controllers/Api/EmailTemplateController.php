<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\EmailTemplateService;
use App\Support\EmailTemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EmailTemplateController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly EmailTemplateService $emailTemplates
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);

        return response()->json([
            'hub' => $this->hubPayload($hub),
            'events' => $this->emailTemplates->listEvents($hub),
            'admin_recipients_note' => 'Admin mail is sent to roles with “Receive admin emails” enabled in the Capabilities matrix.',
        ]);
    }

    public function show(Request $request, string $event): JsonResponse
    {
        $hub = $this->targetHub($request);

        try {
            $payload = $this->emailTemplates->showEvent($hub, $event);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'hub' => $this->hubPayload($hub),
            'event' => $payload,
        ]);
    }

    public function update(Request $request, string $event, string $audience): JsonResponse
    {
        if (! in_array($audience, [
            EmailTemplateCatalog::AUDIENCE_USER,
            EmailTemplateCatalog::AUDIENCE_ADMIN,
        ], true)) {
            return response()->json(['message' => 'Audience must be user or admin.'], 422);
        }

        $validated = $request->validate([
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'eyebrow' => ['sometimes', 'nullable', 'string', 'max:120'],
            'heading' => ['sometimes', 'nullable', 'string', 'max:255'],
            'intro' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'closing' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cta_label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $hub = $this->targetHub($request);

        try {
            $template = $this->emailTemplates->update($hub, $event, $audience, $validated);
        } catch (InvalidArgumentException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'unknown')
                || str_contains(strtolower($e->getMessage()), 'not enabled')
                ? 404
                : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        return response()->json([
            'message' => 'Email template updated successfully.',
            'hub' => $this->hubPayload($hub),
            'event_key' => $event,
            'template' => $template,
        ]);
    }

    public function reset(Request $request, string $event, string $audience): JsonResponse
    {
        if (! in_array($audience, [
            EmailTemplateCatalog::AUDIENCE_USER,
            EmailTemplateCatalog::AUDIENCE_ADMIN,
        ], true)) {
            return response()->json(['message' => 'Audience must be user or admin.'], 422);
        }

        $hub = $this->targetHub($request);

        try {
            $template = $this->emailTemplates->reset($hub, $event, $audience);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'Email template reset to defaults.',
            'hub' => $this->hubPayload($hub),
            'event_key' => $event,
            'template' => $template,
        ]);
    }

    private function targetHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub($request->user());
    }

    /**
     * @return array{id: int, name: string, slug: string, type: string}
     */
    private function hubPayload(Hub $hub): array
    {
        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'slug' => $hub->slug,
            'type' => $hub->type,
        ];
    }
}
