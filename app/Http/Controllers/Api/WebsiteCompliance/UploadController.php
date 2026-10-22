<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class UploadController extends Controller
{
    /** @var list<string> */
    private const IMAGE_EXTENSIONS = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'ico'];

    /** @var list<string> */
    private const DOCUMENT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'csv',
    ];

    public function __construct(
        private readonly WebsiteComplianceGate $gate
    ) {}

    public function uploadImage(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        try {
            $request->validate([
                'image' => [
                    'required',
                    'file',
                    'max:10240',
                    'mimes:jpeg,jpg,png,gif,webp,ico',
                ],
            ]);

            $file = $request->file('image');
            $extension = strtolower((string) $file->getClientOriginalExtension());
            if ($extension === '') {
                $extension = 'jpg';
            }

            // SVG blocked here and by NFR-SEC-FW upload middleware (scriptable).
            if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                return response()->json([
                    'message' => 'Only jpeg, png, gif, webp, or ico images are allowed.',
                ], 422);
            }

            $filename = time().'_'.Str::random(10).'.'.$extension;
            $target = $this->storeUpload($file, $filename);
            if ($target === null) {
                return response()->json([
                    'message' => 'Could not save the image. Check that storage/app/uploads is writable.',
                ], 500);
            }

            $relativePath = '/website-compliance/uploaded-images/'.$filename;

            return response()->json([
                'message' => 'Image uploaded successfully',
                'url' => $relativePath,
                'relative_url' => $relativePath,
                'filename' => $filename,
            ], 201);
        } catch (Throwable $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }

            Log::error('Image upload failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Image upload failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload a document (PDF / Office / text) for template-request content fields
     * (services, policies, page contents). Served only via auth or signed URL.
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        try {
            $request->validate([
                'document' => [
                    'required',
                    'file',
                    'max:10240',
                    'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,csv',
                ],
            ]);

            $file = $request->file('document');
            $extension = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($extension, self::DOCUMENT_EXTENSIONS, true)) {
                return response()->json([
                    'message' => 'Allowed types: PDF, Word, Excel, PowerPoint, TXT, RTF, ODT, CSV.',
                ], 422);
            }

            $originalName = (string) $file->getClientOriginalName();
            $safeOriginal = preg_replace('/[^\w.\- ()]+/u', '_', $originalName) ?: ('document.'.$extension);
            $filename = time().'_'.Str::random(10).'.'.$extension;
            $target = $this->storeUpload($file, $filename);
            if ($target === null) {
                return response()->json([
                    'message' => 'Could not save the document. Check that storage/app/uploads is writable.',
                ], 500);
            }

            $relativePath = '/website-compliance/uploaded-documents/'.$filename;
            $signedUrl = URL::temporarySignedRoute(
                'wc.uploaded-document',
                now()->addMinutes(30),
                ['filename' => $filename]
            );

            return response()->json([
                'message' => 'Document uploaded successfully',
                'url' => $relativePath,
                'relative_url' => $relativePath,
                'signed_url' => $signedUrl,
                'filename' => $filename,
                'original_name' => Str::limit($safeOriginal, 200, ''),
            ], 201);
        } catch (Throwable $e) {
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }

            Log::error('Document upload failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Document upload failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Public image serving for live advisor sites / previews.
     * Document extensions on this legacy path require auth or a signed URL.
     */
    public function show(Request $request, string $filename): BinaryFileResponse
    {
        $filename = $this->safeFilename($filename);

        if ($this->isDocumentFilename($filename)) {
            return $this->serveProtectedDocument($request, $filename);
        }

        $path = $this->resolveUploadPath($filename);
        if ($path === null) {
            abort(404);
        }

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=31536000',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Protected document download (Sanctum session or temporary signed URL).
     */
    public function showDocument(Request $request, string $filename): BinaryFileResponse
    {
        $filename = $this->safeFilename($filename);

        if (! $this->isDocumentFilename($filename)) {
            abort(404);
        }

        return $this->serveProtectedDocument($request, $filename);
    }

    private function serveProtectedDocument(Request $request, string $filename): BinaryFileResponse
    {
        $signedOk = $request->hasValidSignature();
        $user = $request->user();
        $userOk = false;
        if ($user) {
            try {
                $this->gate->assertModuleEnabled($user);
                $userOk = true;
            } catch (Throwable) {
                $userOk = false;
            }
        }

        if (! $signedOk && ! $userOk) {
            abort(401, 'Authentication or a signed document URL is required.');
        }

        $path = $this->resolveUploadPath($filename);
        if ($path === null) {
            abort(404);
        }

        $response = response()->file($path, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            // Shared hosts / LiteSpeed may ignore Cache-Control alone.
            'X-LiteSpeed-Cache-Control' => 'no-cache',
        ]);
        // BinaryFileResponse may inject "public" — force private no-store after.
        $response->headers->set('Cache-Control', 'private, no-store, no-transform');

        return $response;
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename($filename);
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            abort(404);
        }

        return $filename;
    }

    private function isDocumentFilename(string $filename): bool
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($ext, self::DOCUMENT_EXTENSIONS, true);
    }

    private function resolveUploadPath(string $filename): ?string
    {
        $paths = [
            storage_path('app/uploads/'.$filename),
            storage_path('app/private/uploads/'.$filename),
            public_path('uploads/'.$filename),
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function storeUpload(\Illuminate\Http\UploadedFile $file, string $filename): ?string
    {
        $uploadsDir = storage_path('app/uploads');
        if (! is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        // Always write to storage/app/uploads — Laravel's "local" disk roots at private/.
        $target = $uploadsDir.DIRECTORY_SEPARATOR.$filename;
        if (! @copy($file->getRealPath(), $target)) {
            $file->move($uploadsDir, $filename);
        }

        return is_file($target) ? $target : null;
    }
}
