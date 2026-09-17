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
 * While the shared-hub switcher is on a white-label hub, point Website Compliance
 * Eloquent models at that hub's remote database for the rest of the request.
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
        if (! $user || ! $this->actingHubs->isActingOnWhiteLabel($user)) {
            return $next($request);
        }

        try {
            $hub = $this->actingHubs->requireActingWhiteLabel($user);
            $this->remoteDb->assertConfigured($hub);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->remoteDb->run($hub, function (string $connection) use ($next, $request) {
            return WcDatabaseContext::using($connection, fn () => $next($request));
        });
    }
}
