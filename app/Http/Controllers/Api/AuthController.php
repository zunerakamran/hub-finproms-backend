<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        private readonly PowerAdminCapabilitiesService $powerCapabilities
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
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->isSuspended()) {
            Auth::logout();

            return response()->json([
                'message' => 'This account is suspended. Contact your hub administrator.',
            ], 403);
        }

        if ($this->hubs->can('private_invite_only') && ! $user->mayLoginOnInviteOnlyHub()) {
            Auth::logout();

            return response()->json([
                'message' => 'This hub is invite-only. Only advisors imported from the invite list can sign in.',
                'registration_enabled' => false,
                'invite_only' => true,
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

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
        ];

        if ($user->isPowerAdmin()) {
            $payload['power_admin_capabilities'] = $this->powerCapabilities->resolved();
        }

        return response()->json($payload);
    }
}
