<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\UserCapabilitiesService;
use App\Services\WhiteLabelUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Per-user capability overrides within assigned-role UNION.
 * Gated by dashboard_manage_user_capabilities.
 */
class UserCapabilitiesController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly UserCapabilitiesService $userCapabilities,
        private readonly WhiteLabelUserService $whiteLabelUsers,
    ) {}

    public function show(Request $request, int $userId): JsonResponse
    {
        [$hub, $user] = $this->resolveHubAndUser($request, $userId);

        return response()->json($this->userCapabilities->matrixForUser($hub, $user));
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'capabilities' => ['required', 'array'],
        ]);

        [$hub, $user] = $this->resolveHubAndUser($request, $userId);
        $capabilities = $this->normalizeCapabilities($validated['capabilities']);

        try {
            $result = $this->userCapabilities->update(
                $hub,
                $user,
                $capabilities,
                $request->user(),
                $request,
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json([
            'message' => 'User capabilities updated.',
            ...$result,
        ]);
    }

    /**
     * @return array{0: Hub, 1: User}
     */
    private function resolveHubAndUser(Request $request, int $userId): array
    {
        $actor = $request->user();
        if (! $actor) {
            abort(401);
        }

        if ($this->actingHubs->isActingRemotely($actor)) {
            try {
                $hub = $this->actingHubs->requireActingContentHub($actor);
            } catch (InvalidArgumentException $e) {
                throw new HttpException(422, $e->getMessage());
            }

            try {
                $this->whiteLabelUsers->assertTarget($hub);
            } catch (InvalidArgumentException $e) {
                throw new HttpException(422, $e->getMessage());
            }

            $user = app(\App\Services\WhiteLabelDatabaseService::class)->run(
                $hub,
                function (string $connection) use ($userId) {
                    return User::on($connection)->with('roleAssignments')->findOrFail($userId);
                }
            );

            return [$hub, $user];
        }

        $hub = $this->actingHubs->targetHub($actor);
        $user = User::query()->with('roleAssignments')->findOrFail($userId);

        return [$hub, $user];
    }

    /**
     * Accept { key: bool } or [{ key, enabled|value }].
     *
     * @param  array<int|string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    private function normalizeCapabilities(array $capabilities): array
    {
        if (array_is_list($capabilities)) {
            $map = [];
            foreach ($capabilities as $row) {
                if (! is_array($row) || ! isset($row['key'])) {
                    continue;
                }
                $enabled = $row['enabled'] ?? $row['value'] ?? null;
                if ($enabled === null) {
                    continue;
                }
                $map[(string) $row['key']] = $enabled;
            }

            return $map;
        }

        return $capabilities;
    }
}
