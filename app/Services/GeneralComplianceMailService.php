<?php

namespace App\Services;

use App\Models\GeneralComplianceRequest;
use App\Models\Hub;
use App\Models\User;
use App\Support\EmailTemplateCatalog;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeneralComplianceMailService
{
    public function __construct(
        private readonly HubMailService $mail,
        private readonly HubService $hubs,
        private readonly EmailTemplateService $emailTemplates
    ) {}

    public function notifyRequestSubmitted(GeneralComplianceRequest $request, User $subscriber): void
    {
        $hub = $this->hubs->current();
        $preview = $this->preview($request->currentVersionRow?->description);
        $copy = $this->emailTemplates->resolve($hub, 'gc_request_submitted', EmailTemplateCatalog::AUDIENCE_ADMIN, [
            'site_name' => $hub->name,
            'request_id' => (string) $request->id,
            'user_name' => $subscriber->name,
            'user_email' => $subscriber->email,
        ]);

        try {
            $cta = $this->cta($hub, $request);
            if ($copy['cta_label']) {
                $cta['label'] = $copy['cta_label'];
            }

            $this->mail->sendToAdmins(
                subject: $copy['subject'],
                eyebrow: $copy['eyebrow'],
                heading: $copy['heading'],
                intro: $copy['intro'],
                fields: [
                    ['label' => 'Request ID', 'value' => '#'.$request->id],
                    ['label' => 'Submitted by', 'value' => $subscriber->name.' ('.$subscriber->email.')'],
                    ['label' => 'Description', 'value' => $preview],
                    ['label' => 'Status', 'value' => 'Pending assignment'],
                ],
                cta: $cta,
                closing: $copy['closing'],
                excludeEmail: $subscriber->email,
                hub: $hub
            );
        } catch (Throwable $e) {
            Log::warning('General compliance submit email failed.', ['error' => $e->getMessage()]);
        }
    }

    public function notifyApproverAssigned(GeneralComplianceRequest $request, User $approver, User $assignedBy): void
    {
        $hub = $this->hubs->current();
        $copy = $this->emailTemplates->resolve($hub, 'gc_approver_assigned', EmailTemplateCatalog::AUDIENCE_USER, [
            'request_id' => (string) $request->id,
            'assigned_by' => $assignedBy->name,
            'site_name' => $hub->name,
        ]);

        try {
            $cta = $this->cta($hub, $request);
            if ($copy['cta_label']) {
                $cta['label'] = $copy['cta_label'];
            }

            $this->mail->sendToUser(
                $approver,
                subject: $copy['subject'],
                eyebrow: $copy['eyebrow'],
                heading: $copy['heading'],
                intro: $copy['intro'],
                fields: [
                    ['label' => 'Request ID', 'value' => '#'.$request->id],
                    ['label' => 'Assigned by', 'value' => $assignedBy->name],
                    ['label' => 'Status', 'value' => 'Awaiting review'],
                ],
                cta: $cta,
                closing: $copy['closing'],
                hub: $hub
            );
        } catch (Throwable $e) {
            Log::warning('General compliance assign email failed.', ['error' => $e->getMessage()]);
        }
    }

    public function notifyStatusUpdated(
        GeneralComplianceRequest $request,
        User $subscriber,
        string $status,
        ?string $feedback,
        string $reviewerName
    ): void {
        $hub = $this->hubs->current();
        $statusMessage = match ($status) {
            GeneralComplianceRequest::STATUS_APPROVED => 'Your submission has been reviewed and approved.',
            GeneralComplianceRequest::STATUS_REJECTED => 'Your submission was not approved. Please review the feedback and resubmit if needed.',
            GeneralComplianceRequest::STATUS_APPROVED_WITH_FEEDBACK => 'Your submission was approved subject to the feedback below. Please address the notes before publishing.',
            default => 'Your general compliance request status has been updated.',
        };

        $copy = $this->emailTemplates->resolve($hub, 'gc_status_updated', EmailTemplateCatalog::AUDIENCE_USER, [
            'request_id' => (string) $request->id,
            'status' => $status,
            'status_message' => $statusMessage,
            'reviewer_name' => $reviewerName,
            'site_name' => $hub->name,
        ]);

        $fields = [
            ['label' => 'Request ID', 'value' => '#'.$request->id],
            ['label' => 'Reviewed by', 'value' => $reviewerName],
            ['label' => 'Decision', 'value' => $status],
        ];
        if (filled($feedback)) {
            $fields[] = ['label' => 'Feedback', 'value' => $feedback];
        }

        try {
            $cta = $this->cta($hub, $request);
            if ($copy['cta_label']) {
                $cta['label'] = $copy['cta_label'];
            }

            $this->mail->sendToUser(
                $subscriber,
                subject: $copy['subject'],
                eyebrow: $copy['eyebrow'],
                heading: $copy['heading'],
                intro: $copy['intro'],
                fields: $fields,
                cta: $cta,
                closing: $copy['closing'],
                hub: $hub
            );
        } catch (Throwable $e) {
            Log::warning('General compliance status email failed.', ['error' => $e->getMessage()]);
        }
    }

    public function notifyResubmitted(GeneralComplianceRequest $request, User $approver, User $subscriber): void
    {
        $hub = $this->hubs->current();
        $preview = $this->preview($request->currentVersionRow?->description);
        $copy = $this->emailTemplates->resolve($hub, 'gc_request_resubmitted', EmailTemplateCatalog::AUDIENCE_USER, [
            'request_id' => (string) $request->id,
            'user_name' => $subscriber->name,
            'user_email' => $subscriber->email,
            'site_name' => $hub->name,
        ]);

        try {
            $cta = $this->cta($hub, $request);
            if ($copy['cta_label']) {
                $cta['label'] = $copy['cta_label'];
            }

            $this->mail->sendToUser(
                $approver,
                subject: $copy['subject'],
                eyebrow: $copy['eyebrow'],
                heading: $copy['heading'],
                intro: $copy['intro'],
                fields: [
                    ['label' => 'Request ID', 'value' => '#'.$request->id],
                    ['label' => 'Resubmitted by', 'value' => $subscriber->name.' ('.$subscriber->email.')'],
                    ['label' => 'Updated description', 'value' => $preview],
                    ['label' => 'Status', 'value' => 'Awaiting re-review'],
                ],
                cta: $cta,
                closing: $copy['closing'],
                hub: $hub
            );
        } catch (Throwable $e) {
            Log::warning('General compliance resubmit email failed.', ['error' => $e->getMessage()]);
        }
    }

    private function preview(?string $description): string
    {
        $text = trim((string) $description);
        if ($text === '') {
            return '—';
        }

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157).'…' : $text;
    }

    /**
     * @return array{label: string, url: string}
     */
    private function cta(Hub $hub, GeneralComplianceRequest $request): array
    {
        $base = $hub->frontendBaseUrl();

        return [
            'label' => 'View request',
            'url' => $base.'/my-dashboard/general-compliance/'.$request->id,
        ];
    }
}
