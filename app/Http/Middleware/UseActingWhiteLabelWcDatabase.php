<?php

namespace App\Http\Middleware;

use App\Services\ActingHubService;
use App\Services\WhiteLabelDatabaseService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the Central Hub switcher is on a remote content hub, point Website Compliance,
 * Social Media Compliance, General Compliance, Support Tickets, Live Chat, Security
 * Monitoring, and related Eloquent models at that hub's remote database for the rest
 * of the request. Hub registry stays on Central; the authenticated user is pinned to
 * the control-plane connection so capability checks do not hit remounted user_roles.
 */
class UseActingWhiteLabelWcDatabase
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->actingHubs->isActingRemotely($user)) {
            return $next($request);
        }

        try {
            $hub = $this->actingHubs->requireActingContentHub($user);
            $this->remoteDb->assertConfigured($hub);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        // Pin auth identity to this deploy's DB before remounting tenant models.
        // Unpinned User::getConnectionName() would follow WcDatabaseContext and
        // resolve roles for the same numeric id on the content hub (often wrong).
        $this->pinAuthUserToHomeConnection($request);

        return $this->remoteDb->run($hub, function (string $connection) use ($next, $request, $hub) {
            return WcDatabaseContext::using($connection, fn () => $next($request), (int) $hub->id);
        });
    }

    private function pinAuthUserToHomeConnection(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        try {
            $home = $user->getConnection()->getName();
        } catch (\Throwable) {
            $home = (string) config('database.default');
        }

        if ($home === '') {
            return;
        }

        $user->setConnection($home);

        $sanctum = $request->user('sanctum');
        if ($sanctum && $sanctum !== $user) {
            $sanctum->setConnection($home);
        }
    }
}
