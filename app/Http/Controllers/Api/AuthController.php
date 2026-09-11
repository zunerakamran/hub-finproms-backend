<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AdminNewUserRegistrationMailService;
use App\Services\FunctionalMailService;
use App\Services\HubService;
use App\Services\PowerAdminCapabilitiesService;
use App\Services\WelcomeMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly PowerAdminCapabilitiesService $powerCapabilities,
        private readonly ActivityLogService $activityLogs,
        private readonly WelcomeMailService $welcomeMail,
        private readonly AdminNewUserRegistrationMailService $adminNewUserMail,
        private readonly FunctionalMailService $functionalMail
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
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->activityLogs->log([
            'action' => 'auth.register',
            'description' => 'User registered: '.$user->email,
            'user' => $user,
            'request' => $request,
            'subject' => $user,
            'status_code' => 201,
        ]);

        $this->welcomeMail->send($user);
        $this->adminNewUserMail->send($user);

        return response()->json([
            'message' => 'Registration successful.',
            'user' => $user,
            'token' => $token,
        ], 201);
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

        if (! Auth::attempt($credentials)) {
            $this->activityLogs->log([
                'action' => 'auth.login_failed',
                'description' => 'Failed login attempt for '.$credentials['email'],
                'request' => $request,
                'status_code' => 422,
                'properties' => ['email' => $credentials['email']],
            ]);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->isSuspended()) {
            Auth::logout();

            $this->activityLogs->log([
                'action' => 'auth.login_blocked',
                'description' => 'Suspended user blocked from login: '.$user->email,
                'user' => $user,
                'request' => $request,
                'status_code' => 403,
                'properties' => ['reason' => 'suspended'],
            ]);

            return response()->json([
                'message' => 'This account is suspended. Contact your hub administrator.',
            ], 403);
        }

        if ($user->isDiscontinued()) {
            Auth::logout();

            $this->activityLogs->log([
                'action' => 'auth.login_blocked',
                'description' => 'Discontinued advisor blocked from login: '.$user->email,
                'user' => $user,
                'request' => $request,
                'status_code' => 403,
                'properties' => ['reason' => 'discontinued'],
            ]);

            return response()->json([
                'message' => 'This advisor account has been discontinued. Contact your hub administrator.',
            ], 403);
        }

        if ($this->hubs->can('private_invite_only') && ! $user->mayLoginOnInviteOnlyHub()) {
            Auth::logout();

            $this->activityLogs->log([
                'action' => 'auth.login_blocked',
                'description' => 'Invite-only hub blocked login: '.$user->email,
                'user' => $user,
                'request' => $request,
                'status_code' => 403,
                'properties' => ['reason' => 'invite_only'],
            ]);

            return response()->json([
                'message' => 'This hub is invite-only. Only advisors imported from the invite list can sign in.',
                'registration_enabled' => false,
                'invite_only' => true,
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->activityLogs->log([
            'action' => 'auth.login',
            'description' => 'User logged in: '.$user->email,
            'user' => $user,
            'request' => $request,
            'subject' => $user,
            'status_code' => 200,
        ]);

        return response()->json([
            'message' => 'Login successful.',
            'user' => $user,
            'token' => $token,
        ]);
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

        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payload = [
            'user' => $user,
            'visible_metrics' => $user->contentMetricVisibility(),
            'active_plan' => $user->activePlan(),
        ];

        if ($user->isPowerAdmin()) {
            $payload['power_admin_capabilities'] = $this->powerCapabilities->resolved();
        }

        return response()->json($payload);
    }
}
