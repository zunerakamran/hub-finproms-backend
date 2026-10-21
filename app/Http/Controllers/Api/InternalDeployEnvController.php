<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HubDeployEnvService;
use App\Services\HubService;
use App\Support\HubInternalAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Content hub: apply allowlisted deploy env keys from Central.
 * Auth: X-Hub-Deploy-Env-Key (deploy_env_token / HUB_DEPLOY_ENV_SECRET) — not the backup key.
 */
class InternalDeployEnvController extends Controller
{
    public function __construct(
        private readonly HubDeployEnvService $deployEnv,
        private readonly HubService $hubs,
    ) {}

    public function apply(Request $request): JsonResponse
    {
        if (! HubInternalAuth::authorizeCurrentHub($request, HubInternalAuth::PURPOSE_DEPLOY_ENV)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'values' => ['required', 'array', 'max:20'],
            'values.*' => ['nullable', 'string', 'max:2048'],
        ]);

        /** @var array<string, string> $values */
        $values = [];
        foreach ($validated['values'] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        try {
            $result = $this->deployEnv->writeLocalEnvFile($values);
            // Refresh runtime config for this process too.
            $this->deployEnv->applyRuntimeConfig($this->hubs->current());

            return response()->json([
                'message' => 'Deploy env updated on this hub (.env).',
                'written' => $result['written'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
