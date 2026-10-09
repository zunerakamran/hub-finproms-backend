<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AdvisorImportBatch;
use App\Models\ComplianceAuditEvent;
use App\Models\Hub;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketComment;
use App\Models\User;
use App\Models\WebsiteCompliance\TemplateRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * UK GDPR right-to-erasure: anonymise the person, keep FCA-style audit rows.
 * Does not rewrite historical hub_backup SQL dumps (documented residual risk).
 */
class GdprErasureService
{
    public const ANONYMOUS_NAME_PREFIX = 'Deleted user #';

    /**
     * @return array{
     *   user_id: int,
     *   already_erased: bool,
     *   anonymised_name: string,
     *   anonymised_email: string,
     *   scrubbed: array<string, int>,
     *   notes: list<string>
     * }
     */
    public function erase(User $subject, Hub $hub, ?User $actor = null): array
    {
        if ($actor && (int) $actor->id === (int) $subject->id
            && ($actor->getConnectionName() ?: null) === ($subject->getConnectionName() ?: null)
        ) {
            throw new InvalidArgumentException('You cannot erase your own account.');
        }

        if ($subject->gdpr_erased_at) {
            return [
                'user_id' => (int) $subject->id,
                'already_erased' => true,
                'anonymised_name' => (string) $subject->name,
                'anonymised_email' => (string) $subject->email,
                'scrubbed' => [],
                'notes' => ['User was already erased.'],
            ];
        }

        $connection = $subject->getConnectionName() ?: $subject->getConnection()->getName();
        $originalEmail = (string) $subject->email;
        $originalName = (string) $subject->name;
        $userId = (int) $subject->id;

        if ($subject->role === User::ROLE_POWER_ADMIN) {
            $paCount = User::on($connection)
                ->where('role', User::ROLE_POWER_ADMIN)
                ->whereNull('gdpr_erased_at')
                ->count();
            if ($paCount <= 1) {
                throw new InvalidArgumentException('Cannot erase the last Power Admin account on this hub.');
            }
        }

        $anonymousName = self::ANONYMOUS_NAME_PREFIX.$userId;
        $anonymousEmail = sprintf('erased-%d-%s@erased.invalid', $userId, Str::lower(Str::random(8)));

        $scrubbed = [];
        $notes = [
            'Audit event rows are retained with anonymised actor/related identity for FCA integrity.',
            'Historical hub database backups may still contain personal data until retention prune.',
            'Stripe customer records (if any) are cleared locally; delete in Stripe Dashboard/API if needed.',
        ];

        DB::connection($connection)->transaction(function () use (
            $subject,
            $connection,
            $userId,
            $originalEmail,
            $anonymousName,
            $anonymousEmail,
            &$scrubbed
        ) {
            $scrubbed['activity_logs'] = $this->scrubActivityLogs($connection, $userId, $anonymousName, $anonymousEmail);
            $scrubbed['compliance_audit_events'] = $this->scrubComplianceAuditEvents($connection, $userId, $anonymousName, $anonymousEmail);
            $scrubbed['invoices'] = $this->scrubInvoices($connection, $userId, $anonymousName, $anonymousEmail);
            $scrubbed['support_tickets'] = $this->scrubSupportTickets($connection, $userId);
            $scrubbed['support_ticket_comments'] = $this->scrubSupportTicketComments($connection, $userId, $anonymousName);
            $scrubbed['support_ticket_attachments'] = $this->scrubUploaderNames($connection, 'support_ticket_attachments', $userId, $anonymousName);
            $scrubbed['smc_attachments'] = $this->scrubUploaderNames($connection, 'social_media_compliance_request_attachments', $userId, $anonymousName);
            $scrubbed['gc_attachments'] = $this->scrubUploaderNames($connection, 'general_compliance_request_attachments', $userId, $anonymousName);
            $scrubbed['wc_attachments'] = $this->scrubUploaderNames($connection, 'wc_change_request_attachments', $userId, $anonymousName);
            $scrubbed['wc_template_requests'] = $this->scrubWebsiteTemplateRequests($connection, $userId);
            $scrubbed['advisor_import_batches'] = $this->scrubAdvisorImportBatches($connection, $userId, $originalEmail, $anonymousName, $anonymousEmail);
            $scrubbed['tokens'] = $this->deleteTokens($connection, $userId);
            $scrubbed['sessions'] = $this->deleteSessions($connection, $userId);
            $scrubbed['password_reset_tokens'] = $this->deleteEmailKeyedTokens($connection, 'password_reset_tokens', $originalEmail);
            $scrubbed['email_verification_tokens'] = $this->deleteEmailKeyedTokens($connection, 'email_verification_tokens', $originalEmail);
            $scrubbed['login_otp_tokens'] = $this->deleteEmailKeyedTokens($connection, 'login_otp_tokens', $originalEmail);

            $this->deleteAvatar($subject);

            $subject->forceFill([
                'name' => $anonymousName,
                'email' => $anonymousEmail,
                // Plain random value — User model casts password as hashed.
                'password' => Str::random(64),
                'remember_token' => null,
                'email_verified_at' => null,
                'avatar_path' => null,
                'firm_id' => null,
                'modules' => null,
                'stripe_customer_id' => null,
                'stripe_payment_method_id' => null,
                'acting_hub_id' => null,
                'acting_advisor_id' => null,
                'is_advisor' => false,
                'allows_admin_staff_acting' => false,
                'has_unlimited_credits' => false,
                'credits' => 0,
                'is_suspended' => true,
                'is_discontinued' => true,
                'discontinued_at' => $subject->discontinued_at ?? now(),
                'two_factor_enabled' => false,
                'terms_accepted_at' => null,
                'terms_accepted_version' => null,
                'privacy_accepted_at' => null,
                'privacy_accepted_version' => null,
                'gdpr_erased_at' => now(),
            ])->save();
        });

        return [
            'user_id' => $userId,
            'already_erased' => false,
            'anonymised_name' => $anonymousName,
            'anonymised_email' => $anonymousEmail,
            'previous_email' => $originalEmail,
            'previous_name' => $originalName,
            'hub' => [
                'id' => $hub->id,
                'slug' => $hub->slug,
                'name' => $hub->name,
            ],
            'scrubbed' => $scrubbed,
            'notes' => $notes,
        ];
    }

    private function scrubActivityLogs(string $connection, int $userId, string $name, string $email): int
    {
        if (! $this->tableExists($connection, 'activity_logs')) {
            return 0;
        }

        return ActivityLog::on($connection)
            ->where('user_id', $userId)
            ->update([
                'user_name' => $name,
                'user_email' => $email,
                'ip_address' => null,
                'user_agent' => null,
            ]);
    }

    private function scrubComplianceAuditEvents(string $connection, int $userId, string $name, string $email): int
    {
        if (! $this->tableExists($connection, 'compliance_audit_events')) {
            return 0;
        }

        $actor = ComplianceAuditEvent::on($connection)
            ->where('actor_user_id', $userId)
            ->update([
                'actor_name' => $name,
                'actor_email' => $email,
            ]);

        $related = ComplianceAuditEvent::on($connection)
            ->where('related_user_id', $userId)
            ->update([
                'related_user_name' => $name,
                'related_user_email' => $email,
            ]);

        return $actor + $related;
    }

    private function scrubInvoices(string $connection, int $userId, string $name, string $email): int
    {
        if (! $this->tableExists($connection, 'invoices')) {
            return 0;
        }

        return Invoice::on($connection)
            ->where('user_id', $userId)
            ->update([
                'billing_name' => $name,
                'billing_email' => $email,
            ]);
    }

    private function scrubSupportTickets(string $connection, int $userId): int
    {
        if (! $this->tableExists($connection, 'support_tickets')) {
            return 0;
        }

        return SupportTicket::on($connection)
            ->where('user_id', $userId)
            ->update([
                'subject' => '[Erased]',
                'description' => '[Personal data erased under UK GDPR request.]',
                'page_url' => null,
                'browser_info' => null,
                'status_note' => null,
            ]);
    }

    private function scrubSupportTicketComments(string $connection, int $userId, string $name): int
    {
        if (! $this->tableExists($connection, 'support_ticket_comments')) {
            return 0;
        }

        return SupportTicketComment::on($connection)
            ->where('user_id', $userId)
            ->update([
                'author_name' => $name,
                'body' => '[Erased]',
            ]);
    }

    private function scrubUploaderNames(string $connection, string $table, int $userId, string $name): int
    {
        if (! $this->tableExists($connection, $table)) {
            return 0;
        }

        $schema = Schema::connection($connection);
        if (! $schema->hasColumn($table, 'uploaded_by_user_id') || ! $schema->hasColumn($table, 'uploaded_by_name')) {
            return 0;
        }

        return DB::connection($connection)
            ->table($table)
            ->where('uploaded_by_user_id', $userId)
            ->update(['uploaded_by_name' => $name]);
    }

    private function scrubWebsiteTemplateRequests(string $connection, int $userId): int
    {
        if (! $this->tableExists($connection, 'wc_template_requests')) {
            return 0;
        }

        $count = 0;
        TemplateRequest::on($connection)
            ->where(function ($q) use ($userId) {
                $q->where('advisor_id', $userId)
                    ->orWhere('assigned_advisor_id', $userId);
            })
            ->orderBy('id')
            ->each(function (TemplateRequest $row) use (&$count) {
                $details = is_array($row->contact_details) ? $row->contact_details : [];
                $details['phone'] = null;
                $details['email'] = null;
                $details['address'] = null;
                // Keep website if it is not personal; still clear for safety on advisor sites.
                $details['website'] = null;
                $row->contact_details = $details;
                $row->save();
                $count++;
            });

        return $count;
    }

    private function scrubAdvisorImportBatches(
        string $connection,
        int $userId,
        string $originalEmail,
        string $name,
        string $email
    ): int {
        if (! $this->tableExists($connection, 'advisor_import_batches')) {
            return 0;
        }

        $emailLower = strtolower(trim($originalEmail));
        $count = 0;

        AdvisorImportBatch::on($connection)
            ->orderBy('id')
            ->each(function (AdvisorImportBatch $batch) use ($userId, $emailLower, $name, $email, &$count) {
                $changed = false;

                if ((int) $batch->imported_by_user_id === $userId
                    || strtolower(trim((string) $batch->imported_by_email)) === $emailLower
                ) {
                    $batch->imported_by_name = $name;
                    $batch->imported_by_email = $email;
                    $changed = true;
                }

                foreach (['created_users', 'updated_users', 'reactivated_users'] as $key) {
                    $rows = is_array($batch->{$key}) ? $batch->{$key} : [];
                    $newRows = [];
                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            continue;
                        }
                        $rowEmail = strtolower(trim((string) ($row['email'] ?? '')));
                        $rowId = (int) ($row['id'] ?? 0);
                        if ($rowEmail === $emailLower || $rowId === $userId) {
                            $row['name'] = $name;
                            $row['email'] = $email;
                            unset($row['temporary_password'], $row['password'], $row['user']);
                            $changed = true;
                        }
                        $newRows[] = $row;
                    }
                    $batch->{$key} = $newRows;
                }

                if ($changed) {
                    $batch->save();
                    $count++;
                }
            });

        return $count;
    }

    private function deleteTokens(string $connection, int $userId): int
    {
        if (! $this->tableExists($connection, 'personal_access_tokens')) {
            return 0;
        }

        return DB::connection($connection)
            ->table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->delete();
    }

    private function deleteSessions(string $connection, int $userId): int
    {
        if (! $this->tableExists($connection, 'sessions')) {
            return 0;
        }

        return DB::connection($connection)
            ->table('sessions')
            ->where('user_id', $userId)
            ->delete();
    }

    private function deleteEmailKeyedTokens(string $connection, string $table, string $email): int
    {
        if ($email === '' || ! $this->tableExists($connection, $table)) {
            return 0;
        }

        $schema = Schema::connection($connection);
        if (! $schema->hasColumn($table, 'email')) {
            return 0;
        }

        return DB::connection($connection)
            ->table($table)
            ->where('email', $email)
            ->delete();
    }

    private function deleteAvatar(User $user): void
    {
        $path = $user->avatar_path;
        if (! is_string($path) || $path === '') {
            return;
        }

        try {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
            // Remove empty user avatar directory when possible.
            $dir = dirname($path);
            if ($dir && $dir !== '.' && Storage::disk('public')->exists($dir)) {
                $files = Storage::disk('public')->files($dir);
                if ($files === []) {
                    Storage::disk('public')->deleteDirectory($dir);
                }
            }
        } catch (\Throwable) {
            //
        }
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
