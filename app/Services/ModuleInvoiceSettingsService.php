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

    public const VIEW_NETWORK_CAPABILITY = 'dashboard_view_network_module_invoices';

    public const VIEW_FIRM_CAPABILITY = 'dashboard_view_firm_module_invoices';

    public const VIEW_RESELLER_CAPABILITY = 'dashboard_view_reseller_invoices';

    public const NETWORK_CARD_CAPABILITY = 'dashboard_manage_network_payment_card';

    public const FIRM_CARD_CAPABILITY = 'dashboard_manage_firm_payment_card';

    /** @deprecated */
    public const VIEW_ALL_CAPABILITY = 'dashboard_view_all_module_invoices';

    /** @deprecated */
    public const PAYMENT_CARD_CAPABILITY = 'dashboard_manage_network_payment_card';

    /** @var list<array{role: string, capability: string, reason: string}> */
    private array $lastCapabilitiesSynced = [];

    public function __construct(
        private readonly CapabilitiesMatrixService $matrix
    ) {}

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
        $networkPaysAll = (bool) $settings->network_pays_all;
        $firmsPayOwn = (bool) $settings->firms_pay_own;
        $usersPayOwn = (bool) $settings->users_pay_own;
        $networkResells = (bool) $settings->network_resells;

        // Never expose an empty “who pays?” selection — Network pays all is the default.
        if (! $networkPaysAll && ! $firmsPayOwn && ! $usersPayOwn && ! $networkResells) {
            $networkPaysAll = true;
        }

        return [
            'hub_id' => (int) $settings->hub_id,
            'network_pays_all' => $networkPaysAll,
            'firms_pay_own' => $firmsPayOwn,
            'users_pay_own' => $usersPayOwn,
            'network_resells' => $networkResells,
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
                'network_pays_all' => 'Network pays all module invoices (every pricing unit, one-time and ongoing). Saving this turns on Manage payment card + View / View all module invoices for Network / Client Admin (and Accounts Admin for card + view). Power Admin can still grant any role.',
                'firms_pay_own' => 'Each firm pays its own invoice when the unit is per firm (one invoice per firm). Other units stay with the network. Saving this turns on Manage payment card + View module invoices for Firm Admin and Accounts Admin so they can add the firm card and see firm invoices.',
                'users_pay_own' => 'Each user pays their own invoice when the unit is per user / per user (slots). Other units stay with the network. Saving this turns on View module invoices (+ Manage payment card) for User / Advisor / Admin-staff so they can see and pay their own invoices.',
                'network_resells' => 'Network pays the platform for all invoices, then may re-charge users with its own pricing. Disables “users pay own”. Requires logo + invoice text for branded invoices. Also enables network card + hub-wide invoice view caps like “Network pays all”.',
            ],
            'capability_sync' => [
                'network' => [
                    'capabilities' => [
                        self::NETWORK_CARD_CAPABILITY,
                        self::VIEW_NETWORK_CAPABILITY,
                    ],
                    'roles' => ['network_admin', 'client_admin', 'accounts_admin'],
                ],
                'firm' => [
                    'capabilities' => [
                        self::FIRM_CARD_CAPABILITY,
                        self::VIEW_FIRM_CAPABILITY,
                    ],
                    'roles' => ['firm_admin', 'accounts_admin'],
                ],
                'reseller' => [
                    'capabilities' => [self::VIEW_RESELLER_CAPABILITY],
                    'roles' => ['network_admin', 'client_admin', 'accounts_admin'],
                ],
                'user' => [
                    'note' => 'Personal card lives under Profile (no capability). Own module invoices appear under My invoices.',
                ],
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
        $settings = $settings->fresh() ?? $settings;

        // Auto-enable related Capabilities for the payer mode(s) just saved.
        $this->lastCapabilitiesSynced = $this->syncRelatedCapabilities($hub, $settings);

        return $settings;
    }

    /**
     * @return list<array{role: string, capability: string, reason: string}>
     */
    public function lastCapabilitiesSynced(): array
    {
        return $this->lastCapabilitiesSynced;
    }

    /**
     * When a payer mode is ON, turn on the Capabilities that role needs
     * (card + who sees those invoices). Never turns capabilities OFF.
     *
     * @return list<array{role: string, capability: string, reason: string}>
     */
    public function syncRelatedCapabilities(Hub $hub, HubModuleInvoiceSettings $settings): array
    {
        /** @var list<array{role: string, capability: string, reason: string}> $planned */
        $planned = [];

        $plan = function (array $roles, string $capability, string $reason) use (&$planned): void {
            foreach ($roles as $role) {
                $planned[] = [
                    'role' => $role,
                    'capability' => $capability,
                    'reason' => $reason,
                ];
            }
        };

        // Network always pays residual units when firm/user modes are partial,
        // and pays everything when network_pays_all / network_resells.
        $networkPays = $settings->network_pays_all
            || $settings->network_resells
            || $settings->firms_pay_own
            || $settings->users_pay_own;

        if ($networkPays) {
            $reason = 'network_payer';
            $plan(
                [User::ROLE_NETWORK_ADMIN, User::ROLE_CLIENT_ADMIN, User::ROLE_ACCOUNTS_ADMIN],
                self::NETWORK_CARD_CAPABILITY,
                $reason
            );
            $plan(
                [User::ROLE_NETWORK_ADMIN, User::ROLE_CLIENT_ADMIN, User::ROLE_ACCOUNTS_ADMIN, User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN],
                self::VIEW_NETWORK_CAPABILITY,
                $reason
            );
        }

        if ($settings->firms_pay_own) {
            $reason = 'firms_pay_own';
            foreach ([self::FIRM_CARD_CAPABILITY, self::VIEW_FIRM_CAPABILITY] as $cap) {
                $plan([User::ROLE_FIRM_ADMIN, User::ROLE_ACCOUNTS_ADMIN], $cap, $reason);
            }
        }

        if ($settings->network_resells) {
            $plan(
                [User::ROLE_NETWORK_ADMIN, User::ROLE_CLIENT_ADMIN, User::ROLE_ACCOUNTS_ADMIN, User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN],
                self::VIEW_RESELLER_CAPABILITY,
                'network_resells'
            );
        }

        // users_pay_own → personal Profile card (no capability) + My invoices.

        $newlyEnabled = $this->matrix->enableCapabilityGrants($hub, $planned);
        if ($newlyEnabled === []) {
            return [];
        }

        // Re-attach reason from the plan (first matching reason wins).
        $reasonByKey = [];
        foreach ($planned as $row) {
            $key = $row['role'].'|'.$row['capability'];
            if (! isset($reasonByKey[$key])) {
                $reasonByKey[$key] = $row['reason'];
            }
        }

        return array_map(function (array $row) use ($reasonByKey) {
            $key = $row['role'].'|'.$row['capability'];

            return [
                'role' => $row['role'],
                'capability' => $row['capability'],
                'reason' => $reasonByKey[$key] ?? 'payer_settings',
            ];
        }, $newlyEnabled);
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
     * Network payer of record: prefer anyone with Manage payment card + saved card,
     * then card-capable users without a card, then legacy client-admin fallbacks.
     */
    public function resolveNetworkPayer(Hub $hub, ?User $actor = null): User
    {
        $candidates = User::query()
            ->where('is_suspended', false)
            ->where('is_discontinued', false)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u) => $this->matrix->userCan($hub, $u, self::NETWORK_CARD_CAPABILITY))
            ->values();

        $networkProfile = app(PaymentCardService::class)->networkProfile($hub);
        if ($networkProfile?->managed_by_user_id) {
            $manager = User::query()->find($networkProfile->managed_by_user_id);
            if ($manager) {
                return $manager;
            }
        }

        $withCard = $candidates->first(fn (User $u) => filled($u->stripe_payment_method_id));
        if ($withCard) {
            return $withCard;
        }

        if ($actor && $this->matrix->userCan($hub, $actor, self::NETWORK_CARD_CAPABILITY)) {
            return $actor;
        }

        if ($candidates->isNotEmpty()) {
            return $candidates->first();
        }

        $query = User::query()
            ->where('is_suspended', false)
            ->where('is_discontinued', false)
            ->orderBy('id');

        $clientAdmin = (clone $query)
            ->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin'])
            ->whereNotNull('stripe_payment_method_id')
            ->first()
            ?: (clone $query)->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin'])->first();

        return $clientAdmin ?: ($actor ?: User::query()->orderBy('id')->firstOrFail());
    }

    /**
     * Firm payer: prefer firm members with Manage payment card (+ card), else firm head, else network.
     */
    public function resolveFirmPayer(Hub $hub, int $firmId, ?User $actor = null): User
    {
        $users = User::query()
            ->where('firm_id', $firmId)
            ->where('is_suspended', false)
            ->where('is_discontinued', false)
            ->orderBy('id')
            ->get();

        $capable = $users
            ->filter(fn (User $u) => $this->matrix->userCan($hub, $u, self::FIRM_CARD_CAPABILITY))
            ->values();

        $firmProfile = app(PaymentCardService::class)->firmProfile($hub, $firmId);
        if ($firmProfile?->managed_by_user_id) {
            $manager = $users->first(fn (User $u) => (int) $u->id === (int) $firmProfile->managed_by_user_id)
                ?: User::query()->find($firmProfile->managed_by_user_id);
            if ($manager) {
                return $manager;
            }
        }

        $withCard = $capable->first(fn (User $u) => filled($u->stripe_payment_method_id));
        if ($withCard) {
            return $withCard;
        }

        if ($capable->isNotEmpty()) {
            return $capable->first();
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
     * Module invoices page: network and/or firm scopes only (by capability).
     * User-scoped invoices belong on My invoices (and reseller page for admins).
     */
    public function viewerCanSeeBilling(
        User $viewer,
        Hub $hub,
        string $payerScope,
        ?int $firmId,
        ?int $billedUserId
    ): bool {
        if ($payerScope === self::PAYER_NETWORK) {
            return $this->matrix->userCan($hub, $viewer, self::VIEW_NETWORK_CAPABILITY)
                || $this->matrix->userCan($hub, $viewer, 'dashboard_view_module_invoices')
                || $this->matrix->userCan($hub, $viewer, 'dashboard_view_all_module_invoices');
        }

        if ($payerScope === self::PAYER_FIRM) {
            if (! $this->forHub($hub)->firms_pay_own) {
                return false;
            }
            if (! (
                $this->matrix->userCan($hub, $viewer, self::VIEW_FIRM_CAPABILITY)
                || $this->matrix->userCan($hub, $viewer, 'dashboard_view_module_invoices')
                || $this->matrix->userCan($hub, $viewer, 'dashboard_view_all_module_invoices')
            )) {
                return false;
            }
            $viewerFirmId = $viewer->firm_id ? (int) $viewer->firm_id : null;
            $viewerFirm = $viewerFirmId ? Firm::query()->find($viewerFirmId) : null;
            $isNetworkLevel = ! $viewerFirmId || ($viewerFirm && $viewerFirm->isCentral());
            if ($isNetworkLevel || $this->matrix->userCan($hub, $viewer, 'dashboard_view_all_module_invoices')) {
                return true;
            }

            return $firmId && $viewerFirmId && (int) $firmId === $viewerFirmId;
        }

        // User-scoped: not listed on Module invoices page.
        return false;
    }

    /**
     * Reseller invoices page — network→user charges when network_resells is on.
     */
    public function viewerCanSeeResellerBilling(
        User $viewer,
        Hub $hub,
        string $payerScope,
        ?int $billedUserId,
        array $meta = []
    ): bool {
        if (! $this->forHub($hub)->network_resells && empty($meta['network_resells'])) {
            return false;
        }
        if (! $this->matrix->userCan($hub, $viewer, self::VIEW_RESELLER_CAPABILITY)) {
            return false;
        }

        return $payerScope === self::PAYER_USER || ! empty($meta['network_resells']);
    }
}
