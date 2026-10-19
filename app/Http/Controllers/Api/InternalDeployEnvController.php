<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HubBackupService;
use App\Services\HubDeployEnvService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Content hub: apply allowlisted deploy env keys from Central.
 * Auth: same X-Hub-Backup-Key as backup callbacks.
 */
class InternalDeployEnvController extends Controller
{
    public function __construct(
        private readonly HubDeployEnvService $deployEnv,
        private readonly HubService $hubs,
        private readonly HubBackupService $backups,
    ) {}

    public function apply(Request $request): JsonResponse
    {
        if (! $this->authorizeHub($request)) {
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

    private function authorizeHub(Request $request): bool
    {
        $provided = (string) $request->header('X-Hub-Backup-Key', '');
        if ($provided === '') {
            return false;
        }

        $hub = $this->hubs->current();
        $expected = (string) ($hub->backup_token ?: '');
        $shared = (string) config('services.hub_backup.secret', '');

        if ($expected !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        return $shared !== '' && hash_equals($shared, $provided);
    }
}
