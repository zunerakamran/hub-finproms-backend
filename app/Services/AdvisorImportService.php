<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AdvisorImportService
{
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
     *   pending: list<array{action: string, name: string, email: string, password: string, firm_id: ?int, firm: ?string}>,
     *   preview: array{created: list<array{name: string, email: string, firm: ?string}>, updated: list<array{name: string, email: string, firm: ?string}>, reactivated: list<array{name: string, email: string, firm: ?string}>},
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
                'No advisor rows were found. Make sure the first non-empty row contains headers like name, email, password, firm.'
            );
        }

        $pending = [];
        $previewCreated = [];
        $previewUpdated = [];
        $previewReactivated = [];
        $skipped = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $name = trim((string) ($row['name'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $password = trim((string) ($row['password'] ?? ''));
            $firmName = trim((string) ($row['firm'] ?? ''));

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

            $firmId = null;
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

            $firmId = (int) $firm->id;
            $resolvedFirmName = (string) $firm->name;

            $user = User::on($connection)->where('email', $email)->first();

            if ($user) {
                if ($user->isClientAdmin() || $user->isPowerAdmin()) {
                    $skipped[] = [
                        'row' => $rowNumber,
                        'email' => $email,
                        'reason' => 'Email belongs to an admin account.',
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
                    'firm_id' => $firmId,
                    'firm' => $resolvedFirmName,
                ];

                if ($action === 'reactivate') {
                    $previewReactivated[] = ['name' => $name, 'email' => $email, 'firm' => $resolvedFirmName];
                } else {
                    $previewUpdated[] = ['name' => $name, 'email' => $email, 'firm' => $resolvedFirmName];
                }

                continue;
            }

            $pending[] = [
                'action' => 'create',
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'firm_id' => $firmId,
                'firm' => $resolvedFirmName,
            ];
            $previewCreated[] = ['name' => $name, 'email' => $email, 'firm' => $resolvedFirmName];
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
                'billable_batch' => count($previewCreated) + count($previewReactivated),
            ],
        ];
    }

    /**
     * Apply a previously staged import plan (after payment method is chosen).
     *
     * @param  list<array{action: string, name: string, email: string, password?: string, firm_id?: ?int}>  $pending
     * @param  list<array{row: int, email: ?string, reason: string}>  $skipped
     * @return array{
     *   created: list<array{name: string, email: string, temporary_password: ?string, firm: ?string}>,
     *   updated: list<array{name: string, email: string, firm: ?string}>,
     *   reactivated: list<array{name: string, email: string, firm: ?string}>,
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
            $firmId = isset($row['firm_id']) && $row['firm_id'] !== null && $row['firm_id'] !== ''
                ? (int) $row['firm_id']
                : null;

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

            if ($firmId === null) {
                $skipped[] = [
                    'row' => $index + 1,
                    'email' => $email,
                    'reason' => 'Firm is required.',
                ];
                continue;
            }

            try {
                $result = DB::connection($connection)->transaction(function () use (
                    $action,
                    $name,
                    $email,
                    $password,
                    $firmId,
                    $hub,
                    $connection
                ) {
                    $user = User::on($connection)->where('email', $email)->first();
                    $temporaryPassword = null;

                    if ($user) {
                        if ($user->isClientAdmin() || $user->isPowerAdmin()) {
                            return [
                                'status' => 'skipped',
                                'reason' => 'Email belongs to an admin account.',
                            ];
                        }

                        $wasInactive = $user->isDiscontinued() || $user->isSuspended();

                        $user->fill([
                            'name' => $name,
                            'role' => User::ROLE_USER,
                            'is_advisor' => true,
                            'is_suspended' => false,
                            'is_discontinued' => false,
                            'discontinued_at' => null,
                            'firm_id' => $firmId,
                        ]);
                        $user->save();
                        $this->ensureAdvisorSubscription($user);
                        if ($wasInactive) {
                            $this->subscriberCredits->applyToAdvisor($user->fresh(), $hub, true);
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
                        'role' => User::ROLE_USER,
                        'credits' => 0,
                        'is_advisor' => true,
                        'has_unlimited_credits' => false,
                        'is_suspended' => false,
                        'is_discontinued' => false,
                        'firm_id' => $firmId,
                    ]);

                    $this->ensureAdvisorSubscription($user);
                    $this->subscriberCredits->applyToAdvisor($user->fresh(), $hub, true);

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

            if ($result['status'] === 'created') {
                $created[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'temporary_password' => $result['temporary_password'],
                    'firm' => $firmLabel,
                ];
                app(FunctionalMailService::class)->advisorInvite(
                    $result['user'],
                    $result['temporary_password'] ?? null
                );
            } elseif ($result['status'] === 'reactivated') {
                $reactivated[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'firm' => $firmLabel,
                ];
                app(FunctionalMailService::class)->advisorReactivated($result['user']);
            } else {
                $updated[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'firm' => $firmLabel,
                ];
            }
        }

        app(FunctionalMailService::class)->adminAdvisorImportSummary($created, $reactivated);

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
                'billable_batch' => count($created) + count($reactivated),
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
            $existing->ends_at = null;
            $existing->save();

            return $existing;
        }

        return UserSubscription::on($connection)->create([
            'user_id' => $user->id,
            'subscription_plan_id' => null,
            'credits_granted' => 0,
            'amount_paid' => 0,
            'status' => 'active',
            'payment_method' => 'advisor_import',
            'payment_status' => 'paid',
            'payment_reference' => 'ADV-'.Str::upper(Str::random(8)),
            'starts_at' => now(),
            'ends_at' => null,
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
     * @return list<array{name?: string, email?: string, password?: string}>
     */
    public function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Unable to read uploaded file.');
        }

        if (in_array($extension, ['csv', 'txt', ''], true)) {
            return $this->parseCsv($path);
        }

        if ($extension === 'xlsx') {
            return $this->parseXlsx($path);
        }

        if ($extension === 'xls') {
            throw new RuntimeException(
                'Legacy Excel (.xls) is not supported yet. Please save the sheet as .xlsx or CSV and upload that file.'
            );
        }

        throw new RuntimeException('Unsupported file type. Upload a CSV or Excel (.xlsx) file.');
    }

    /**
     * @return list<array{name?: string, email?: string, password?: string}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        $header = null;
        $rows = [];

        try {
            while (($data = fgetcsv($handle)) !== false) {
                if ($data === [null] || $data === false) {
                    continue;
                }

                if ($header === null && $this->rowIsEmpty($data)) {
                    continue;
                }

                // Strip UTF-8 BOM from first cell
                if ($header === null && isset($data[0])) {
                    $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $data[0]) ?? (string) $data[0];
                }

                if ($header === null) {
                    $header = array_map(fn ($h) => $this->normalizeHeader((string) $h), $data);
                    continue;
                }

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

                // Map common aliases
                $row = [
                    'name' => $assoc['name'] ?? $assoc['full_name'] ?? $assoc['advisor_name'] ?? '',
                    'email' => $assoc['email'] ?? $assoc['email_address'] ?? '',
                    'password' => $assoc['password'] ?? $assoc['temporary_password'] ?? '',
                    'firm' => $assoc['firm'] ?? $assoc['firm_name'] ?? $assoc['company'] ?? '',
                ];

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if ($header === null) {
            throw new RuntimeException('CSV file is empty. Include a header row: name,email');
        }

        if (! in_array('email', $header, true) && ! in_array('email_address', $header, true)) {
            // Allow headerless single-column? No — require email header.
            // If headers were wrong keys, still try positional fallback for 2+ columns.
            if (count($rows) === 0) {
                throw new RuntimeException('CSV must include an email column. Expected headers: name,email');
            }
        }

        return $rows;
    }

    /**
     * @return list<array{name?: string, email?: string, password?: string}>
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
            throw new RuntimeException('Excel file is empty. Include a header row: name, email, password');
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
            throw new RuntimeException('Excel file is empty. Include a header row: name, email, password');
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
                'firm' => $assoc['firm'] ?? $assoc['firm_name'] ?? $assoc['company'] ?? '',
            ];
        }

        if (! in_array('email', $header, true) && ! in_array('email_address', $header, true)) {
            if (count($rows) === 0) {
                throw new RuntimeException('Excel sheet must include an email column. Expected headers: name, email');
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

    public function templateCsv(): string
    {
        $lines = [
            'name,email,password,firm',
            'Jane Advisor,jane@example.com,,Acme Wealth',
            'John Advisor,john@example.com,OptionalPassword123,Acme Wealth',
        ];

        return implode("\n", $lines)."\n";
    }
}
