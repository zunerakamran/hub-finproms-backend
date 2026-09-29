<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubModuleBilling;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class ModuleBillingService
{
    public function __construct(
        private readonly ModulePricingService $pricing,
        private readonly InvoiceService $invoices
    ) {}

    public function billingEnabled(Hub $hub): bool
    {
        return $hub->can('charge_amount_per_module');
    }

    /**
     * Hub packaging module (Shared / White Label) — invoice is created as paid.
     * All other product modules start unpaid until payment is recorded later.
     */
    public static function isHubEnableModule(string $moduleKey): bool
    {
        return in_array($moduleKey, Hub::LOCKED_MODULE_KEYS, true);
    }

    /**
     * Prefer the actor when they are client admin; otherwise first client admin, else actor.
     */
    public function resolvePayer(User $actor): User
    {
        if (in_array($actor->role, [User::ROLE_CLIENT_ADMIN, 'admin'], true)) {
            return $actor;
        }

        $clientAdmin = User::query()
            ->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin'])
            ->orderBy('id')
            ->first();

        return $clientAdmin ?: $actor;
    }

    /**
     * Create one-time invoices for modules that newly became enabled.
     *
     * @param  list<string>  $newlyEnabledKeys
     * @return list<Invoice>
     */
    public function invoiceNewlyEnabledModules(Hub $hub, array $newlyEnabledKeys, User $actor): array
    {
        if (! $this->billingEnabled($hub) || $newlyEnabledKeys === []) {
            return [];
        }

        $created = [];
        foreach ($newlyEnabledKeys as $moduleKey) {
            $invoice = $this->invoiceModuleIfNeeded($hub, $moduleKey, $actor);
            if ($invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * Invoice every currently enabled module that has not been billed yet
     * (e.g. when charge_amount_per_module is turned on, or a new hub is created).
     *
     * @return list<Invoice>
     */
    public function invoiceEnabledModules(Hub $hub, User $actor): array
    {
        if (! $this->billingEnabled($hub)) {
            return [];
        }

        $created = [];
        foreach ($hub->enabledModuleKeys() as $moduleKey) {
            $invoice = $this->invoiceModuleIfNeeded($hub, $moduleKey, $actor);
            if ($invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    public function invoiceModuleIfNeeded(Hub $hub, string $moduleKey, User $actor): ?Invoice
    {
        if (! $this->billingEnabled($hub)) {
            return null;
        }

        if (! in_array($moduleKey, Hub::MODULE_KEYS, true)) {
            return null;
        }

        if (! $hub->moduleEffectivelyEnabled($moduleKey)) {
            return null;
        }

        $existing = HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', $moduleKey)
            ->where('status', '!=', HubModuleBilling::STATUS_CANCELED)
            ->first();

        if ($existing) {
            // Already billed — only create a missing invoice row, never re-report as new.
            if ($existing->invoice) {
                return null;
            }

            return $this->invoices->createForModuleBilling($existing);
        }

        $quote = $this->pricing->quote($moduleKey);
        if ($quote['amount'] <= 0) {
            // Zero / inactive pricing — skip invoice generation.
            return null;
        }

        // Per-website modules are not charged as a flat one-time on enable —
        // amount is a unit rate (£/website) applied when websites are provisioned.
        if (($quote['billing_unit'] ?? ModulePricingService::BILLING_UNIT_ONE_TIME)
            === ModulePricingService::BILLING_UNIT_PER_WEBSITE
        ) {
            return null;
        }

        $payer = $this->resolvePayer($actor);
        $paid = self::isHubEnableModule($moduleKey);
        $status = $paid ? HubModuleBilling::STATUS_PAID : HubModuleBilling::STATUS_UNPAID;

        return DB::transaction(function () use ($hub, $moduleKey, $quote, $payer, $status, $paid) {
            $billing = HubModuleBilling::query()->create([
                'hub_id' => $hub->id,
                'module_key' => $moduleKey,
                'billed_user_id' => $payer->id,
                'amount' => $quote['amount'],
                'currency' => $quote['currency'],
                'status' => $status,
                'payment_status' => $status,
                'meta' => [
                    'module_label' => Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey,
                    'billing_cadence' => Invoice::TYPES_ONE_TIME,
                    'billing_unit' => $quote['billing_unit'] ?? ModulePricingService::BILLING_UNIT_ONE_TIME,
                    'auto_paid_on_hub_enable' => $paid,
                ],
            ]);

            return $this->invoices->createForModuleBilling($billing);
        });
    }

    /**
     * Manually settle an unpaid module invoice (notes + optional attachment).
     *
     * @param  array{
     *   payment_method?: string|null,
     *   payment_reference?: string|null,
     *   payment_notes?: string|null
     * }  $data
     */
    public function markInvoicePaid(
        Invoice $invoice,
        User $actor,
        array $data,
        ?UploadedFile $attachment = null
    ): Invoice {
        if ($invoice->type !== Invoice::TYPE_MODULE_BILLING) {
            throw new InvalidArgumentException('Only module invoices can be marked paid with this action.');
        }

        $invoice->loadMissing('moduleBilling');
        $billing = $invoice->moduleBilling;
        if (! $billing) {
            throw new RuntimeException('Module billing record is missing for this invoice.');
        }

        if ($billing->status === HubModuleBilling::STATUS_CANCELED) {
            throw new InvalidArgumentException('Canceled module billings cannot be marked paid.');
        }

        if ($invoice->status === 'paid' && $billing->status === HubModuleBilling::STATUS_PAID) {
            return $invoice->fresh()->load([
                'moduleBilling.hub',
                'moduleBilling.paidBy',
                'user',
            ]);
        }

        return DB::transaction(function () use ($invoice, $billing, $actor, $data, $attachment) {
            $attachmentPath = $billing->payment_attachment_path;
            $attachmentName = $billing->payment_attachment_name;
            $attachmentMime = $billing->payment_attachment_mime;

            if ($attachment) {
                if ($attachmentPath) {
                    Storage::disk('public')->delete($attachmentPath);
                }
                $attachmentPath = $attachment->store('module-invoice-payments', 'public');
                $attachmentName = $attachment->getClientOriginalName();
                $attachmentMime = $attachment->getClientMimeType();
            }

            $billing->forceFill([
                'status' => HubModuleBilling::STATUS_PAID,
                'payment_status' => 'paid',
                'paid_at' => now(),
                'paid_by_user_id' => $actor->id,
                'payment_method' => $data['payment_method'] ?? 'manual',
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_notes' => $data['payment_notes'] ?? null,
                'payment_attachment_path' => $attachmentPath,
                'payment_attachment_name' => $attachmentName,
                'payment_attachment_mime' => $attachmentMime,
            ])->save();

            $invoice->forceFill([
                'status' => 'paid',
            ])->save();

            return $invoice->fresh()->load([
                'moduleBilling.hub',
                'moduleBilling.paidBy',
                'user',
            ]);
        });
    }

    /**
     * Diff before/after checklist maps for modules that newly became effectively enabled.
     *
     * @param  array<string, bool>  $beforeChecklist
     * @param  array<string, bool>  $afterChecklist
     * @return list<string>
     */
    public function newlyEnabledModuleKeys(Hub $hub, array $beforeChecklist, array $afterChecklist): array
    {
        $newly = [];
        foreach ($hub->moduleKeysForPage() as $key) {
            $wasOn = $this->effectivelyEnabledIn($hub, $beforeChecklist, $key);
            $isOn = $this->effectivelyEnabledIn($hub, $afterChecklist, $key);
            if (! $wasOn && $isOn) {
                $newly[] = $key;
            }
        }

        return $newly;
    }

    /**
     * @param  array<string, bool>  $checklist
     */
    private function effectivelyEnabledIn(Hub $hub, array $checklist, string $key): bool
    {
        if (! ($checklist[$key] ?? false)) {
            return false;
        }

        foreach ($hub->moduleDependenciesFor($key) as $dep) {
            if (! ($checklist[$dep] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
