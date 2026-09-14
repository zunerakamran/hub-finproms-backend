<?php

namespace App\Services;

use App\Models\GeneralComplianceRequest;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeneralComplianceMailService
{
    public function __construct(
        private readonly HubMailService $mail,
        private readonly HubService $hubs
    ) {}

    public function notifyRequestSubmitted(GeneralComplianceRequest $request, User $subscriber): void
    {
        $hub = $this->hubs->current();
        $preview = $this->preview($request->currentVersionRow?->description);

        try {
            $this->mail->sendToAdmins(
                subject: 'New General Compliance Request Submitted',
                eyebrow: 'General Compliance',
                heading: 'New general compliance request awaiting assignment',
                intro: 'A new general compliance request has been submitted and requires assignment to a reviewer.',
                fields: [
                    ['label' => 'Request ID', 'value' => '#'.$request->id],
                    ['label' => 'Submitted by', 'value' => $subscriber->name.' ('.$subscriber->email.')'],
                    ['label' => 'Description', 'value' => $preview],
                    ['label' => 'Status', 'value' => 'Pending assignment'],
                ],
                cta: $this->cta($hub, $request),
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

        try {
            $this->mail->sendToUser(
                $approver,
                subject: 'General Compliance Request Assigned for Review',
                eyebrow: 'General Compliance',
                heading: 'A general compliance request was assigned to you',
                intro: 'Please review the submitted material and provide your assessment.',
                fields: [
                    ['label' => 'Request ID', 'value' => '#'.$request->id],
                    ['label' => 'Assigned by', 'value' => $assignedBy->name],
                    ['label' => 'Status', 'value' => 'Awaiting review'],
                ],
                cta: $this->cta($hub, $request),
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
        $intro = match ($status) {
            GeneralComplianceRequest::STATUS_APPROVED => 'Your submission has been reviewed and approved.',
            GeneralComplianceRequest::STATUS_REJECTED => 'Your submission was not approved. Please review the feedback and resubmit if needed.',
            GeneralComplianceRequest::STATUS_APPROVED_WITH_FEEDBACK => 'Your submission was approved subject to the feedback below. Please address the notes before publishing.',
            default => 'Your general compliance request status has been updated.',
        };

        $fields = [
            ['label' => 'Request ID', 'value' => '#'.$request->id],
            ['label' => 'Reviewed by', 'value' => $reviewerName],
            ['label' => 'Decision', 'value' => $status],
        ];
        if (filled($feedback)) {
            $fields[] = ['label' => 'Feedback', 'value' => $feedback];
        }

        try {
            $this->mail->sendToUser(
                $subscriber,
                subject: 'Update on Your General Compliance Request',
                eyebrow: 'General Compliance',
                heading: 'General compliance decision: '.$status,
                intro: $intro,
                fields: $fields,
                cta: $this->cta($hub, $request),
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

        try {
            $this->mail->sendToUser(
                $approver,
                subject: 'General Compliance Request Resubmitted for Review',
                eyebrow: 'General Compliance',
                heading: 'A general compliance request was resubmitted',
                intro: 'The adviser has updated and resubmitted a previously returned request for your review.',
                fields: [
                    ['label' => 'Request ID', 'value' => '#'.$request->id],
                    ['label' => 'Resubmitted by', 'value' => $subscriber->name.' ('.$subscriber->email.')'],
                    ['label' => 'Updated description', 'value' => $preview],
                    ['label' => 'Status', 'value' => 'Awaiting re-review'],
                ],
                cta: $this->cta($hub, $request),
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
