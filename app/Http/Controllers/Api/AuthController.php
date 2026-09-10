<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\HubService;
use App\Services\PowerAdminCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly PowerAdminCapabilitiesService $powerCapabilities,
        private readonly ActivityLogService $activityLogs
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

        return response()->json([
            'message' => 'Registration successful.',
            'user' => $user,
            'token' => $token,
        ], 201);
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
