<?php

namespace App\Http\Middleware;

use App\Services\UploadSecurityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-SEC-FW — scan multipart uploads before controllers run.
 */
class ScanUploadedFiles
{
    public function __construct(private UploadSecurityService $uploads) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->files->count() > 0) {
            $this->uploads->assertRequestUploadsSafe($request);
        }

        return $next($request);
    }
}
