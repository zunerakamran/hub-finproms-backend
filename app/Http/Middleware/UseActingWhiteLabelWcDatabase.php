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
 * Social Media Compliance, and General Compliance Eloquent models at that hub's
 * remote database for the rest of the request (Hub registry / auth stay on Central).
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

        return $this->remoteDb->run($hub, function (string $connection) use ($next, $request, $hub) {
            return WcDatabaseContext::using($connection, fn () => $next($request), (int) $hub->id);
        });
    }
}
