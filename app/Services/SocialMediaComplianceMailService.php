<?php

namespace App\Services;

use App\Models\SocialMediaComplianceRequest;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialMediaComplianceMailService
{
    public function __construct(
        private readonly HubMailService $mail,
        private readonly HubService $hubs
    ) {}

    public function notifyRequestSubmitted(SocialMediaComplianceRequest $request, User $subscriber): void
    {
        $hub = $this->hubs->current();
        $preview = $this->preview($request->currentVersionRow?->description);

        try {
            $this->mail->sendToAdmins(
                subject: 'New Social Media Compliance Request Submitted',
                eyebrow: 'Social Media Compliance',
                heading: 'New social media compliance request awaiting assignment',
                intro: 'A new social media compliance request has been submitted and requires assignment to a reviewer.',
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
            Log::warning('Social media compliance submit email failed.', ['error' => $e->getMessage()]);
        }
    }

    public function notifyApproverAssigned(SocialMediaComplianceRequest $request, User $approver, User $assignedBy): void
    {
        $hub = $this->hubs->current();

        try {
            $this->mail->sendToUser(
                $approver,
                subject: 'Social Media Compliance Request Assigned for Review',
                eyebrow: 'Social Media Compliance',
                heading: 'A social media compliance request was assigned to you',
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
            Log::warning('Social media compliance assign email failed.', ['error' => $e->getMessage()]);
        }
    }

    public function notifyStatusUpdated(
        SocialMediaComplianceRequest $request,
        User $subscriber,
        string $status,
        ?string $feedback,
        string $reviewerName
    ): void {
        $hub = $this->hubs->current();
        $intro = match ($status) {
            SocialMediaComplianceRequest::STATUS_APPROVED => 'Your submission has been reviewed and approved.',
            SocialMediaComplianceRequest::STATUS_REJECTED => 'Your submission was not approved. Please review the feedback and resubmit if needed.',
            SocialMediaComplianceRequest::STATUS_APPROVED_WITH_FEEDBACK => 'Your submission was approved subject to the feedback below. Please address the notes before publishing.',
            default => 'Your social media compliance request status has been updated.',
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
                subject: 'Update on Your Social Media Compliance Request',
                eyebrow: 'Social Media Compliance',
                heading: 'Social media compliance decision: '.$status,
                intro: $intro,
                fields: $fields,
                cta: $this->cta($hub, $request),
                hub: $hub
            );
        } catch (Throwable $e) {
            Log::warning('Social media compliance status email failed.', ['error' => $e->getMessage()]);
        }
    }

    public function notifyResubmitted(SocialMediaComplianceRequest $request, User $approver, User $subscriber): void
    {
        $hub = $this->hubs->current();
        $preview = $this->preview($request->currentVersionRow?->description);

        try {
            $this->mail->sendToUser(
                $approver,
                subject: 'Social Media Compliance Request Resubmitted for Review',
                eyebrow: 'Social Media Compliance',
                heading: 'A social media compliance request was resubmitted',
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
            Log::warning('Social media compliance resubmit email failed.', ['error' => $e->getMessage()]);
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
    private function cta(Hub $hub, SocialMediaComplianceRequest $request): array
    {
        $base = $hub->frontendBaseUrl();

        return [
            'label' => 'View request',
            'url' => $base.'/my-dashboard/social-media-compliance/'.$request->id,
        ];
    }
}
