<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\HubVisibilityTransitionService;
use App\Services\ModuleBillingService;
use App\Services\SubscriberCreditsService;
use App\Services\WhiteLabelDatabaseService;
use App\Services\WhiteLabelHubSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class PowerAdminHubController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly HubVisibilityTransitionService $visibilityTransitions,
        private readonly SubscriberCreditsService $subscriberCredits,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly WhiteLabelHubSyncService $whiteLabelSync,
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly ModuleBillingService $moduleBilling
    ) {}

    public function index(): JsonResponse
    {
        // Ensure this deploy's Central row is type=central before listing
        // (avoids 403 control-plane gates after a fresh Central install).
        $this->hubs->current();

        $hubs = Hub::query()
            ->orderByRaw('CASE
                WHEN type = ? THEN 0
                WHEN type = ? THEN 1
                ELSE 2
            END', [Hub::TYPE_CENTRAL, Hub::TYPE_SHARED])
            ->orderBy('name')
            ->get()
            ->map(function (Hub $hub) {
                try {
                    return $hub->toAdminArray();
                } catch (\Throwable $e) {
                    report($e);

                    return [
                        'id' => $hub->id,
                        'name' => $hub->name,
                        'slug' => $hub->slug,
                        'type' => $hub->type,
                        'is_central' => $hub->isCentral(),
                        'is_control_plane' => $hub->isControlPlane(),
                        'is_content_hub' => $hub->isContentHub(),
                        'is_active' => (bool) $hub->is_active,
                        'branding' => [
                            'application_name' => $hub->name,
                            'logo_url' => null,
                            'white_logo_url' => null,
                            'favicon_url' => null,
                            'auth_bg_image_url' => null,
                            'from_email' => $hub->from_email,
                            'primary_color' => $hub->primary_color,
                            'secondary_color' => $hub->secondary_color,
                            'accent_color' => $hub->accent_color,
                            'color_scheme' => [
                                'primary' => $hub->primary_color,
                                'secondary' => $hub->secondary_color,
                                'accent' => $hub->accent_color,
                            ],
                        ],
                        'deploy' => [
                            'ready' => false,
                            'status' => 'error',
                            'status_label' => 'Failed to load hub details: '.$e->getMessage(),
                        ],
                        'stripe' => [
                            'key' => null,
                            'secret_set' => false,
                            'webhook_secret_set' => false,
                            'currency' => null,
                        ],
                        'subscriber_credits' => [
                            'unlimited' => true,
                            'credits' => null,
                        ],
                        'checklist' => [],
                        'checklist_groups' => [],
                        'created_at' => $hub->created_at,
                        'updated_at' => $hub->updated_at,
                        'load_error' => $e->getMessage(),
                    ];
                }
            })
            ->values();

        return response()->json([
            'hubs' => $hubs,
            'checklist_definitions' => $this->definitionsPayload(),
            'checklist_groups' => $this->functionalityGroupsPayload(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->hubs->ensureRemoteDatabaseColumns();
        $this->normalizeOptionalUrlFields($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:hubs,slug'],
            'type' => ['sometimes', 'string', Rule::in([Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
            'accent_color' => ['nullable', 'string', 'max:32'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'favicon_url' => ['nullable', 'string', 'max:2048'],
            'frontend_url' => ['nullable', 'string', 'max:2048', 'url'],
            'api_url' => ['nullable', 'string', 'max:2048', 'url'],
            'deploy_notes' => ['nullable', 'string', 'max:5000'],
            'db_driver' => ['nullable', 'string', Rule::in(['mysql', 'pgsql', 'sqlsrv'])],
            'db_host' => ['nullable', 'string', 'max:255'],
            'db_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['nullable', 'string', 'max:255'],
            'db_username' => ['nullable', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:1000'],
            'db_ssl_mode' => ['nullable', 'string', Rule::in(['disabled', 'preferred', 'required', 'verify_ca'])],
            'db_ssl_ca' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
            'checklist' => ['sometimes', 'array'],
        ]);

        $type = $validated['type'] ?? Hub::TYPE_WHITE_LABEL;
        if ($type === Hub::TYPE_CENTRAL) {
            return response()->json([
                'message' => 'Cannot create another Central Hub Controller via the registry.',
            ], 422);
        }

        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        if ($slug === '' || Hub::query()->where('slug', $slug)->exists()) {
            $slug = Str::slug($validated['name']).'-'.Str::lower(Str::random(4));
        }
        if ($slug === 'central') {
            return response()->json([
                'message' => 'The slug “central” is reserved for the Central Hub Controller.',
            ], 422);
        }

        $checklist = array_key_exists('checklist', $validated)
            ? $this->hubs->sanitizeChecklist($validated['checklist'], $type)
            : Hub::defaultChecklist($type);

        try {
            $hub = Hub::query()->create([
                'name' => $validated['name'],
                'slug' => $slug,
                'type' => $type,
                'is_active' => $validated['is_active'] ?? true,
                'primary_color' => $validated['primary_color'] ?? null,
                'secondary_color' => $validated['secondary_color'] ?? null,
                'accent_color' => $validated['accent_color'] ?? null,
                'logo_url' => $validated['logo_url'] ?? null,
                'favicon_url' => $validated['favicon_url'] ?? null,
                'frontend_url' => $validated['frontend_url'] ?? null,
                'api_url' => $validated['api_url'] ?? null,
                'deploy_notes' => $validated['deploy_notes'] ?? null,
                'db_driver' => $validated['db_driver'] ?? 'mysql',
                'db_host' => $validated['db_host'] ?? null,
                'db_port' => $validated['db_port'] ?? null,
                'db_database' => $validated['db_database'] ?? null,
                'db_username' => $validated['db_username'] ?? null,
                'db_password' => $validated['db_password'] ?? null,
                'db_ssl_mode' => $validated['db_ssl_mode'] ?? 'disabled',
                'db_ssl_ca' => $validated['db_ssl_ca'] ?? null,
                'checklist' => $checklist,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not create hub: '.$e->getMessage(),
            ], 422);
        }

        $moduleInvoices = [];
        $billingWarning = null;
        if ($request->user()) {
            try {
                $moduleInvoices = $this->moduleBilling->invoiceEnabledModules($hub->fresh(), $request->user());
            } catch (\Throwable $e) {
                // Never fail hub registration because module invoicing broke
                // (e.g. incomplete Central seed / missing pricing rows).
                report($e);
                $billingWarning = 'Hub was registered, but module invoicing failed: '.$e->getMessage();
            }
        }

        $label = $type === Hub::TYPE_SHARED ? 'Shared hub' : 'White-labelled hub';
        $message = $label.' created.';
        if ($billingWarning) {
            $message .= ' '.$billingWarning;
        }

        return response()->json([
            'message' => $message,
            'hub' => $hub->fresh()->toAdminArray(),
            'module_invoices' => collect($moduleInvoices)->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'type' => $invoice->type,
                'types' => $invoice->types,
                'amount' => $invoice->amount,
                'status' => $invoice->status,
                'description' => $invoice->description,
            ])->all(),
        ], 201);
    }

    public function show(Hub $hub): JsonResponse
    {
        return response()->json([
            'hub' => $hub->toAdminArray(),
            'checklist_definitions' => $this->definitionsPayload(),
            'checklist_groups' => $this->functionalityGroupsPayload(),
        ]);
    }

    public function update(Request $request, Hub $hub): JsonResponse
    {
        $this->hubs->ensureRemoteDatabaseColumns();
        $this->normalizeOptionalUrlFields($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('hubs', 'slug')->ignore($hub->id),
            ],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
            'accent_color' => ['nullable', 'string', 'max:32'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'favicon_url' => ['nullable', 'string', 'max:2048'],
            'frontend_url' => ['nullable', 'string', 'max:2048', 'url'],
            'api_url' => ['nullable', 'string', 'max:2048', 'url'],
            'deploy_notes' => ['nullable', 'string', 'max:5000'],
            'db_driver' => ['nullable', 'string', Rule::in(['mysql', 'pgsql', 'sqlsrv'])],
            'db_host' => ['nullable', 'string', 'max:255'],
            'db_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['nullable', 'string', 'max:255'],
            'db_username' => ['nullable', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:1000'],
            'clear_db_password' => ['sometimes', 'boolean'],
            'db_ssl_mode' => ['nullable', 'string', Rule::in(['disabled', 'preferred', 'required', 'verify_ca'])],
            'db_ssl_ca' => ['nullable', 'string', 'max:5000'],
            'clear_db_ssl_ca' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            // null / omitted unlimited flag + credits: Power Admin subscriber allotment
            'subscriber_credits_unlimited' => ['sometimes', 'boolean'],
            'subscriber_credits' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        // Central / Shared control-plane rows stay stable.
        if ($hub->isControlPlane() && array_key_exists('slug', $validated)) {
            unset($validated['slug']);
        }

        if ($hub->isControlPlane() && array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            return response()->json([
                'message' => 'The Central Hub Controller cannot be deactivated.',
            ], 422);
        }

        $creditsPayload = array_intersect_key($validated, array_flip([
            'subscriber_credits_unlimited',
            'subscriber_credits',
        ]));
        unset($validated['subscriber_credits_unlimited'], $validated['subscriber_credits']);

        $clearDbPassword = (bool) ($validated['clear_db_password'] ?? false);
        unset($validated['clear_db_password']);
        $clearDbSslCa = (bool) ($validated['clear_db_ssl_ca'] ?? false);
        unset($validated['clear_db_ssl_ca']);

        // Blank password on update means "keep existing".
        if (array_key_exists('db_password', $validated) && ! filled($validated['db_password'])) {
            unset($validated['db_password']);
        }
        if (array_key_exists('db_ssl_ca', $validated) && ! filled($validated['db_ssl_ca'])) {
            unset($validated['db_ssl_ca']);
        }

        try {
            $hub->fill($validated);
            if ($clearDbPassword) {
                $hub->db_password = null;
            }
            if ($clearDbSslCa) {
                $hub->db_ssl_ca = null;
            }
            $hub->save();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not save hub wiring: '.$e->getMessage(),
            ], 422);
        }

        if ($creditsPayload !== []) {
            $user = $request->user();
            if (! $user || ! $this->matrix->roleCan($hub, (string) $user->role, 'dashboard_manage_subscriber_credits')) {
                return response()->json([
                    'message' => 'Setting subscriber credits is disabled for your role. Enable it in Power Admin → Capabilities.',
                ], 403);
            }

            $unlimited = array_key_exists('subscriber_credits_unlimited', $creditsPayload)
                ? (bool) $creditsPayload['subscriber_credits_unlimited']
                : $hub->givesUnlimitedSubscriberCredits();

            if ($unlimited) {
                $this->subscriberCredits->updateHubSetting($hub, null);
            } else {
                if (! array_key_exists('subscriber_credits', $creditsPayload)
                    || $creditsPayload['subscriber_credits'] === null) {
                    throw ValidationException::withMessages([
                        'subscriber_credits' => 'Enter the number of credits per subscriber, or choose Unlimited.',
                    ]);
                }
                $this->subscriberCredits->updateHubSetting($hub, (int) $creditsPayload['subscriber_credits']);
            }
        }

        $this->hubs->forgetCurrentCache();

        $syncWarning = null;
        try {
            $this->syncWhiteLabelSettings($hub->fresh());
        } catch (InvalidArgumentException $e) {
            // Credentials may still be valid locally; remote host often unreachable
            // from Central (e.g. tenant "localhost"). Keep the save; surface a warning.
            $syncWarning = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $syncWarning = 'Remote sync failed: '.$e->getMessage();
        }

        $message = 'Hub updated successfully.';
        if ($syncWarning) {
            $message .= ' '.$syncWarning;
        }

        return response()->json([
            'message' => $message,
            'hub' => $hub->fresh()->toAdminArray(),
            'sync_warning' => $syncWarning,
        ]);
    }

    public function updateChecklist(Request $request, Hub $hub): JsonResponse
    {
        $validated = $request->validate([
            'checklist' => ['required', 'array'],
        ]);

        // Accept either { checklist: { key: bool } } or { checklist: [ { key, enabled } ] }
        $input = $this->normalizeChecklistPayload($validated['checklist']);

        $unknown = array_diff(array_keys($input), array_keys(Hub::CHECKLIST_DEFINITIONS));
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown checklist keys: '.implode(', ', $unknown),
            ], 422);
        }

        // Hub checklist screen edits Functionalities only; ignore capability keys.
        $input = array_filter(
            $input,
            fn ($key) => Hub::isFunctionalityKey((string) $key),
            ARRAY_FILTER_USE_KEY
        );

        // Module toggles are owned by dashboard_manage_modules — not this screen.
        $canManageModules = false;
        $actor = $request->user();
        if ($actor) {
            $canManageModules = $this->matrix->roleCan(
                $hub,
                (string) $actor->role,
                'dashboard_manage_modules'
            );
        }
        if (! $canManageModules) {
            $input = array_filter(
                $input,
                fn ($key) => ! Hub::isModuleKey((string) $key),
                ARRAY_FILTER_USE_KEY
            );
        }

        $before = $hub->resolvedChecklist();
        $merged = $this->hubs->mergeChecklist($hub, $input);
        $transitionType = $this->visibilityTransitions->detectTransition($before, $merged);
        $checklist = $this->visibilityTransitions->applyModeFlags($merged, $transitionType);

        // Keep a previously configured fixed allotment when returning to private
        // (PRIVATE_MODE_FLAGS defaults unlimited; do not wipe Power Admin setting).
        if ($transitionType === 'public_to_private' && ! $hub->givesUnlimitedSubscriberCredits()) {
            $checklist['unlimited_credits'] = false;
            $checklist['paid_credits'] = true;
        }

        $this->subscriberCredits->syncFieldFromChecklist($hub, $checklist);

        $hub->checklist = $checklist;
        $hub->save();

        $this->hubs->forgetCurrentCache();
        $hub = $hub->fresh();

        $moduleInvoices = [];
        if ($actor) {
            $chargeWasOff = ! (bool) ($before['charge_amount_per_module'] ?? false);
            $chargeIsOn = $hub->can('charge_amount_per_module');

            if ($chargeWasOff && $chargeIsOn) {
                // Turning the functionality on bills every currently enabled module once.
                $moduleInvoices = $this->moduleBilling->invoiceEnabledModules($hub, $actor);
            } else {
                $newlyEnabled = $this->moduleBilling->newlyEnabledModuleKeys($hub, $before, $checklist);
                $moduleInvoices = $this->moduleBilling->invoiceNewlyEnabledModules(
                    $hub,
                    $newlyEnabled,
                    $actor
                );
            }
        }

        $usersConnection = null;
        $syncWarning = null;
        try {
            if ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
                $this->whiteLabelSync->pushSettings($hub);
                $usersConnection = $this->remoteDb->connect($hub);
            } elseif ($hub->isContentHub()) {
                $syncWarning = 'This hub has no remote database wiring, so the live content hub site was not updated.';
            }
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'hub' => $hub->toAdminArray(),
            ], 422);
        }

        try {
            $sideEffects = $this->visibilityTransitions->runSideEffects(
                $hub,
                $transitionType,
                $usersConnection
            );
        } finally {
            if ($usersConnection) {
                $this->remoteDb->disconnect($hub);
            }
        }

        $message = 'Checklist updated successfully.';
        if ($sideEffects['type'] === 'private_to_public') {
            $message = sprintf(
                'Hub switched to public. Suspended %d imported advisor(s) and stopped advisor auto-renew.',
                $sideEffects['advisors_suspended']
            );
        } elseif ($sideEffects['type'] === 'public_to_private') {
            $message = sprintf(
                'Hub switched to private. Reactivated %d imported advisor(s).',
                $sideEffects['advisors_reactivated']
            );
        }
        if ($syncWarning) {
            $message .= ' '.$syncWarning;
        }

        return response()->json([
            'message' => $message,
            'hub' => $hub->fresh()->toAdminArray(),
            'visibility_transition' => $sideEffects,
            'module_invoices' => collect($moduleInvoices)->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'type' => $invoice->type,
                'types' => $invoice->types,
                'amount' => $invoice->amount,
                'status' => $invoice->status,
                'description' => $invoice->description,
            ])->all(),
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $checklist
     * @return array<string, mixed>
     */
    private function normalizeChecklistPayload(array $checklist): array
    {
        // List form: [ { key: 'public_subscribe', enabled: true }, ... ]
        if (array_is_list($checklist)) {
            $map = [];
            foreach ($checklist as $row) {
                if (! is_array($row) || ! isset($row['key'])) {
                    continue;
                }
                $enabled = $row['enabled'] ?? $row['value'] ?? null;
                if ($enabled === null) {
                    continue;
                }
                $map[(string) $row['key']] = $enabled;
            }

            return $map;
        }

        return $checklist;
    }

    /**
     * Functionalities definitions only (hub checklist screen).
     *
     * @return list<array{key: string, label: string, description: string, group: string, group_label: string, default_shared: bool, default_white_label: bool, exclusive_with: ?string}>
     */
    private function definitionsPayload(): array
    {
        $items = [];
        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            $group = $meta['group'] ?? Hub::GROUP_BEHAVIOUR;
            if (! in_array($group, Hub::FUNCTIONALITY_GROUPS, true)) {
                continue;
            }
            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => $group,
                'group_label' => Hub::CHECKLIST_GROUPS[$group] ?? 'Other',
                'default_shared' => $meta['default_shared'],
                'default_white_label' => $meta['default_white_label'],
                'exclusive_with' => Hub::CHECKLIST_OPPOSITES[$key] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function functionalityGroupsPayload(): array
    {
        $groups = [];
        foreach (Hub::FUNCTIONALITY_GROUPS as $group) {
            $groups[$group] = Hub::CHECKLIST_GROUPS[$group] ?? $group;
        }

        return $groups;
    }

    /**
     * Push registry settings onto the white-labelled hub's own database when wired.
     */
    private function syncWhiteLabelSettings(?Hub $hub): void
    {
        if (! $hub || $hub->isControlPlane() || ! $hub->hasRemoteDatabaseConfigured()) {
            return;
        }

        $this->whiteLabelSync->pushSettings($hub);
    }

    /**
     * Empty strings fail Laravel's "url" rule; treat them as null.
     */
    private function normalizeOptionalUrlFields(Request $request): void
    {
        $merge = [];
        foreach (['frontend_url', 'api_url', 'deploy_notes', 'logo_url', 'favicon_url', 'db_host', 'db_database', 'db_username', 'db_password', 'db_ssl_ca'] as $key) {
            if ($request->exists($key) && $request->input($key) === '') {
                $merge[$key] = null;
            }
        }
        if ($request->exists('db_port') && $request->input('db_port') === '') {
            $merge['db_port'] = null;
        }
        if ($merge !== []) {
            $request->merge($merge);
        }
    }
}
