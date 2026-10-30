<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AdminNewUserRegistrationMailService;
use App\Services\EmailVerificationService;
use App\Services\FunctionalMailService;
use App\Services\HubService;
use App\Services\LoginMfaChallengeService;
use App\Services\LoginOtpService;
use App\Services\PowerAdminCapabilitiesService;
use App\Services\SecurityMonitoringService;
use App\Services\TotpService;
use App\Services\WelcomeMailService;
use App\Support\InputSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly PowerAdminCapabilitiesService $powerCapabilities,
        private readonly ActivityLogService $activityLogs,
        private readonly SecurityMonitoringService $securityMonitoring,
        private readonly WelcomeMailService $welcomeMail,
        private readonly AdminNewUserRegistrationMailService $adminNewUserMail,
        private readonly FunctionalMailService $functionalMail,
        private readonly EmailVerificationService $emailVerification,
        private readonly LoginOtpService $loginOtp,
        private readonly LoginMfaChallengeService $loginMfa,
        private readonly TotpService $totp,
    ) {}

    public function register(Request $request): JsonResponse
    {
        if ($this->hubs->can('private_invite_only') || ! $this->hubs->can('public_subscribe')) {
            return response()->json([
                'message' => 'Public registration is disabled for this hub. Access is invite-only.',
                'registration_enabled' => false,
                'invite_only' => true,
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $validated['name'] = InputSanitizer::plain($validated['name']);
        $validated['email'] = strtolower(InputSanitizer::plain($validated['email']));

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => User::ROLE_USER,
            'credits' => 0,
            // email_verified_at stays null until the member confirms via email link.
        ]);

        $this->activityLogs->log([
            'action' => 'auth.register',
            'description' => 'User registered (pending email verification): '.$user->email,
            'user' => $user,
            'request' => $request,
            'subject' => $user,
            'status_code' => 201,
        ]);

        $verificationToken = $this->emailVerification->createToken($user);
        try {
            $this->functionalMail->sendEmailVerificationLink($user, $verificationToken);
        } catch (\Throwable $e) {
            report($e);
        }
        $this->adminNewUserMail->send($user);

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account before signing in.',
            'email_verification_required' => true,
            'email' => $user->email,
        ], 201);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
        ]);

        $user = $this->emailVerification->consume($validated['email'], $validated['token']);

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['This verification link is invalid or has expired. Request a new one and try again.'],
            ]);
        }

        $this->activityLogs->log([
            'action' => 'auth.email_verified',
            'description' => 'Email verified: '.$user->email,
            'user' => $user,
            'request' => $request,
            'subject' => $user,
            'status_code' => 200,
        ]);

        // Welcome mail is sent only after the address is confirmed.
        $this->welcomeMail->send($user);

        return $this->issueLoginToken($user, $request, 'Email verified successfully. You are now signed in.');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        // Always return the same message to avoid account enumeration.
        $message = 'If that email needs verification, a new link has been sent.';

        if ($user && ! $user->hasVerifiedEmail()) {
            if (! $this->emailVerification->canResend($user->email)) {
                return response()->json([
                    'message' => 'Please wait a minute before requesting another verification email.',
                    'email_verification_required' => true,
                    'email' => $user->email,
                ], 429);
            }

            $token = $this->emailVerification->createToken($user);
            $this->functionalMail->sendEmailVerificationLink($user, $token);
        }

        return response()->json([
            'message' => $message,
            'email_verification_required' => true,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        // Always return the same message to avoid account enumeration.
        if ($user) {
            $token = PasswordBroker::broker()->createToken($user);
            $this->functionalMail->sendPasswordResetLink($user, $token);
        }

        return response()->json([
            'message' => 'If that email is registered, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $status = PasswordBroker::broker()->reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                ])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Password has been reset successfully. You can sign in now.',
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            try {
                $this->activityLogs->log([
                    'action' => 'auth.login_failed',
                    'description' => 'Failed login attempt for '.$credentials['email'],
                    'request' => $request,
                    'status_code' => 422,
                    'properties' => ['email' => $credentials['email']],
                ]);
            } catch (\Throwable) {
                // Never block login on audit logging.
            }

            try {
                $this->securityMonitoring->recordFailedLogin($this->hubs->current(), $credentials['email'], $request);
            } catch (\Throwable) {
                //
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->isSuspended()) {
            try {
                $this->activityLogs->log([
                    'action' => 'auth.login_blocked',
                    'description' => 'Suspended user blocked from login: '.$user->email,
                    'user' => $user,
                    'request' => $request,
                    'status_code' => 403,
                    'properties' => ['reason' => 'suspended'],
                ]);
            } catch (\Throwable) {
                //
            }

            try {
                $this->securityMonitoring->recordBlockedLogin($this->hubs->current(), $user, 'suspended', $request);
            } catch (\Throwable) {
                //
            }

            return response()->json([
                'message' => 'This account is suspended. Contact your hub administrator.',
            ], 403);
        }

        if ($user->isDiscontinued()) {
            try {
                $this->activityLogs->log([
                    'action' => 'auth.login_blocked',
                    'description' => 'Discontinued advisor blocked from login: '.$user->email,
                    'user' => $user,
                    'request' => $request,
                    'status_code' => 403,
                    'properties' => ['reason' => 'discontinued'],
                ]);
            } catch (\Throwable) {
                //
            }

            try {
                $this->securityMonitoring->recordBlockedLogin($this->hubs->current(), $user, 'discontinued', $request);
            } catch (\Throwable) {
                //
            }

            return response()->json([
                'message' => 'This advisor account has been discontinued. Contact your hub administrator.',
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            try {
                $this->activityLogs->log([
                    'action' => 'auth.login_blocked',
                    'description' => 'Unverified email blocked from login: '.$user->email,
                    'user' => $user,
                    'request' => $request,
                    'status_code' => 403,
                    'properties' => ['reason' => 'email_unverified'],
                ]);
            } catch (\Throwable) {
                //
            }

            try {
                $this->securityMonitoring->recordBlockedLogin($this->hubs->current(), $user, 'email_unverified', $request);
            } catch (\Throwable) {
                //
            }

            return response()->json([
                'message' => 'Please verify your email before signing in. Check your inbox for the verification link.',
                'email_verification_required' => true,
                'email' => $user->email,
            ], 403);
        }

        if ($this->hubs->can('private_invite_only') && ! $user->mayLoginOnInviteOnlyHub()) {
            try {
                $this->activityLogs->log([
                    'action' => 'auth.login_blocked',
                    'description' => 'Invite-only hub blocked login: '.$user->email,
                    'user' => $user,
                    'request' => $request,
                    'status_code' => 403,
                    'properties' => ['reason' => 'invite_only'],
                ]);
            } catch (\Throwable) {
                //
            }

            try {
                $this->securityMonitoring->recordBlockedLogin($this->hubs->current(), $user, 'invite_only', $request);
            } catch (\Throwable) {
                //
            }

            return response()->json([
                'message' => 'This hub is invite-only. Only users imported from the invite list can sign in.',
                'registration_enabled' => false,
                'invite_only' => true,
            ], 403);
        }

        // MFA (Microsoft Authenticator / TOTP) takes precedence over email OTP.
        if ($user->hasMfaEnabled()) {
            $challenge = $this->loginMfa->create($user);

            try {
                $this->activityLogs->log([
                    'action' => 'auth.login_mfa_required',
                    'description' => 'Login MFA challenge issued: '.$user->email,
                    'user' => $user,
                    'request' => $request,
                    'subject' => $user,
                    'status_code' => 200,
                    'properties' => ['method' => 'mfa'],
                ]);
            } catch (\Throwable) {
                //
            }

            return response()->json([
                'message' => 'Enter the code from your authenticator app to finish signing in.',
                'mfa_required' => true,
                'mfa_challenge' => $challenge['challenge'],
                'email' => $user->email,
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            $otp = $this->loginOtp->create($user);
            try {
                $this->functionalMail->sendLoginOtp($user, $otp['code']);
            } catch (\Throwable $e) {
                report($e);
            }

            try {
                $this->activityLogs->log([
                    'action' => 'auth.login_otp_sent',
                    'description' => 'Login OTP sent: '.$user->email,
                    'user' => $user,
                    'request' => $request,
                    'subject' => $user,
                    'status_code' => 200,
                    'properties' => ['method' => 'otp'],
                ]);
            } catch (\Throwable) {
                //
            }

            return response()->json([
                'message' => 'A one-time passcode has been sent to your email. Enter it to finish signing in.',
                'otp_required' => true,
                'otp_challenge' => $otp['challenge'],
                'email' => $user->email,
            ]);
        }

        return $this->issueLoginToken($user, $request);
    }

    public function verifyLoginMfa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $this->loginMfa->consume(
            $validated['email'],
            $validated['challenge'],
            $validated['code'],
            $this->totp
        );

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired authenticator code. Try again.'],
            ]);
        }

        if ($user->isSuspended() || $user->isDiscontinued() || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This account cannot sign in right now. Contact your hub administrator.',
            ], 403);
        }

        if ($this->hubs->can('private_invite_only') && ! $user->mayLoginOnInviteOnlyHub()) {
            return response()->json([
                'message' => 'This hub is invite-only. Only users imported from the invite list can sign in.',
                'registration_enabled' => false,
                'invite_only' => true,
            ], 403);
        }

        try {
            $this->activityLogs->log([
                'action' => 'auth.login_mfa_verified',
                'description' => 'Login MFA verified: '.$user->email,
                'user' => $user,
                'request' => $request,
                'subject' => $user,
                'status_code' => 200,
                'properties' => ['method' => 'mfa'],
            ]);
        } catch (\Throwable) {
            //
        }

        return $this->issueLoginToken($user, $request);
    }

    public function verifyLoginOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $this->loginOtp->consume(
            $validated['email'],
            $validated['challenge'],
            $validated['code']
        );

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired code. Request a new one and try again.'],
            ]);
        }

        if ($user->isSuspended() || $user->isDiscontinued() || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This account cannot sign in right now. Contact your hub administrator.',
            ], 403);
        }

        if ($this->hubs->can('private_invite_only') && ! $user->mayLoginOnInviteOnlyHub()) {
            return response()->json([
                'message' => 'This hub is invite-only. Only users imported from the invite list can sign in.',
                'registration_enabled' => false,
                'invite_only' => true,
            ], 403);
        }

        try {
            $this->activityLogs->log([
                'action' => 'auth.login_otp_verified',
                'description' => 'Login OTP verified: '.$user->email,
                'user' => $user,
                'request' => $request,
                'subject' => $user,
                'status_code' => 200,
                'properties' => ['method' => 'otp'],
            ]);
        } catch (\Throwable) {
            //
        }

        return $this->issueLoginToken($user, $request);
    }

    public function resendLoginOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'challenge' => ['required', 'string'],
        ]);

        $message = 'If that sign-in is still pending, a new code has been sent.';

        if (! $this->loginOtp->challengeMatches($validated['email'], $validated['challenge'])) {
            return response()->json([
                'message' => $message,
                'otp_required' => true,
            ]);
        }

        $user = User::query()->where('email', $validated['email'])->first();
        if (! $user) {
            return response()->json([
                'message' => $message,
                'otp_required' => true,
            ]);
        }

        if (! $this->loginOtp->canResend($user->email)) {
            return response()->json([
                'message' => 'Please wait a minute before requesting another code.',
                'otp_required' => true,
                'email' => $user->email,
                'otp_challenge' => $validated['challenge'],
            ], 429);
        }

        $otp = $this->loginOtp->create($user);
        try {
            $this->functionalMail->sendLoginOtp($user, $otp['code']);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'A new one-time passcode has been sent to your email.',
            'otp_required' => true,
            'email' => $user->email,
            'otp_challenge' => $otp['challenge'],
        ]);
    }

    private function issueLoginToken(User $user, Request $request, ?string $message = null): JsonResponse
    {
        // httpOnly session cookie (primary for first-party SPA).
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // Personal access token kept for Active Sessions / force-logout + legacy clients.
        // Expires with the hub’s configured idle timeout (aligned with SPA session cookie).
        try {
            $lifetimeMinutes = $this->hubs->current()->resolvedSessionLifetimeMinutes();
            $token = $user->createToken(
                'auth_token',
                ['*'],
                now()->addMinutes($lifetimeMinutes)
            )->plainTextToken;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Login matched, but the server could not create a session token. Check storage permissions and run: php artisan migrate --force',
            ], 500);
        }

        try {
            $this->activityLogs->log([
                'action' => 'auth.login',
                'description' => 'User logged in: '.$user->email,
                'user' => $user,
                'request' => $request,
                'subject' => $user,
                'status_code' => 200,
            ]);
        } catch (\Throwable) {
            //
        }

        return response()->json([
            'message' => $message ?: 'Login successful.',
            'user' => $this->decorateUserForAuthResponse($user),
            // Returned for optional Bearer fallback; SPA should rely on cookies.
            'token' => $token,
            'auth_mode' => 'cookie',
        ]);
    }

    private function decorateUserForAuthResponse(User $user): User
    {
        $hub = $this->hubs->current();
        $user->setAttribute('terms_accepted', $user->hasAcceptedTerms($hub));
        $user->setAttribute('terms_required_version', $hub->termsVersion());
        $user->setAttribute('privacy_accepted', $user->hasAcceptedPrivacy($hub));
        $user->setAttribute('privacy_required_version', $hub->privacyVersion());
        $user->setAttribute('mfa_pending', $user->hasMfaPending());

        $user->loadMissing([
            'firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
            'firm.complianceAuthorityFirms:id,name,is_central',
            'roleAssignments',
        ]);
        if ($user->firm) {
            $api = $user->firm->toApiArray();
            $user->firm->setAttribute('compliance_visibility', $api['compliance_visibility']);
        }

        return $user;
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->activityLogs->log([
            'action' => 'auth.logout',
            'description' => 'User logged out: '.($user->email ?? $user->name),
            'user' => $user,
            'request' => $request,
            'subject' => $user,
            'status_code' => 200,
        ]);

        $accessToken = $user->currentAccessToken();
        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function acceptTerms(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->hubs->current();

        $user->acceptTerms($hub);

        try {
            $this->activityLogs->log([
                'action' => 'auth.terms_accepted',
                'description' => 'Terms & Conditions accepted (v'.$hub->termsVersion().'): '.$user->email,
                'user' => $user,
                'request' => $request,
                'subject' => $user,
                'status_code' => 200,
                'properties' => ['terms_version' => $hub->termsVersion()],
            ]);
        } catch (\Throwable) {
            //
        }

        $user = $user->fresh();

        return response()->json([
            'message' => 'Terms & Conditions accepted.',
            'user' => $this->decorateUserForAuthResponse($user),
        ]);
    }

    public function acceptPrivacy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->hubs->current();

        $user->acceptPrivacy($hub);

        try {
            $this->activityLogs->log([
                'action' => 'auth.privacy_accepted',
                'description' => 'Privacy Policy acknowledged (v'.$hub->privacyVersion().'): '.$user->email,
                'user' => $user,
                'request' => $request,
                'subject' => $user,
                'status_code' => 200,
                'properties' => ['privacy_version' => $hub->privacyVersion()],
            ]);
        } catch (\Throwable) {
            //
        }

        $user = $user->fresh();

        return response()->json([
            'message' => 'Privacy Policy acknowledged.',
            'user' => $this->decorateUserForAuthResponse($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->decorateUserForAuthResponse($request->user());

        $actingAdvisors = app(\App\Services\ActingAdvisorService::class);
        $subject = $actingAdvisors->billingSubject($user);
        $hub = app(\App\Services\HubService::class)->current();
        $hubUnlimited = $hub->can('unlimited_credits');
        $isActing = (int) $subject->id !== (int) $user->id;

        // When Admin-staff act as an advisor, expose that advisor's credits / unlimited
        // on the auth user payload so the website header and catalog see full access.
        if ($isActing) {
            $user->setAttribute('credits', (int) $subject->credits);
            $user->setAttribute(
                'has_unlimited_credits',
                $subject->hasUnlimitedCredits($hubUnlimited)
            );
            $user->setAttribute('billing_subject_id', (int) $subject->id);
            $user->setAttribute('billing_subject_name', $subject->name);
        }

        $payload = [
            'user' => $user,
            'visible_metrics' => $subject->contentMetricVisibility(),
            'active_plan' => $subject->activePlan(),
            'billing_subject' => [
                'id' => (int) $subject->id,
                'name' => $subject->name,
                'email' => $subject->email,
                'is_acting' => $isActing,
                'credits' => (int) $subject->credits,
                'has_unlimited_credits' => $subject->hasUnlimitedCredits($hubUnlimited),
            ],
        ];

        try {
            $firmDocRights = app(\App\Services\FirmDocumentAccessService::class)
                ->effectiveRightsSummary($user, $hub);
            $payload['firm_document_rights'] = $firmDocRights;
            $user->setAttribute('is_firm_head', (bool) ($firmDocRights['is_firm_head'] ?? false));
            $user->setAttribute(
                'firm_document_firm_id',
                isset($firmDocRights['firm_id']) ? (int) $firmDocRights['firm_id'] : null
            );
            $payload['user'] = $user;
        } catch (\Throwable) {
            //
        }

        if ($user->isPowerAdmin()) {
            $payload['power_admin_capabilities'] = $this->powerCapabilities->resolved();
        }

        $switcher = null;
        try {
            $switcher = app(\App\Services\ActingHubService::class)->switcherPayload($user);
        } catch (\Throwable) {
            $switcher = null;
        }
        if ($switcher !== null) {
            $payload['hub_switcher'] = $switcher;
        }

        try {
            $advisorSwitcher = $actingAdvisors->switcherPayload($user);
        } catch (\Throwable) {
            $advisorSwitcher = null;
        }
        if ($advisorSwitcher !== null) {
            $payload['acting_advisor_switcher'] = $advisorSwitcher;
        }

        return response()->json($payload);
    }

    /**
     * Self-serve profile update: name, email, avatar, email OTP 2FA, and MFA (MS Authenticator).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'two_factor_enabled' => ['sometimes', 'boolean'],
            'mfa_enabled' => ['sometimes', 'boolean'],
            'avatar' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'current_password' => ['nullable', 'string'],
        ]);

        $emailChanging = array_key_exists('email', $validated)
            && strcasecmp((string) $validated['email'], (string) $user->email) !== 0;
        $twoFactorProvided = array_key_exists('two_factor_enabled', $validated);
        // Use Request::boolean so FormData "0"/"1" strings are parsed correctly.
        $twoFactorEnabled = $twoFactorProvided ? $request->boolean('two_factor_enabled') : (bool) $user->two_factor_enabled;
        $twoFactorChanging = $twoFactorProvided
            && $twoFactorEnabled !== (bool) $user->two_factor_enabled;

        $mfaProvided = array_key_exists('mfa_enabled', $validated);
        $mfaWanted = $mfaProvided ? $request->boolean('mfa_enabled') : $user->hasMfaEnabled();
        $mfaCurrentlyOn = $user->hasMfaEnabled();
        $mfaCurrentlyPending = $user->hasMfaPending();
        // Turning MFA "on" while already enrolled is a no-op; turning on while off/pending starts/resumes setup.
        // Turning MFA "off" clears enrollment or abandons pending setup.
        $mfaTurningOn = $mfaProvided && $mfaWanted && ! $mfaCurrentlyOn;
        $mfaTurningOff = $mfaProvided && ! $mfaWanted && ($mfaCurrentlyOn || $mfaCurrentlyPending);

        if ($emailChanging || $twoFactorChanging || $mfaTurningOn || $mfaTurningOff) {
            $currentPassword = (string) ($validated['current_password'] ?? '');
            if ($currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Your current password is required to change email, OTP, or MFA settings.'],
                ]);
            }
        }

        if ($twoFactorProvided && $mfaProvided && $twoFactorEnabled && $mfaWanted) {
            throw ValidationException::withMessages([
                'mfa_enabled' => ['Choose either email OTP or authenticator MFA, not both.'],
            ]);
        }

        $changes = [];
        $mfaSetup = null;
        $authMethodLogs = [];

        if (array_key_exists('name', $validated)) {
            $validated['name'] = InputSanitizer::plain($validated['name']);
            if ($validated['name'] !== $user->name) {
                $user->name = $validated['name'];
                $changes[] = 'name';
            }
        }

        if ($emailChanging) {
            $user->email = strtolower(InputSanitizer::plain($validated['email']));
            $user->email_verified_at = null;
            $changes[] = 'email';
        }

        if ($twoFactorChanging) {
            $user->two_factor_enabled = $twoFactorEnabled;
            $changes[] = $user->two_factor_enabled ? 'two_factor_otp_enabled' : 'two_factor_otp_disabled';
            $authMethodLogs[] = [
                'action' => $user->two_factor_enabled ? 'auth.two_factor_otp_chosen' : 'auth.two_factor_otp_disabled',
                'description' => ($user->two_factor_enabled
                    ? 'User chose email OTP two-factor authentication: '
                    : 'User disabled email OTP two-factor authentication: ').$user->email,
                'properties' => [
                    'method' => 'otp',
                    'enabled' => (bool) $user->two_factor_enabled,
                ],
            ];

            // Email OTP and authenticator MFA are mutually exclusive.
            if ($user->two_factor_enabled && ($user->hasMfaEnabled() || $user->hasMfaPending())) {
                $user->mfa_enabled = false;
                $user->mfa_secret = null;
                $user->mfa_confirmed_at = null;
                $changes[] = 'mfa_cleared_for_otp';
                $authMethodLogs[] = [
                    'action' => 'auth.mfa_disabled',
                    'description' => 'Authenticator MFA cleared because email OTP was chosen: '.$user->email,
                    'properties' => [
                        'method' => 'mfa',
                        'enabled' => false,
                        'reason' => 'switched_to_otp',
                    ],
                ];
            }
        }

        if ($mfaTurningOff) {
            $user->mfa_enabled = false;
            $user->mfa_secret = null;
            $user->mfa_confirmed_at = null;
            $changes[] = 'mfa_disabled';
            $authMethodLogs[] = [
                'action' => 'auth.mfa_disabled',
                'description' => 'User disabled authenticator MFA: '.$user->email,
                'properties' => [
                    'method' => 'mfa',
                    'enabled' => false,
                ],
            ];
        }

        if ($mfaTurningOn) {
            $resumingPending = $user->hasMfaPending();
            if (! $resumingPending) {
                $user->mfa_secret = $this->totp->generateSecret();
                $user->mfa_enabled = false;
                $user->mfa_confirmed_at = null;
                $changes[] = 'mfa_setup_started';

                if ($user->two_factor_enabled) {
                    $user->two_factor_enabled = false;
                    $changes[] = 'two_factor_otp_cleared_for_mfa';
                    $authMethodLogs[] = [
                        'action' => 'auth.two_factor_otp_disabled',
                        'description' => 'Email OTP cleared because authenticator MFA setup started: '.$user->email,
                        'properties' => [
                            'method' => 'otp',
                            'enabled' => false,
                            'reason' => 'switched_to_mfa',
                        ],
                    ];
                }

                $authMethodLogs[] = [
                    'action' => 'auth.mfa_setup_started',
                    'description' => 'User started authenticator MFA setup (Microsoft Authenticator / TOTP): '.$user->email,
                    'properties' => [
                        'method' => 'mfa',
                        'enabled' => false,
                        'pending' => true,
                    ],
                ];
            }

            $secret = (string) $user->mfa_secret;
            $issuer = $this->mfaIssuerName();
            $mfaSetup = [
                'secret' => $secret,
                'otpauth_uri' => $this->totp->otpauthUri($secret, (string) $user->email, $issuer),
                'issuer' => $issuer,
                'account' => (string) $user->email,
            ];
        }

        $removeAvatar = $request->boolean('remove_avatar');
        if ($removeAvatar && $user->avatar_path) {
            $this->deleteAvatarFile($user->avatar_path);
            $user->avatar_path = null;
            $changes[] = 'avatar_removed';
        }

        if ($request->hasFile('avatar')) {
            /** @var UploadedFile $file */
            $file = $request->file('avatar');
            $oldPath = $user->avatar_path;
            $path = $file->store('avatars/'.$user->id, 'public');
            $user->avatar_path = $path;
            if ($oldPath && $oldPath !== $path) {
                $this->deleteAvatarFile($oldPath);
            }
            $changes[] = 'avatar';
        }

        if ($changes !== []) {
            $user->save();
        }

        if ($emailChanging) {
            try {
                $token = $this->emailVerification->createToken($user);
                $this->functionalMail->sendEmailVerificationLink($user, $token);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            $this->activityLogs->log([
                'action' => 'auth.profile_updated',
                'description' => 'Profile updated: '.$user->email.($changes !== [] ? ' ('.implode(', ', $changes).')' : ''),
                'user' => $user,
                'request' => $request,
                'subject' => $user,
                'status_code' => 200,
                'properties' => ['changes' => $changes],
            ]);
        } catch (\Throwable) {
            //
        }

        foreach ($authMethodLogs as $methodLog) {
            try {
                $this->activityLogs->log([
                    'action' => $methodLog['action'],
                    'description' => $methodLog['description'],
                    'user' => $user,
                    'request' => $request,
                    'subject' => $user,
                    'status_code' => 200,
                    'properties' => $methodLog['properties'],
                ]);
            } catch (\Throwable) {
                //
            }
        }

        $user = $user->fresh();

        $message = 'Profile updated successfully.';
        if ($emailChanging) {
            $message = 'Profile updated. Please verify your new email address — a confirmation link has been sent.';
        } elseif ($mfaTurningOn) {
            $message = 'Scan the QR code (or enter the secret) in Microsoft Authenticator, then confirm with a code.';
        } elseif ($mfaTurningOff) {
            $message = 'Authenticator MFA has been disabled.';
        }

        $payload = [
            'message' => $message,
            'user' => $this->decorateUserForAuthResponse($user),
            'email_verification_required' => $emailChanging,
        ];

        if ($mfaSetup !== null) {
            $payload['mfa_setup'] = $mfaSetup;
            $payload['mfa_confirmation_required'] = true;
        }

        return response()->json($payload);
    }

    /**
     * Confirm pending MFA enrollment with a code from Microsoft Authenticator (or any TOTP app).
     */
    public function confirmMfa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is required to enable MFA.'],
            ]);
        }

        if (! $user->hasMfaPending()) {
            throw ValidationException::withMessages([
                'code' => ['No MFA setup is in progress. Enable MFA from Update profile first.'],
            ]);
        }

        if (! $this->totp->verify((string) $user->mfa_secret, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['Invalid authenticator code. Check the app and try again.'],
            ]);
        }

        $user->mfa_enabled = true;
        $user->mfa_confirmed_at = now();
        $user->two_factor_enabled = false;
        $user->save();

        try {
            $this->activityLogs->log([
                'action' => 'auth.mfa_chosen',
                'description' => 'User chose authenticator MFA (Microsoft Authenticator / TOTP): '.$user->email,
                'user' => $user,
                'request' => $request,
                'subject' => $user,
                'status_code' => 200,
                'properties' => [
                    'method' => 'mfa',
                    'enabled' => true,
                ],
            ]);
        } catch (\Throwable) {
            //
        }

        return response()->json([
            'message' => 'Authenticator MFA is now enabled. You will be asked for an app code at sign-in.',
            'user' => $this->decorateUserForAuthResponse($user->fresh()),
        ]);
    }

    /**
     * Re-issue MFA setup payload when enrollment is still pending (e.g. user closed the QR dialog).
     */
    public function mfaSetup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is required to view MFA setup.'],
            ]);
        }

        if ($user->hasMfaEnabled()) {
            return response()->json([
                'message' => 'Authenticator MFA is already enabled.',
                'user' => $this->decorateUserForAuthResponse($user),
            ], 422);
        }

        $regenerate = $request->boolean('regenerate') || ! $user->hasMfaPending();
        if ($regenerate) {
            $user->mfa_secret = $this->totp->generateSecret();
            $user->mfa_enabled = false;
            $user->mfa_confirmed_at = null;
            if ($user->two_factor_enabled) {
                $user->two_factor_enabled = false;
            }
            $user->save();

            try {
                $this->activityLogs->log([
                    'action' => 'auth.mfa_setup_started',
                    'description' => 'User started/regenerated authenticator MFA setup: '.$user->email,
                    'user' => $user,
                    'request' => $request,
                    'subject' => $user,
                    'status_code' => 200,
                    'properties' => [
                        'method' => 'mfa',
                        'enabled' => false,
                        'pending' => true,
                        'regenerated' => true,
                    ],
                ]);
            } catch (\Throwable) {
                //
            }
        }

        $secret = (string) $user->mfa_secret;
        $issuer = $this->mfaIssuerName();

        return response()->json([
            'message' => 'Scan the QR code (or enter the secret) in Microsoft Authenticator, then confirm with a code.',
            'mfa_confirmation_required' => true,
            'mfa_setup' => [
                'secret' => $secret,
                'otpauth_uri' => $this->totp->otpauthUri($secret, (string) $user->email, $issuer),
                'issuer' => $issuer,
                'account' => (string) $user->email,
            ],
            'user' => $this->decorateUserForAuthResponse($user->fresh()),
        ]);
    }

    private function mfaIssuerName(): string
    {
        try {
            $name = trim((string) $this->hubs->current()->name);
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable) {
            //
        }

        return (string) config('app.name', 'Hub');
    }

    private function deleteAvatarFile(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        try {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        } catch (\Throwable) {
            //
        }
    }
}
