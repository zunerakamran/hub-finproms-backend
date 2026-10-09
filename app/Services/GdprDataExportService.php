<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AdvisorImportBatch;
use App\Models\ComplianceAuditEvent;
use App\Models\FirmDocument;
use App\Models\Hub;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WebsiteCompliance\TemplateRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Build a subject-access (DSAR) JSON package for one user on a hub database.
 * Excludes secrets (password hashes, payment method ids, cPanel secrets).
 */
class GdprDataExportService
{
    private const ACTIVITY_LIMIT = 2000;

    private const COMPLIANCE_AUDIT_LIMIT = 2000;

    /**
     * @return array<string, mixed>
     */
    public function build(User $subject, Hub $hub, ?User $exportedBy = null): array
    {
        $connection = $subject->getConnectionName() ?: $subject->getConnection()->getName();

        return [
            'export_meta' => [
                'type' => 'uk_gdpr_subject_access',
                'exported_at' => now()->toIso8601String(),
                'hub' => [
                    'id' => $hub->id,
                    'name' => $hub->name,
                    'slug' => $hub->slug,
                    'type' => $hub->type,
                ],
                'exported_by' => $exportedBy ? [
                    'id' => $exportedBy->id,
                    'name' => $exportedBy->name,
                    'email' => $exportedBy->email,
                    'role' => $exportedBy->role,
                ] : null,
                'subject_user_id' => $subject->id,
                'notes' => [
                    'Passwords and payment-method secrets are never included.',
                    'File contents are not embedded — only metadata and identifiers.',
                    'Activity and compliance audit rows may be capped for size.',
                ],
            ],
            'profile' => $this->profile($subject),
            'firm' => $this->firm($subject),
            'invoices' => $this->invoices($subject, $connection),
            'support_tickets' => $this->supportTickets($subject, $connection),
            'website_template_requests' => $this->websiteTemplateRequests($subject, $connection),
            'firm_documents_uploaded' => $this->firmDocumentsUploaded($subject, $connection),
            'activity_logs' => $this->activityLogs($subject, $connection),
            'compliance_audit_events' => $this->complianceAuditEvents($subject, $connection),
            'advisor_import_batches' => $this->advisorImportBatches($subject, $connection),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'firm_id' => $user->firm_id ? (int) $user->firm_id : null,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
            'terms_accepted_version' => $user->terms_accepted_version,
            'privacy_accepted_at' => $user->privacy_accepted_at?->toIso8601String(),
            'privacy_accepted_version' => $user->privacy_accepted_version,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'avatar_url' => $user->avatar_url,
            'is_advisor' => (bool) $user->is_advisor,
            'is_suspended' => (bool) $user->is_suspended,
            'is_discontinued' => (bool) $user->is_discontinued,
            'discontinued_at' => $user->discontinued_at?->toIso8601String(),
            'gdpr_erased_at' => $user->gdpr_erased_at?->toIso8601String(),
            'credits' => $user->credits,
            'has_unlimited_credits' => (bool) $user->has_unlimited_credits,
            'modules' => $user->modules,
            'stripe_customer_id' => $user->stripe_customer_id,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firm(User $user): ?array
    {
        $user->loadMissing('firm:id,name,is_central');
        if (! $user->firm) {
            return null;
        }

        return [
            'id' => $user->firm->id,
            'name' => $user->firm->name,
            'is_central' => (bool) ($user->firm->is_central ?? false),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invoices(User $user, string $connection): array
    {
        if (! $this->tableExists($connection, 'invoices')) {
            return [];
        }

        return Invoice::on($connection)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'type' => $invoice->type,
                'types' => $invoice->types,
                'description' => $invoice->description,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'credits' => $invoice->credits,
                'status' => $invoice->status,
                'billing_name' => $invoice->billing_name,
                'billing_email' => $invoice->billing_email,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'due_on' => $invoice->due_on,
                'created_at' => $invoice->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function supportTickets(User $user, string $connection): array
    {
        if (! $this->tableExists($connection, 'support_tickets')) {
            return [];
        }

        return SupportTicket::on($connection)
            ->with(['comments:id,ticket_id,user_id,author_name,body,created_at', 'attachments'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (SupportTicket $ticket) {
                return [
                    'id' => $ticket->id,
                    'subject' => $ticket->subject,
                    'module_area' => $ticket->module_area,
                    'category' => $ticket->category,
                    'priority' => $ticket->priority,
                    'description' => $ticket->description,
                    'status' => $ticket->status,
                    'page_url' => $ticket->page_url,
                    'browser_info' => $ticket->browser_info,
                    'created_at' => $ticket->created_at?->toIso8601String(),
                    'comments' => $ticket->comments->map(fn ($c) => [
                        'id' => $c->id,
                        'user_id' => $c->user_id,
                        'author_name' => $c->author_name,
                        'body' => $c->body,
                        'created_at' => $c->created_at?->toIso8601String(),
                    ])->all(),
                    'attachments' => $ticket->attachments->map(fn ($a) => [
                        'id' => $a->id,
                        'original_name' => $a->original_name ?? null,
                        'mime_type' => $a->mime_type ?? null,
                        'uploaded_by_user_id' => $a->uploaded_by_user_id ?? null,
                        'uploaded_by_name' => $a->uploaded_by_name ?? null,
                        'created_at' => $a->created_at?->toIso8601String(),
                    ])->all(),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function websiteTemplateRequests(User $user, string $connection): array
    {
        if (! $this->tableExists($connection, 'wc_template_requests')) {
            return [];
        }

        return TemplateRequest::on($connection)
            ->where(function (Builder $q) use ($user) {
                $q->where('advisor_id', $user->id)
                    ->orWhere('assigned_advisor_id', $user->id);
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (TemplateRequest $row) => [
                'id' => $row->id,
                'advisor_id' => $row->advisor_id,
                'assigned_advisor_id' => $row->assigned_advisor_id ?? null,
                'status' => $row->status ?? null,
                'domain' => $row->domain ?? null,
                'contact_details' => is_array($row->contact_details) ? $row->contact_details : null,
                'created_at' => $row->created_at?->toIso8601String(),
                'updated_at' => $row->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function firmDocumentsUploaded(User $user, string $connection): array
    {
        if (! $this->tableExists($connection, 'firm_documents')) {
            return [];
        }

        return FirmDocument::on($connection)
            ->where('uploaded_by', $user->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (FirmDocument $doc) => [
                'id' => $doc->id,
                'firm_id' => $doc->firm_id,
                'title' => $doc->title ?? $doc->name ?? null,
                'uploaded_by' => $doc->uploaded_by,
                'archived_at' => $doc->archived_at?->toIso8601String(),
                'created_at' => $doc->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityLogs(User $user, string $connection): array
    {
        if (! $this->tableExists($connection, 'activity_logs')) {
            return [];
        }

        return ActivityLog::on($connection)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'path' => $log->path,
                'method' => $log->method,
                'status_code' => $log->status_code,
                'properties' => $log->properties,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function complianceAuditEvents(User $user, string $connection): array
    {
        if (! $this->tableExists($connection, 'compliance_audit_events')) {
            return [];
        }

        return ComplianceAuditEvent::on($connection)
            ->where(function (Builder $q) use ($user) {
                $q->where('actor_user_id', $user->id)
                    ->orWhere('related_user_id', $user->id);
            })
            ->orderByDesc('id')
            ->limit(self::COMPLIANCE_AUDIT_LIMIT)
            ->get()
            ->map(fn (ComplianceAuditEvent $event) => [
                'id' => $event->id,
                'module' => $event->module,
                'event_type' => $event->event_type,
                'description' => $event->description,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'actor_user_id' => $event->actor_user_id,
                'related_user_id' => $event->related_user_id,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function advisorImportBatches(User $user, string $connection): array
    {
        if (! $this->tableExists($connection, 'advisor_import_batches')) {
            return [];
        }

        $email = strtolower(trim((string) $user->email));
        $batches = AdvisorImportBatch::on($connection)
            ->where(function (Builder $q) use ($user, $email) {
                $q->where('imported_by_user_id', $user->id);
                if ($email !== '') {
                    $q->orWhere('imported_by_email', $user->email);
                }
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // Also include batches that list this email in created/updated/reactivated JSON.
        if ($email !== '') {
            $mentioned = AdvisorImportBatch::on($connection)
                ->where(function (Builder $q) use ($email) {
                    $like = '%'.$email.'%';
                    $q->where('created_users', 'like', $like)
                        ->orWhere('updated_users', 'like', $like)
                        ->orWhere('reactivated_users', 'like', $like);
                })
                ->orderByDesc('id')
                ->limit(50)
                ->get();
            $batches = $batches->concat($mentioned)->unique('id')->values();
        }

        return $batches->map(function (AdvisorImportBatch $batch) use ($user, $email) {
            $mentions = [];
            foreach (['created_users', 'updated_users', 'reactivated_users'] as $key) {
                $rows = is_array($batch->{$key}) ? $batch->{$key} : [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $rowEmail = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($rowEmail === $email || (int) ($row['id'] ?? 0) === (int) $user->id) {
                        $mentions[] = [
                            'list' => $key,
                            'name' => $row['name'] ?? null,
                            'email' => $row['email'] ?? null,
                            'role' => $row['role'] ?? null,
                            'firm' => $row['firm'] ?? null,
                        ];
                    }
                }
            }

            return [
                'id' => $batch->id,
                'status' => $batch->status,
                'kind' => $batch->kind,
                'original_filename' => $batch->original_filename,
                'imported_by_user_id' => $batch->imported_by_user_id,
                'imported_by_name' => $batch->imported_by_name,
                'imported_by_email' => $batch->imported_by_email,
                'subject_mentions' => $mentions,
                'created_at' => $batch->created_at?->toIso8601String(),
            ];
        })->all();
    }

    private function tableExists(string $connection, string $table): bool
    {
        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
