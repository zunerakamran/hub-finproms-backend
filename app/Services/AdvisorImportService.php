<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Models\UserSubscription;
use App\Support\AdvisorImportXlsxTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AdvisorImportService
{
    /**
     * Roles that may be assigned via Excel import on a private / white-labelled hub.
     * Control-plane roles (power_admin, finproms_admin) are never importable.
     *
     * @var list<string>
     */
    public const IMPORTABLE_ROLES = [
        User::ROLE_CLIENT_ADMIN,
        User::ROLE_MANAGER,
        User::ROLE_APPROVER,
        User::ROLE_ADVISOR,
        User::ROLE_ADMIN_STAFF,
        User::ROLE_USER,
    ];

    public function __construct(
        private readonly HubService $hubs,
        private readonly SubscriberCreditsService $subscriberCredits
    ) {}

    /**
     * Parse + commit immediately (used when advisor billing is off).
     *
     * @return array{
     *   created: list<array{name: string, email: string, temporary_password: ?string}>,
     *   updated: list<array{name: string, email: string}>,
     *   reactivated: list<array{name: string, email: string}>,
     *   skipped: list<array{row: int, email: ?string, reason: string}>,
     *   summary: array{total_rows: int, created: int, updated: int, reactivated: int, skipped: int, billable_batch: int}
     * }
     */
    public function import(UploadedFile $file, ?Hub $hub = null, ?string $connection = null): array
    {
        $plan = $this->buildPlan($file, $hub, $connection);

        return $this->commitPending($plan['pending'], $hub, $connection, $plan['skipped']);
    }

    /**
     * Classify rows without creating users. Used when payment is required first.
     *
     * @return array{
     *   pending: list<array{action: string, name: string, email: string, password: string, role: string, firm_id: ?int, firm: ?string, modules: list<string>}>,
     *   preview: array{created: list<array{name: string, email: string, role: ?string, firm: ?string, modules: list<string>}>, updated: list<array{name: string, email: string, role: ?string, firm: ?string, modules: list<string>}>, reactivated: list<array{name: string, email: string, role: ?string, firm: ?string, modules: list<string>}>},
     *   skipped: list<array{row: int, email: ?string, reason: string}>,
     *   summary: array{total_rows: int, created: int, updated: int, reactivated: int, skipped: int, billable_batch: int}
     * }
     */
    public function buildPlan(UploadedFile $file, ?Hub $hub = null, ?string $connection = null): array
    {
        $rows = $this->parseFile($file);
        $hub ??= $this->hubs->current();
        $connection ??= config('database.default');

        if ($rows === []) {
            throw new RuntimeException(
                'No user rows were found. Make sure the first non-empty row contains headers like name, email, password, role, firm, modules.'
            );
        }

        $pending = [];
        $previewCreated = [];
        $previewUpdated = [];
        $previewReactivated = [];
        $skipped = [];
        $billableBatch = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $name = trim((string) ($row['name'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $password = trim((string) ($row['password'] ?? ''));
            $roleInput = trim((string) ($row['role'] ?? ''));
            $firmName = trim((string) ($row['firm'] ?? ''));
            $modulesInput = trim((string) ($row['modules'] ?? ''));

            if ($email === '' && $name === '') {
                continue;
            }

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'email' => $email !== '' ? $email : null,
                    'reason' => 'Valid email is required.',
                ];
                continue;
            }

            if ($name === '') {
                $name = Str::before($email, '@');
            }

            $role = $this->resolveRole($hub, $roleInput);
            if ($role === null) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'email' => $email,
                    'reason' => $this->invalidRoleReason($hub, $roleInput),
                ];
                continue;
            }

            if ($firmName === '') {
                $skipped[] = [
                    'row' => $rowNumber,
                    'email' => $email,
                    'reason' => 'Firm is required.',
                ];
                continue;
            }

            $firm = Firm::on($connection)
                ->whereRaw('LOWER(name) = ?', [strtolower($firmName)])
                ->first();

            if (! $firm) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'email' => $email,
                    'reason' => 'Unknown firm "'.$firmName.'". Add the firm first, then re-import.',
                ];
                continue;
            }

            $modules = $this->resolveModules($hub, $modulesInput);
            $firmId = (int) $firm->id;
            $resolvedFirmName = (string) $firm->name;
            $roleLabel = $hub->roleLabel($role);
            $moduleLabels = array_map(fn (string $key) => $hub->moduleLabel($key), $modules);

            $user = User::on($connection)->where('email', $email)->first();

            if ($user) {
                if (ActingHubService::isControlPlaneRole((string) $user->role)) {
                    $skipped[] = [
                        'row' => $rowNumber,
                        'email' => $email,
                        'reason' => 'Email belongs to a control-plane admin account.',
                    ];
                    continue;
                }

                $wasInactive = $user->isDiscontinued() || $user->isSuspended();
                $action = $wasInactive ? 'reactivate' : 'update';
                $pending[] = [
                    'action' => $action,
                    'name' => $name,
                    'email' => $email,
                    'password' => '',
                    'role' => $role,
                    'firm_id' => $firmId,
                    'firm' => $resolvedFirmName,
                    'modules' => $modules,
                ];

                $previewRow = [
                    'name' => $name,
                    'email' => $email,
                    'role' => $roleLabel,
                    'firm' => $resolvedFirmName,
                    'modules' => $moduleLabels,
                ];

                if ($action === 'reactivate') {
                    $previewReactivated[] = $previewRow;
                    if ($this->roleIsAdvisor($role)) {
                        $billableBatch++;
                    }
                } else {
                    $previewUpdated[] = $previewRow;
                }

                continue;
            }

            $pending[] = [
                'action' => 'create',
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'firm_id' => $firmId,
                'firm' => $resolvedFirmName,
                'modules' => $modules,
            ];
            $previewCreated[] = [
                'name' => $name,
                'email' => $email,
                'role' => $roleLabel,
                'firm' => $resolvedFirmName,
                'modules' => $moduleLabels,
            ];
            if ($this->roleIsAdvisor($role)) {
                $billableBatch++;
            }
        }

        return [
            'pending' => $pending,
            'preview' => [
                'created' => $previewCreated,
                'updated' => $previewUpdated,
                'reactivated' => $previewReactivated,
            ],
            'skipped' => $skipped,
            'summary' => [
                'total_rows' => count($rows),
                'created' => count($previewCreated),
                'updated' => count($previewUpdated),
                'reactivated' => count($previewReactivated),
                'skipped' => count($skipped),
                'billable_batch' => $billableBatch,
            ],
        ];
    }

    /**
     * Apply a previously staged import plan (after payment method is chosen).
     *
     * @param  list<array{action: string, name: string, email: string, password?: string, role?: string, firm_id?: ?int, modules?: list<string>}>  $pending
     * @param  list<array{row: int, email: ?string, reason: string}>  $skipped
     * @return array{
     *   created: list<array{name: string, email: string, temporary_password: ?string, role: ?string, firm: ?string, modules: list<string>}>,
     *   updated: list<array{name: string, email: string, role: ?string, firm: ?string, modules: list<string>}>,
     *   reactivated: list<array{name: string, email: string, role: ?string, firm: ?string, modules: list<string>}>,
     *   skipped: list<array{row: int, email: ?string, reason: string}>,
     *   summary: array{total_rows: int, created: int, updated: int, reactivated: int, skipped: int, billable_batch: int}
     * }
     */
    public function commitPending(
        array $pending,
        ?Hub $hub = null,
        ?string $connection = null,
        array $skipped = []
    ): array {
        $hub ??= $this->hubs->current();
        $connection ??= config('database.default');

        $created = [];
        $updated = [];
        $reactivated = [];

        foreach ($pending as $index => $row) {
            $action = (string) ($row['action'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $password = trim((string) ($row['password'] ?? ''));
            $role = trim((string) ($row['role'] ?? ''));
            $firmId = isset($row['firm_id']) && $row['firm_id'] !== null && $row['firm_id'] !== ''
                ? (int) $row['firm_id']
                : null;
            $modules = isset($row['modules']) && is_array($row['modules'])
                ? $hub->normalizeUserModules($row['modules'])
                : $hub->normalizeUserModules([]);

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = [
                    'row' => $index + 1,
                    'email' => $email !== '' ? $email : null,
                    'reason' => 'Valid email is required.',
                ];
                continue;
            }

            if ($name === '') {
                $name = Str::before($email, '@');
            }

            if (! in_array($role, self::IMPORTABLE_ROLES, true)) {
                $skipped[] = [
                    'row' => $index + 1,
                    'email' => $email,
                    'reason' => $this->invalidRoleReason($hub, $role),
                ];
                continue;
            }

            if ($firmId === null) {
                $skipped[] = [
                    'row' => $index + 1,
                    'email' => $email,
                    'reason' => 'Firm is required.',
                ];
                continue;
            }

            $isAdvisor = $this->roleIsAdvisor($role);

            try {
                $result = DB::connection($connection)->transaction(function () use (
                    $action,
                    $name,
                    $email,
                    $password,
                    $role,
                    $isAdvisor,
                    $firmId,
                    $modules,
                    $hub,
                    $connection
                ) {
                    $user = User::on($connection)->where('email', $email)->first();
                    $temporaryPassword = null;

                    if ($user) {
                        if (ActingHubService::isControlPlaneRole((string) $user->role)) {
                            return [
                                'status' => 'skipped',
                                'reason' => 'Email belongs to a control-plane admin account.',
                            ];
                        }

                        $wasInactive = $user->isDiscontinued() || $user->isSuspended();

                        $user->fill([
                            'name' => $name,
                            'role' => $role,
                            'is_advisor' => $isAdvisor,
                            'is_suspended' => false,
                            'is_discontinued' => false,
                            'discontinued_at' => null,
                            'firm_id' => $firmId,
                            'modules' => $modules,
                        ]);
                        if (! $isAdvisor) {
                            $user->allows_admin_staff_acting = false;
                        }
                        $user->save();

                        if ($isAdvisor) {
                            $this->ensureAdvisorSubscription($user);
                        }
                        if ($wasInactive) {
                            $this->subscriberCredits->applyToImportedSubscriber($user->fresh(), $hub, true);
                        }

                        return [
                            'status' => $wasInactive ? 'reactivated' : 'updated',
                            'user' => $user->fresh('firm'),
                        ];
                    }

                    if ($action !== 'create' && $action !== '') {
                        // Stale plan row (e.g. already created) — treat as create if missing.
                    }

                    if ($password === '') {
                        $temporaryPassword = Str::password(12);
                        $password = $temporaryPassword;
                    }

                    $user = User::on($connection)->create([
                        'name' => $name,
                        'email' => $email,
                        'password' => $password,
                        'role' => $role,
                        'credits' => 0,
                        'is_advisor' => $isAdvisor,
                        'allows_admin_staff_acting' => false,
                        'has_unlimited_credits' => false,
                        'is_suspended' => false,
                        'is_discontinued' => false,
                        'firm_id' => $firmId,
                        'modules' => $modules,
                    ]);

                    if ($isAdvisor) {
                        $this->ensureAdvisorSubscription($user);
                    }
                    $this->subscriberCredits->applyToImportedSubscriber($user->fresh(), $hub, true);

                    return [
                        'status' => 'created',
                        'user' => $user->fresh('firm'),
                        'temporary_password' => $temporaryPassword,
                    ];
                });
            } catch (\Throwable $e) {
                $skipped[] = [
                    'row' => $index + 1,
                    'email' => $email,
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            if (($result['status'] ?? null) === 'skipped') {
                $skipped[] = [
                    'row' => $index + 1,
                    'email' => $email,
                    'reason' => $result['reason'] ?? 'Skipped.',
                ];
                continue;
            }

            $firmLabel = $result['user']->firm?->name ?? ($row['firm'] ?? null);
            $roleLabel = $hub->roleLabel((string) $result['user']->role);
            $moduleLabels = array_map(
                fn (string $key) => $hub->moduleLabel($key),
                is_array($result['user']->modules) ? $result['user']->modules : $modules
            );

            if ($result['status'] === 'created') {
                $created[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'temporary_password' => $result['temporary_password'],
                    'role' => $roleLabel,
                    'firm' => $firmLabel,
                    'modules' => $moduleLabels,
                ];
                app(FunctionalMailService::class)->advisorInvite(
                    $result['user'],
                    $result['temporary_password'] ?? null
                );
            } elseif ($result['status'] === 'reactivated') {
                $reactivated[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'role' => $roleLabel,
                    'firm' => $firmLabel,
                    'modules' => $moduleLabels,
                ];
                app(FunctionalMailService::class)->advisorReactivated($result['user']);
            } else {
                $updated[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'role' => $roleLabel,
                    'firm' => $firmLabel,
                    'modules' => $moduleLabels,
                ];
            }
        }

        app(FunctionalMailService::class)->adminAdvisorImportSummary($created, $reactivated);

        $billable = count(array_filter($created, fn ($row) => $this->previewRowIsAdvisor($hub, $row)))
            + count(array_filter($reactivated, fn ($row) => $this->previewRowIsAdvisor($hub, $row)));

        return [
            'created' => $created,
            'updated' => $updated,
            'reactivated' => $reactivated,
            'skipped' => $skipped,
            'summary' => [
                'total_rows' => count($pending) + count($skipped),
                'created' => count($created),
                'updated' => count($updated),
                'reactivated' => count($reactivated),
                'skipped' => count($skipped),
                'billable_batch' => $billable,
            ],
        ];
    }

    public function ensureAdvisorSubscription(User $user): UserSubscription
    {
        $connection = $user->getConnectionName();

        $existing = UserSubscription::on($connection)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'suspended', 'discontinued'])
            ->where('payment_method', 'advisor_import')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->status = 'active';
            $existing->payment_status = 'paid';
            if (! $existing->starts_at) {
                $existing->starts_at = now();
            }
            $existing->ends_at = $existing->starts_at->copy()->addMonth();
            $existing->save();

            return $existing;
        }

        $startsAt = now();

        return UserSubscription::on($connection)->create([
            'user_id' => $user->id,
            'subscription_plan_id' => null,
            'credits_granted' => 0,
            'amount_paid' => 0,
            'status' => 'active',
            'payment_method' => 'advisor_import',
            'payment_status' => 'paid',
            'payment_reference' => 'ADV-'.Str::upper(Str::random(8)),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMonth(),
        ]);
    }

    /**
     * Permanently end an imported advisor (WP FPSUB_Subscribers::end equivalent).
     * Blocks login/access immediately. Does not delete the user.
     * Re-importing the same email can restore them.
     */
    public function discontinue(User $advisor): User
    {
        if (! $advisor->isAdvisor()) {
            throw new RuntimeException('Only imported advisors can be discontinued.');
        }

        if ($advisor->isDiscontinued()) {
            return $advisor;
        }

        $advisor = DB::connection($advisor->getConnectionName())->transaction(function () use ($advisor) {
            $connection = $advisor->getConnectionName();
            $advisor->is_discontinued = true;
            $advisor->discontinued_at = now();
            $advisor->has_unlimited_credits = false;
            $advisor->save();

            if (method_exists($advisor, 'tokens')) {
                try {
                    $advisor->tokens()->delete();
                } catch (\Throwable) {
                    // Remote hubs may not have Sanctum tokens table wired the same way.
                }
            }

            UserSubscription::on($connection)
                ->where('user_id', $advisor->id)
                ->where('payment_method', 'advisor_import')
                ->whereIn('status', ['active', 'suspended'])
                ->update([
                    'status' => 'discontinued',
                    'ends_at' => now(),
                ]);

            return $advisor->fresh();
        });

        app(FunctionalMailService::class)->advisorDiscontinued($advisor);

        return $advisor;
    }

    /**
     * Downloadable Excel template with role/firm/modules dropdowns for this hub.
     */
    public function templateXlsx(?Hub $hub = null, ?string $connection = null): string
    {
        $hub ??= $this->hubs->current();
        $connection ??= config('database.default');

        $roleLabels = array_map(
            fn (array $role) => $role['label'],
            $this->importableRoleOptions($hub)
        );

        $firmNames = Firm::on($connection)
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();

        $modulePackages = $hub->importModulePackageLabels();

        return (new AdvisorImportXlsxTemplate)->build($roleLabels, $firmNames, $modulePackages);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function importableRoleOptions(Hub $hub): array
    {
        $roles = [];
        foreach (self::IMPORTABLE_ROLES as $role) {
            $roles[] = [
                'key' => $role,
                'label' => $hub->roleLabel($role),
            ];
        }

        return $roles;
    }

    /**
     * Resolve a sheet role value (key or display/renamed label) to a role key.
     */
    public function resolveRole(Hub $hub, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = Str::lower($value);
        $normalizedKey = str_replace([' ', '-'], '_', $normalized);

        if (ActingHubService::isControlPlaneRole($normalizedKey)) {
            return null;
        }

        foreach ($this->importableRoleOptions($hub) as $role) {
            $key = $role['key'];
            $label = Str::lower($role['label']);
            $default = Str::lower(User::ROLE_LABELS[$key] ?? $key);

            if (
                $normalizedKey === $key
                || $normalized === $label
                || $normalized === $default
            ) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Parse modules cell (comma / semicolon / pipe separated labels or keys).
     * Only hub-enabled modules are kept; base module is always included.
     * Unknown or hub-disabled modules are ignored (no access granted).
     *
     * @return list<string>
     */
    public function resolveModules(Hub $hub, string $value): array
    {
        $tokens = preg_split('/[,;|]+/', $value) ?: [];
        $requested = [];

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $resolved = $this->resolveModuleKey($hub, $token);
            if ($resolved !== null) {
                $requested[] = $resolved;
            }
        }

        return $hub->normalizeUserModules($requested);
    }

    /**
     * Resolve a single module token (key or label) to a module key if hub-enabled.
     * Base Shared / White Label Hub is recognised but omitted from template packages.
     */
    public function resolveModuleKey(Hub $hub, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = Str::lower($value);
        $normalizedKey = str_replace([' ', '-'], '_', $normalized);

        foreach ($hub->enabledModuleOptions() as $module) {
            $key = $module['key'];
            $label = Str::lower($module['label']);

            if (
                $normalizedKey === $key
                || $normalized === $label
                || $normalizedKey === str_replace([' ', '-'], '_', $label)
            ) {
                // Opposite locked base (e.g. White Label on a shared hub) is ignored.
                if (Hub::isLockedModuleKey($key) && $key !== $hub->baseModuleKey()) {
                    return null;
                }

                return $key;
            }
        }

        return null;
    }

    /**
     * @return list<array{name?: string, email?: string, password?: string, role?: string, firm?: string, modules?: string}>
     */
    public function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Unable to read uploaded file.');
        }

        if ($extension === 'xlsx') {
            return $this->parseXlsx($path);
        }

        if ($extension === 'xls') {
            throw new RuntimeException(
                'Legacy Excel (.xls) is not supported. Please save the sheet as .xlsx and upload that file.'
            );
        }

        if (in_array($extension, ['csv', 'txt'], true)) {
            throw new RuntimeException('CSV is no longer supported. Please upload an Excel (.xlsx) file.');
        }

        throw new RuntimeException('Unsupported file type. Upload an Excel (.xlsx) file.');
    }

    /**
     * @return list<array{name?: string, email?: string, password?: string, role?: string, firm?: string, modules?: string}>
     */
    private function parseXlsx(string $path): array
    {
        $libraryPath = base_path('vendor/shuchkin/simplexlsx/src/SimpleXLSX.php');
        if (! class_exists(\Shuchkin\SimpleXLSX::class)) {
            if (! is_file($libraryPath)) {
                throw new RuntimeException('Excel import library is missing. Please install the XLSX parser package.');
            }
            require_once $libraryPath;
        }

        $xlsx = \Shuchkin\SimpleXLSX::parse($path);
        if (! $xlsx) {
            $error = method_exists(\Shuchkin\SimpleXLSX::class, 'parseError')
                ? \Shuchkin\SimpleXLSX::parseError()
                : 'Unable to parse Excel file.';
            throw new RuntimeException((string) $error);
        }

        $sheetRows = $xlsx->rows();
        if ($sheetRows === []) {
            throw new RuntimeException('Excel file is empty. Include a header row: name, email, password, role, firm, modules');
        }

        $headerRow = null;
        while ($sheetRows !== []) {
            $candidate = array_shift($sheetRows) ?: [];
            if (! $this->rowIsEmpty($candidate)) {
                $headerRow = $candidate;
                break;
            }
        }

        if ($headerRow === null) {
            throw new RuntimeException('Excel file is empty. Include a header row: name, email, password, role, firm, modules');
        }

        $header = array_map(
            fn ($h) => $this->normalizeHeader((string) $h),
            $headerRow
        );

        $rows = [];
        foreach ($sheetRows as $data) {
            if ($this->rowIsEmpty($data)) {
                continue;
            }

            $assoc = [];
            foreach ($header as $i => $key) {
                if ($key === null || $key === '') {
                    continue;
                }
                $assoc[$key] = isset($data[$i]) ? trim((string) $data[$i]) : '';
            }

            $rows[] = [
                'name' => $assoc['name'] ?? $assoc['full_name'] ?? $assoc['advisor_name'] ?? '',
                'email' => $assoc['email'] ?? $assoc['email_address'] ?? '',
                'password' => $assoc['password'] ?? $assoc['temporary_password'] ?? '',
                'role' => $assoc['role'] ?? $assoc['user_role'] ?? '',
                'firm' => $assoc['firm'] ?? $assoc['firm_name'] ?? $assoc['company'] ?? '',
                'modules' => $assoc['modules'] ?? $assoc['module'] ?? $assoc['allowed_modules'] ?? '',
            ];
        }

        if (! in_array('email', $header, true) && ! in_array('email_address', $header, true)) {
            if (count($rows) === 0) {
                throw new RuntimeException('Excel sheet must include an email column. Expected headers: name, email, password, role, firm, modules');
            }
        }

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = str_replace([' ', '-'], '_', $header);

        return $header;
    }

    /**
     * @param  list<null|string>  $data
     */
    private function rowIsEmpty(array $data): bool
    {
        foreach ($data as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function roleIsAdvisor(string $role): bool
    {
        return $role === User::ROLE_ADVISOR;
    }

    /**
     * @param  array{role?: ?string}  $row
     */
    private function previewRowIsAdvisor(Hub $hub, array $row): bool
    {
        $labelOrKey = (string) ($row['role'] ?? '');
        $resolved = $this->resolveRole($hub, $labelOrKey);

        return $resolved !== null && $this->roleIsAdvisor($resolved);
    }

    private function invalidRoleReason(Hub $hub, string $roleInput): string
    {
        $trimmed = trim($roleInput);
        if ($trimmed === '') {
            return 'Role is required. Use a role from the template dropdown.';
        }

        $normalizedKey = str_replace([' ', '-'], '_', Str::lower($trimmed));
        if (ActingHubService::isControlPlaneRole($normalizedKey)) {
            return 'Role "'.$trimmed.'" is not allowed on this hub. Power Admin and FinProms Admin cannot be imported.';
        }

        $allowed = collect($this->importableRoleOptions($hub))
            ->pluck('label')
            ->implode(', ');

        return 'Unknown role "'.$trimmed.'". Allowed roles: '.$allowed.'.';
    }
}
