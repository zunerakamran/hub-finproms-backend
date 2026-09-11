<?php

namespace App\Services;

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
     * @return array{
     *   created: list<array{name: string, email: string, temporary_password: string}>,
     *   updated: list<array{name: string, email: string}>,
     *   skipped: list<array{row: int, email: ?string, reason: string}>,
     *   summary: array{total_rows: int, created: int, updated: int, skipped: int}
     * }
     */
    public function import(UploadedFile $file): array
    {
        $rows = $this->parseFile($file);
        $hub = $this->hubs->current();

        if ($rows === []) {
            throw new RuntimeException(
                'No advisor rows were found. Make sure the first non-empty row contains headers like name, email, password.'
            );
        }

        $created = [];
        $updated = [];
        $reactivated = [];
        $skipped = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // header is row 1
            $name = trim((string) ($row['name'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $password = trim((string) ($row['password'] ?? ''));

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

            try {
                $result = DB::transaction(function () use ($name, $email, $password, $hub) {
                    $user = User::query()->where('email', $email)->first();
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
                        ]);
                        $user->save();
                        $this->ensureAdvisorSubscription($user);
                        // Fresh allotment only for new / reactivated; active advisors wait for autorenew.
                        if ($wasInactive) {
                            $this->subscriberCredits->applyToAdvisor($user->fresh(), $hub, true);
                        }

                        return [
                            'status' => $wasInactive ? 'reactivated' : 'updated',
                            'user' => $user->fresh(),
                        ];
                    }

                    if ($password === '') {
                        $temporaryPassword = Str::password(12);
                        $password = $temporaryPassword;
                    }

                    $user = User::query()->create([
                        'name' => $name,
                        'email' => $email,
                        'password' => $password,
                        'role' => User::ROLE_USER,
                        'credits' => 0,
                        'is_advisor' => true,
                        'has_unlimited_credits' => false,
                        'is_suspended' => false,
                        'is_discontinued' => false,
                    ]);

                    $this->ensureAdvisorSubscription($user);
                    $this->subscriberCredits->applyToAdvisor($user->fresh(), $hub, true);

                    return [
                        'status' => 'created',
                        'user' => $user->fresh(),
                        'temporary_password' => $temporaryPassword,
                    ];
                });
            } catch (\Throwable $e) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'email' => $email,
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            if (($result['status'] ?? null) === 'skipped') {
                $skipped[] = [
                    'row' => $rowNumber,
                    'email' => $email,
                    'reason' => $result['reason'] ?? 'Skipped.',
                ];
                continue;
            }

            if ($result['status'] === 'created') {
                $created[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'temporary_password' => $result['temporary_password'],
                ];
            } elseif ($result['status'] === 'reactivated') {
                $reactivated[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                ];
            } else {
                $updated[] = [
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                ];
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'reactivated' => $reactivated,
            'skipped' => $skipped,
            'summary' => [
                'total_rows' => count($rows),
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
        $existing = $user->subscriptions()
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

        return UserSubscription::query()->create([
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

        return DB::transaction(function () use ($advisor) {
            $advisor->is_discontinued = true;
            $advisor->discontinued_at = now();
            $advisor->has_unlimited_credits = false;
            $advisor->save();
            $advisor->tokens()->delete();

            UserSubscription::query()
                ->where('user_id', $advisor->id)
                ->where('payment_method', 'advisor_import')
                ->whereIn('status', ['active', 'suspended'])
                ->update([
                    'status' => 'discontinued',
                    'ends_at' => now(),
                ]);

            return $advisor->fresh();
        });
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
            'name,email,password',
            'Jane Advisor,jane@example.com,',
            'John Advisor,john@example.com,OptionalPassword123',
        ];

        return implode("\n", $lines)."\n";
    }
}
