<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline HTTP security headers for API responses.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->secure() || app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // Embed proxy must remain frameable by the hub UI; skip framing locks there.
        $isEmbedProxy = str_contains($request->path(), 'website-compliance/public/embed');
        if (! $isEmbedProxy) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

            // JSON APIs should not be treated as navigable documents.
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->headers->set(
                    'Content-Security-Policy',
                    "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
                );
            }
        }

        return $response;
    }
}
