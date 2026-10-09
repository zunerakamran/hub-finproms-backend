<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AdminNewUserRegistrationMailService;
use App\Services\EmailVerificationService;
use App\Services\FunctionalMailService;
use App\Services\HubService;
use App\Services\LoginOtpService;
use App\Services\PowerAdminCapabilitiesService;
use App\Services\WelcomeMailService;
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
        private readonly WelcomeMailService $welcomeMail,
        private readonly AdminNewUserRegistrationMailService $adminNewUserMail,
        private readonly FunctionalMailService $functionalMail,
        private readonly EmailVerificationService $emailVerification,
        private readonly LoginOtpService $loginOtp
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

            return response()->json([
                'message' => 'This hub is invite-only. Only users imported from the invite list can sign in.',
                'registration_enabled' => false,
                'invite_only' => true,
            ], 403);
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
        try {
            $token = $user->createToken('auth_token')->plainTextToken;
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

        $user->loadMissing([
            'firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
        ]);
        if ($user->firm) {
            $user->firm->setAttribute('compliance_visibility', [
                'visible_to_own' => (bool) $user->firm->compliance_visible_to_own,
                'visible_to_central' => (bool) $user->firm->compliance_visible_to_central,
                'visible_to_firm_id' => $user->firm->compliance_visible_to_firm_id
                    ? (int) $user->firm->compliance_visible_to_firm_id
                    : null,
            ]);
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
     * Self-serve profile update: name, email, avatar, and per-user 2FA toggle.
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

        if ($emailChanging || $twoFactorChanging) {
            $currentPassword = (string) ($validated['current_password'] ?? '');
            if ($currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Your current password is required to change email or two-factor authentication.'],
                ]);
            }
        }

        $changes = [];

        if (array_key_exists('name', $validated) && $validated['name'] !== $user->name) {
            $user->name = $validated['name'];
            $changes[] = 'name';
        }

        if ($emailChanging) {
            $user->email = $validated['email'];
            $user->email_verified_at = null;
            $changes[] = 'email';
        }

        if ($twoFactorChanging) {
            $user->two_factor_enabled = $twoFactorEnabled;
            $changes[] = $user->two_factor_enabled ? 'two_factor_enabled' : 'two_factor_disabled';
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

        $user = $user->fresh();

        $message = 'Profile updated successfully.';
        if ($emailChanging) {
            $message = 'Profile updated. Please verify your new email address — a confirmation link has been sent.';
        }

        return response()->json([
            'message' => $message,
            'user' => $this->decorateUserForAuthResponse($user),
            'email_verification_required' => $emailChanging,
        ]);
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
