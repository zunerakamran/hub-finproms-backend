<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Hub → Users admin mutations (local + remote acting hub).
 */
class HubUserAdminService
{
    /** @var list<string> */
    public const LOGIN_ACTIONS = [
        'auth.login',
        'auth.login_failed',
        'auth.login_blocked',
        'auth.login_otp_sent',
        'auth.login_otp_verified',
        'auth.login_mfa_required',
        'auth.login_mfa_verified',
    ];

    /** @var list<string> */
    public const OTP_LOG_ACTIONS = [
        'auth.two_factor_otp_chosen',
        'auth.two_factor_otp_disabled',
        'hub_users.otp_enabled',
        'hub_users.otp_disabled',
    ];

    /** @var list<string> */
    public const MFA_LOG_ACTIONS = [
        'auth.mfa_setup_started',
        'auth.mfa_chosen',
        'auth.mfa_disabled',
        'hub_users.mfa_disabled',
        'hub_users.mfa_setup_started',
    ];

    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly WhiteLabelUserService $whiteLabelUsers,
        private readonly UserRolesService $userRoles,
        private readonly ActivityLogService $activityLogs,
        private readonly FunctionalMailService $functionalMail,
        private readonly AdvisorImportService $advisorImport,
    ) {}

    /**
     * Run $callback(User $user, ?Hub $hub, string $connection) against the local
     * or remotely-acted hub database. Remote work stays inside the open connection.
     *
     * @template T
     *
     * @param  callable(User, ?Hub, string): T  $callback
     * @return T
     */
    public function withTargetUser(Request $request, int $userId, callable $callback): mixed
    {
        $actor = $request->user();
        if (! $actor) {
            throw new InvalidArgumentException('Unauthenticated.');
        }

        if ($this->actingHubs->isActingRemotely($actor)) {
            $hub = $this->actingHubs->requireActingContentHub($actor);

            return $this->remoteDb->run($hub, function (string $connection) use ($hub, $userId, $callback) {
                $user = User::on($connection)->with(['firm:id,name', 'roleAssignments'])->find($userId);
                if (! $user) {
                    throw new InvalidArgumentException('User not found on this hub.');
                }

                return $callback($user, $hub, $connection);
            });
        }

        $hub = $this->actingHubs->targetHub($actor);
        $user = User::query()->with(['firm:id,name', 'roleAssignments'])->find($userId);
        if (! $user) {
            throw new InvalidArgumentException('User not found.');
        }

        return $callback($user, $hub, $user->getConnectionName());
    }

    public function assertMutable(User $user): void
    {
        if (ActingHubService::isControlPlaneRole((string) $user->role)
            || $user->hasRole(User::ROLE_POWER_ADMIN)
            || $user->hasRole(User::ROLE_FINPROMS_ADMIN)) {
            throw ValidationException::withMessages([
                'user' => 'Control-plane admin accounts cannot be managed from Hub → Users.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(User $user, ?Hub $hub): array
    {
        return $this->whiteLabelUsers->serialize($user, $hub);
    }

    /**
     * Suspend / discontinue / archive (same action).
     *
     * @return array<string, mixed>
     */
    public function suspend(Request $request, int $userId, ?string $remarks = null): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub) use ($request, $remarks) {
            $this->assertMutable($user);

            if ($remarks !== null && $remarks !== '') {
                $user->status_remarks = $remarks;
                $user->save();
            }

            $user = $this->advisorImport->discontinue($user);

            $this->logForTarget($user, $hub, $request, [
                'action' => 'hub_users.suspended',
                'description' => 'Suspended / discontinued user '.$user->email,
                'user' => $request->user(),
                'request' => $request,
                'subject' => $user,
                'hub' => $hub,
                'properties' => [
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'remarks' => $remarks,
                ],
            ]);

            return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
        });
    }

    /**
     * @return array{user: array<string, mixed>, temporary_password: string}
     */
    public function changePassword(Request $request, int $userId): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub) use ($request) {
            $this->assertMutable($user);

            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    'email' => 'Password can only be emailed to a verified email address. Verify the user’s email first.',
                ]);
            }

            $temporaryPassword = Str::password(12);
            $user->password = $temporaryPassword;
            $user->password_changed_at = now();
            $user->save();

            if (method_exists($user, 'tokens')) {
                try {
                    $user->tokens()->delete();
                } catch (\Throwable) {
                    //
                }
            }

            $this->functionalMail->passwordChangedByAdmin($user, $hub, $temporaryPassword);

            $this->logForTarget($user, $hub, $request, [
                'action' => 'hub_users.password_changed',
                'description' => 'Admin reset password for '.$user->email,
                'user' => $request->user(),
                'request' => $request,
                'subject' => $user,
                'hub' => $hub,
                'properties' => [
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'emailed' => true,
                ],
            ]);

            return [
                'user' => $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub),
                'temporary_password' => $temporaryPassword,
            ];
        });
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    public function updateRoles(Request $request, int $userId, array $roles): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub) use ($request, $roles) {
            $this->assertMutable($user);

            $roles = array_values(array_unique(array_filter(array_map('strval', $roles))));
            if ($roles === []) {
                throw ValidationException::withMessages(['roles' => 'Select at least one role.']);
            }

            foreach ($roles as $role) {
                if (! array_key_exists($role, User::ROLE_LABELS)) {
                    throw ValidationException::withMessages(['roles' => "Invalid role: {$role}"]);
                }
                if (ActingHubService::isControlPlaneRole($role)) {
                    throw ValidationException::withMessages(['roles' => 'Cannot assign control-plane roles here.']);
                }
            }

            $user->role = $this->userRoles->canonicalLegacyRole($roles);
            $user->is_advisor = in_array(User::ROLE_ADVISOR, $roles, true) || (bool) $user->is_advisor;
            if (! in_array(User::ROLE_ADMIN_STAFF, $roles, true)) {
                $user->acting_advisor_id = null;
            }
            $user->save();

            $this->userRoles->syncRoles(
                $user,
                $roles,
                log: true,
                actor: $request->user(),
                request: $request,
                hub: $hub,
            );

            return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
        });
    }

    /**
     * @param  list<string>|null  $modules  null = unrestricted
     * @return array<string, mixed>
     */
    public function updateModules(Request $request, int $userId, ?array $modules): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub) use ($request, $modules) {
            $this->assertMutable($user);

            if ($modules === null) {
                $user->modules = null;
            } else {
                $known = Hub::MODULE_KEYS;
                $clean = [];
                foreach ($modules as $key) {
                    $key = (string) $key;
                    if (! in_array($key, $known, true)) {
                        throw ValidationException::withMessages([
                            'modules' => "Unknown module: {$key}",
                        ]);
                    }
                    $clean[] = $key;
                }
                $user->modules = array_values(array_unique($clean));
            }
            $user->save();

            $this->logForTarget($user, $hub, $request, [
                'action' => 'hub_users.modules_updated',
                'description' => 'Updated modules for '.$user->email,
                'user' => $request->user(),
                'request' => $request,
                'subject' => $user,
                'hub' => $hub,
                'properties' => [
                    'target_user_id' => $user->id,
                    'modules' => $user->modules,
                ],
            ]);

            return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function changeEmail(Request $request, int $userId, string $email): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub, string $connection) use ($request, $email) {
            $this->assertMutable($user);

            $email = strtolower(trim($email));
            $exists = User::on($connection)->where('email', $email)->where('id', '!=', $user->id)->exists();
            if ($exists) {
                throw ValidationException::withMessages(['email' => 'That email is already in use on this hub.']);
            }

            $old = $user->email;
            $user->email = $email;
            $user->email_verified_at = null;
            $user->save();

            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable) {
                //
            }

            $this->logForTarget($user, $hub, $request, [
                'action' => 'hub_users.email_changed',
                'description' => "Changed registered email from {$old} to {$email}",
                'user' => $request->user(),
                'request' => $request,
                'subject' => $user,
                'hub' => $hub,
                'properties' => [
                    'target_user_id' => $user->id,
                    'old_email' => $old,
                    'new_email' => $email,
                ],
            ]);

            return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleOtp(Request $request, int $userId, bool $enabled): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub) use ($request, $enabled) {
            $this->assertMutable($user);

            if ($enabled && $user->hasMfaEnabled()) {
                $user->mfa_enabled = false;
                $user->mfa_secret = null;
                $user->mfa_confirmed_at = null;
                $this->logForTarget($user, $hub, $request, [
                    'action' => 'hub_users.mfa_disabled',
                    'description' => 'Cleared MS Authenticator for '.$user->email.' (OTP enabled by admin)',
                    'user' => $request->user(),
                    'request' => $request,
                    'subject' => $user,
                    'hub' => $hub,
                    'properties' => [
                        'target_user_id' => $user->id,
                        'enabled' => false,
                        'reason' => 'otp_enabled',
                        'admin_id' => $request->user()?->id,
                    ],
                ]);
            }

            $user->two_factor_enabled = $enabled;
            $user->save();

            $this->logForTarget($user, $hub, $request, [
                'action' => $enabled ? 'hub_users.otp_enabled' : 'hub_users.otp_disabled',
                'description' => ($enabled ? 'Enabled' : 'Disabled').' login OTP for '.$user->email,
                'user' => $request->user(),
                'request' => $request,
                'subject' => $user,
                'hub' => $hub,
                'properties' => [
                    'target_user_id' => $user->id,
                    'enabled' => $enabled,
                    'method' => 'otp',
                    'admin_id' => $request->user()?->id,
                ],
            ]);

            return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleAuthenticator(Request $request, int $userId, bool $enabled): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub) use ($request, $enabled) {
            $this->assertMutable($user);

            if (! $enabled) {
                $user->mfa_enabled = false;
                $user->mfa_secret = null;
                $user->mfa_confirmed_at = null;
                $user->save();

                $this->logForTarget($user, $hub, $request, [
                    'action' => 'hub_users.mfa_disabled',
                    'description' => 'Disabled MS Authenticator for '.$user->email,
                    'user' => $request->user(),
                    'request' => $request,
                    'subject' => $user,
                    'hub' => $hub,
                    'properties' => [
                        'target_user_id' => $user->id,
                        'enabled' => false,
                        'admin_id' => $request->user()?->id,
                    ],
                ]);

                return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
            }

            // Enabling MFA requires the user to complete TOTP setup — clear OTP and
            // leave a pending state so they finish on Update profile.
            $user->two_factor_enabled = false;
            $user->mfa_enabled = false;
            $user->mfa_secret = null;
            $user->mfa_confirmed_at = null;
            $user->save();

            $this->logForTarget($user, $hub, $request, [
                'action' => 'hub_users.mfa_setup_started',
                'description' => 'Admin requested MS Authenticator setup for '.$user->email,
                'user' => $request->user(),
                'request' => $request,
                'subject' => $user,
                'hub' => $hub,
                'properties' => [
                    'target_user_id' => $user->id,
                    'enabled' => true,
                    'pending_user_setup' => true,
                    'admin_id' => $request->user()?->id,
                ],
            ]);

            return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function updateTraining(Request $request, int $userId, bool $attended): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub) use ($request, $attended) {
            $this->assertMutable($user);

            $user->training_attended = $attended;
            $user->save();

            $this->logForTarget($user, $hub, $request, [
                'action' => 'hub_users.training_updated',
                'description' => 'Marked training '.($attended ? 'attended' : 'not attended').' for '.$user->email,
                'user' => $request->user(),
                'request' => $request,
                'subject' => $user,
                'hub' => $hub,
                'properties' => [
                    'target_user_id' => $user->id,
                    'training_attended' => $attended,
                ],
            ]);

            return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
        });
    }

    /**
     * @return array{logs: list<array<string, mixed>>, meta: array<string, int>, user: array<string, mixed>}
     */
    public function loginHistory(Request $request, int $userId, int $perPage = 50, int $page = 1): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub, string $connection) use ($perPage, $page) {
            $query = ActivityLog::on($connection)
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere(function ($inner) use ($user) {
                            $inner->whereNull('user_id')
                                ->where('user_email', $user->email);
                        })
                        ->orWhere(function ($inner) use ($user) {
                            $inner->where('subject_type', User::class)
                                ->where('subject_id', $user->id);
                        });
                })
                ->whereIn('action', self::LOGIN_ACTIONS)
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if ($hub) {
                $query->where(function ($q) use ($hub) {
                    $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
                });
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return [
                'logs' => $paginator->getCollection()->map(fn (ActivityLog $log) => $this->serializeLoginLog($log))->values()->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ];
        });
    }

    /**
     * @return array{otp: list<array<string, mixed>>, authenticator: list<array<string, mixed>>}
     */
    public function securityToggleLogs(Request $request, int $userId): array
    {
        return $this->withTargetUser($request, $userId, function (User $user, ?Hub $hub, string $connection) {
            $base = ActivityLog::on($connection)
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere(function ($inner) use ($user) {
                            $inner->where('subject_type', User::class)->where('subject_id', $user->id);
                        })
                        ->orWhere(function ($inner) use ($user) {
                            $inner->where('properties->target_user_id', $user->id);
                        });
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $otp = (clone $base)->whereIn('action', self::OTP_LOG_ACTIONS)->limit(50)->get()
                ->map(fn (ActivityLog $log) => $this->serializeToggleLog($log))
                ->values()
                ->all();

            $mfa = (clone $base)->whereIn('action', self::MFA_LOG_ACTIONS)->limit(50)->get()
                ->map(fn (ActivityLog $log) => $this->serializeToggleLog($log))
                ->values()
                ->all();

            return ['otp' => $otp, 'authenticator' => $mfa];
        });
    }

    /**
     * Write an activity row on the same DB connection as the target user
     * (so Hub → Users history works while Central is acting remotely).
     *
     * @param  array<string, mixed>  $data
     */
    private function logForTarget(User $target, ?Hub $hub, Request $request, array $data): void
    {
        $payload = array_merge($data, [
            'user' => $request->user(),
            'request' => $request,
            'subject' => $target,
            'hub' => $hub,
        ]);

        $connection = $target->getConnectionName();
        $default = (string) config('database.default');

        if ($connection === $default || $connection === null || $connection === '') {
            try {
                $this->activityLogs->log($payload);
            } catch (\Throwable) {
                //
            }

            return;
        }

        try {
            $actor = $request->user();
            ActivityLog::on($connection)->create([
                'hub_id' => $hub?->id,
                'user_id' => $actor?->id,
                'user_name' => $actor?->name,
                'user_email' => $actor?->email,
                'user_role' => $actor?->role,
                'action' => (string) $data['action'],
                'description' => $data['description'] ?? null,
                'subject_type' => User::class,
                'subject_id' => $target->id,
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route_name' => $request->route()?->getName(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status_code' => $data['status_code'] ?? 200,
                'properties' => $data['properties'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            //
        }

        try {
            $this->activityLogs->log($payload);
        } catch (\Throwable) {
            //
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLoginLog(ActivityLog $log): array
    {
        $ua = (string) ($log->user_agent ?? '');
        $parsed = $this->parseUserAgent($ua);
        $success = in_array($log->action, [
            'auth.login',
            'auth.login_otp_verified',
            'auth.login_mfa_verified',
        ], true);

        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'ip_address' => $log->ip_address,
            'user_agent' => $ua !== '' ? $ua : null,
            'device' => $parsed['device'],
            'os' => $parsed['os'],
            'browser' => $parsed['browser'],
            'success' => $success,
            'outcome' => $success ? 'success' : 'failure',
            'status_code' => $log->status_code,
            'created_at' => $log->created_at?->toIso8601String(),
            'properties' => $log->properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeToggleLog(ActivityLog $log): array
    {
        $props = is_array($log->properties) ? $log->properties : [];

        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'enabled' => array_key_exists('enabled', $props) ? (bool) $props['enabled'] : null,
            'admin_id' => $props['admin_id'] ?? null,
            'actor_email' => $log->user_email,
            'created_at' => $log->created_at?->toIso8601String(),
            'properties' => $props,
        ];
    }

    /**
     * @return array{device: string, os: string, browser: string}
     */
    private function parseUserAgent(string $ua): array
    {
        if ($ua === '') {
            return ['device' => 'Unknown', 'os' => 'Unknown', 'browser' => 'Unknown'];
        }

        $os = 'Unknown';
        if (preg_match('/Windows NT [\d.]+/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iOS/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = 'Unknown';
        if (preg_match('/Edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\//i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//i', $ua) && ! preg_match('/Chrome\//i', $ua)) {
            $browser = 'Safari';
        }

        $device = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'Mobile' : 'PC';

        return ['device' => $device, 'os' => $os, 'browser' => $browser];
    }
}
