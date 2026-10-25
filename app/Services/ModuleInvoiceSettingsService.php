<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\HubModuleInvoiceSettings;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ModuleInvoiceSettingsService
{
    public const PAYER_NETWORK = 'network';

    public const PAYER_FIRM = 'firm';

    public const PAYER_USER = 'user';

    public function forHub(Hub $hub): HubModuleInvoiceSettings
    {
        return HubModuleInvoiceSettings::query()->firstOrCreate(
            ['hub_id' => $hub->id],
            [
                'network_pays_all' => true,
                'firms_pay_own' => false,
                'users_pay_own' => false,
                'network_resells' => false,
                'reseller_invoice_format' => HubModuleInvoiceSettings::FORMAT_STANDARD,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(HubModuleInvoiceSettings $settings): array
    {
        return [
            'hub_id' => (int) $settings->hub_id,
            'network_pays_all' => (bool) $settings->network_pays_all,
            'firms_pay_own' => (bool) $settings->firms_pay_own,
            'users_pay_own' => (bool) $settings->users_pay_own,
            'network_resells' => (bool) $settings->network_resells,
            'reseller_logo_url' => $settings->resellerLogoUrl(),
            'reseller_logo_name' => $settings->reseller_logo_name,
            'reseller_invoice_text' => $settings->reseller_invoice_text,
            'reseller_invoice_format' => (string) ($settings->reseller_invoice_format ?: HubModuleInvoiceSettings::FORMAT_STANDARD),
            'reseller_invoice_formats' => HubModuleInvoiceSettings::FORMATS,
            'rules' => [
                'network_pays_all_clears_firm_and_user' => true,
                'network_resells_clears_users_pay_own' => true,
                'firms_only_non_firm_units_paid_by_network' => true,
                'users_only_non_user_units_paid_by_network' => true,
            ],
            'explanations' => [
                'network_pays_all' => 'Network pays all module invoices (every pricing unit, one-time and ongoing). Network admin adds the card; network Accounts Admin can see the invoices.',
                'firms_pay_own' => 'Each firm pays its own invoice when the unit is per firm (one invoice per firm). Other units are still paid by the network.',
                'users_pay_own' => 'Each user pays their own invoice when the unit is per user / per user (slots). Other units are still paid by the network.',
                'network_resells' => 'Network pays the platform for all invoices, then may re-charge users with its own pricing. Disables “users pay own”. Requires logo + invoice text for branded invoices.',
            ],
        ];
    }

    /**
     * Apply checkbox mutual-exclusion rules and optional reseller branding.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Hub $hub, array $data, ?UploadedFile $logo = null): HubModuleInvoiceSettings
    {
        $settings = $this->forHub($hub);

        $networkPaysAll = array_key_exists('network_pays_all', $data)
            ? (bool) $data['network_pays_all']
            : (bool) $settings->network_pays_all;
        $firmsPayOwn = array_key_exists('firms_pay_own', $data)
            ? (bool) $data['firms_pay_own']
            : (bool) $settings->firms_pay_own;
        $usersPayOwn = array_key_exists('users_pay_own', $data)
            ? (bool) $data['users_pay_own']
            : (bool) $settings->users_pay_own;
        $networkResells = array_key_exists('network_resells', $data)
            ? (bool) $data['network_resells']
            : (bool) $settings->network_resells;

        // 1 checked → clear 2 and 3.
        if ($networkPaysAll) {
            $firmsPayOwn = false;
            $usersPayOwn = false;
        }

        // Reseller clears “users pay own”.
        if ($networkResells) {
            $usersPayOwn = false;
        }

        // If nothing selected, default to network pays all.
        if (! $networkPaysAll && ! $firmsPayOwn && ! $usersPayOwn && ! $networkResells) {
            $networkPaysAll = true;
        }

        // When firm/user flags are on without network_pays_all, keep network_pays_all false.
        if ($firmsPayOwn || $usersPayOwn) {
            $networkPaysAll = false;
        }

        $settings->network_pays_all = $networkPaysAll;
        $settings->firms_pay_own = $firmsPayOwn;
        $settings->users_pay_own = $usersPayOwn;
        $settings->network_resells = $networkResells;

        if (array_key_exists('reseller_invoice_text', $data)) {
            $text = $data['reseller_invoice_text'];
            $settings->reseller_invoice_text = $text !== null && trim((string) $text) !== ''
                ? trim((string) $text)
                : null;
        }

        if (array_key_exists('reseller_invoice_format', $data)) {
            $format = (string) ($data['reseller_invoice_format'] ?: HubModuleInvoiceSettings::FORMAT_STANDARD);
            if (! in_array($format, HubModuleInvoiceSettings::FORMATS, true)) {
                throw new InvalidArgumentException('Invalid reseller_invoice_format.');
            }
            $settings->reseller_invoice_format = $format;
        }

        if (! empty($data['clear_reseller_logo'])) {
            $this->deleteLogo($settings);
            $settings->reseller_logo_path = null;
            $settings->reseller_logo_name = null;
        }

        if ($logo) {
            $this->deleteLogo($settings);
            $path = $logo->store('module-invoice-reseller-logos', 'public');
            $settings->reseller_logo_path = $path;
            $settings->reseller_logo_name = $logo->getClientOriginalName();
        }

        if ($settings->network_resells) {
            if (! filled($settings->reseller_invoice_text)) {
                throw new InvalidArgumentException(
                    'When the network resells / re-charges users, invoice text is required.'
                );
            }
            if (! filled($settings->reseller_logo_path)) {
                throw new InvalidArgumentException(
                    'When the network resells / re-charges users, a logo is required for branded invoices.'
                );
            }
        }

        $settings->save();

        return $settings->fresh();
    }

    private function deleteLogo(HubModuleInvoiceSettings $settings): void
    {
        if ($settings->reseller_logo_path) {
            Storage::disk('public')->delete($settings->reseller_logo_path);
        }
    }

    /**
     * Who should be charged for a given catalogue billing unit under hub settings.
     */
    public function payerScopeForUnit(HubModuleInvoiceSettings $settings, string $billingUnit): string
    {
        $unit = ModulePricingService::normalizeUnit($billingUnit);

        if ($settings->network_pays_all || $settings->network_resells) {
            return self::PAYER_NETWORK;
        }

        if ($settings->firms_pay_own && $unit === ModulePricingService::UNIT_PER_FIRM) {
            return self::PAYER_FIRM;
        }

        if (
            $settings->users_pay_own
            && in_array($unit, [
                ModulePricingService::UNIT_PER_USER,
                ModulePricingService::UNIT_PER_USER_SLOTS,
            ], true)
        ) {
            return self::PAYER_USER;
        }

        // Only firm or only user checked → everything else paid by network.
        return self::PAYER_NETWORK;
    }

    /**
     * Network payer of record: network admin with card preferred, else client admin, else actor.
     */
    public function resolveNetworkPayer(Hub $hub, ?User $actor = null): User
    {
        $query = User::query()
            ->where('is_suspended', false)
            ->where('is_discontinued', false)
            ->orderBy('id');

        $networkAdmin = (clone $query)
            ->where('role', User::ROLE_NETWORK_ADMIN)
            ->whereNotNull('stripe_payment_method_id')
            ->first()
            ?: (clone $query)->where('role', User::ROLE_NETWORK_ADMIN)->first();

        if ($networkAdmin) {
            return $networkAdmin;
        }

        if ($actor && in_array($actor->role, [User::ROLE_CLIENT_ADMIN, 'admin', User::ROLE_NETWORK_ADMIN], true)) {
            return $actor;
        }

        $clientAdmin = (clone $query)
            ->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin'])
            ->whereNotNull('stripe_payment_method_id')
            ->first()
            ?: (clone $query)->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin'])->first();

        return $clientAdmin ?: ($actor ?: User::query()->orderBy('id')->firstOrFail());
    }

    /**
     * Firm payer: firm admin with card, else firm accounts admin, else firm head, else network.
     */
    public function resolveFirmPayer(Hub $hub, int $firmId, ?User $actor = null): User
    {
        $users = User::query()
            ->where('firm_id', $firmId)
            ->where('is_suspended', false)
            ->where('is_discontinued', false)
            ->orderBy('id')
            ->get();

        $firmAdminWithCard = $users->first(
            fn (User $u) => $u->hasRole(User::ROLE_FIRM_ADMIN) && filled($u->stripe_payment_method_id)
        );
        if ($firmAdminWithCard) {
            return $firmAdminWithCard;
        }

        $firmAdmin = $users->first(fn (User $u) => $u->hasRole(User::ROLE_FIRM_ADMIN));
        if ($firmAdmin) {
            return $firmAdmin;
        }

        $accounts = $users->first(fn (User $u) => $u->hasRole(User::ROLE_ACCOUNTS_ADMIN));
        if ($accounts) {
            return $accounts;
        }

        $firm = Firm::query()->find($firmId);
        if ($firm?->head_user_id) {
            $head = User::query()->find($firm->head_user_id);
            if ($head) {
                return $head;
            }
        }

        return $this->resolveNetworkPayer($hub, $actor);
    }

    /**
     * Branding block stored on invoice meta when network_resells is on.
     *
     * @return array<string, mixed>
     */
    public function resellerBrandingMeta(HubModuleInvoiceSettings $settings): array
    {
        if (! $settings->network_resells) {
            return [];
        }

        return [
            'network_resells' => true,
            'reseller_invoice_format' => $settings->reseller_invoice_format,
            'reseller_invoice_text' => $settings->reseller_invoice_text,
            'reseller_logo_url' => $settings->resellerLogoUrl(),
            'reseller_logo_name' => $settings->reseller_logo_name,
        ];
    }

    /**
     * Whether an accounts / firm / network viewer may see this billing row.
     */
    public function viewerCanSeeBilling(
        User $viewer,
        Hub $hub,
        string $payerScope,
        ?int $firmId,
        ?int $billedUserId
    ): bool {
        // Hub-wide managers see everything.
        if (
            $viewer->hasRole(User::ROLE_POWER_ADMIN)
            || $viewer->hasRole(User::ROLE_FINPROMS_ADMIN)
            || $viewer->hasRole(User::ROLE_CLIENT_ADMIN)
            || $viewer->hasRole(User::ROLE_NETWORK_ADMIN)
            || $viewer->hasRole('admin')
        ) {
            return true;
        }

        if ($viewer->hasRole(User::ROLE_ACCOUNTS_ADMIN)) {
            // Network accounts admin (no firm / central firm) → network-scoped invoices.
            $viewerFirmId = $viewer->firm_id ? (int) $viewer->firm_id : null;
            $viewerFirm = $viewerFirmId ? Firm::query()->find($viewerFirmId) : null;
            $isNetworkAccounts = ! $viewerFirmId || ($viewerFirm && $viewerFirm->isCentral());

            if ($isNetworkAccounts) {
                return $payerScope === self::PAYER_NETWORK
                    || $payerScope === self::PAYER_USER; // network may still oversee user invoices
            }

            // Firm accounts admin → that firm’s invoices only.
            return $payerScope === self::PAYER_FIRM && $firmId && (int) $firmId === $viewerFirmId;
        }

        if ($viewer->hasRole(User::ROLE_FIRM_ADMIN)) {
            $viewerFirmId = $viewer->firm_id ? (int) $viewer->firm_id : null;

            return $payerScope === self::PAYER_FIRM && $firmId && $viewerFirmId === (int) $firmId;
        }

        // End users see their own user-scoped invoices.
        return $payerScope === self::PAYER_USER
            && $billedUserId
            && (int) $billedUserId === (int) $viewer->id;
    }
}
