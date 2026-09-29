<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persist an activity log entry for successful mutating API requests.
 */
class LogApiActivity
{
    public function __construct(
        private readonly ActivityLogService $activityLogs
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            return $response;
        }

        try {
            $this->activityLogs->logApiRequest($request, $status);
        } catch (\Throwable) {
            // Never break the main request because logging failed.
        }

        return $response;
    }
}
