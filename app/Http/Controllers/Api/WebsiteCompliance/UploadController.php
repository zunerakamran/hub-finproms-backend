<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class UploadController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate
    ) {}

    public function uploadImage(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        try {
            if (! $request->hasFile('image')) {
                return response()->json([
                    'message' => 'No image file uploaded. Use form field name "image".',
                ], 400);
            }

            $file = $request->file('image');
            if (! $file->isValid()) {
                $error = method_exists($file, 'getErrorMessage')
                    ? $file->getErrorMessage()
                    : 'Invalid upload.';

                return response()->json(['message' => $error], 400);
            }

            $extension = strtolower((string) $file->getClientOriginalExtension());
            if ($extension === '') {
                $extension = 'jpg';
            }

            $allowed = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'svg', 'ico'];
            if (! in_array($extension, $allowed, true)) {
                return response()->json([
                    'message' => 'Only jpeg, png, gif, webp, svg, or ico images are allowed.',
                ], 422);
            }

            $filename = time().'_'.Str::random(10).'.'.$extension;
            $uploadsDir = storage_path('app/uploads');
            if (! is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }

            // Always write to storage/app/uploads — Laravel's "local" disk roots at private/.
            $target = $uploadsDir.DIRECTORY_SEPARATOR.$filename;
            if (! @copy($file->getRealPath(), $target)) {
                $file->move($uploadsDir, $filename);
            }

            if (! is_file($target)) {
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
            Log::error('Image upload failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Image upload failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload a document (PDF / Office / text) for template-request content fields
     * (services, policies, page contents).
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        try {
            if (! $request->hasFile('document')) {
                return response()->json([
                    'message' => 'No document uploaded. Use form field name "document".',
                ], 400);
            }

            $file = $request->file('document');
            if (! $file->isValid()) {
                $error = method_exists($file, 'getErrorMessage')
                    ? $file->getErrorMessage()
                    : 'Invalid upload.';

                return response()->json(['message' => $error], 400);
            }

            $maxKb = 10240;
            if ($file->getSize() > $maxKb * 1024) {
                return response()->json([
                    'message' => 'Document must be 10 MB or smaller.',
                ], 422);
            }

            $extension = strtolower((string) $file->getClientOriginalExtension());
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'csv'];
            if (! in_array($extension, $allowed, true)) {
                return response()->json([
                    'message' => 'Allowed types: PDF, Word, Excel, PowerPoint, TXT, RTF, ODT, CSV.',
                ], 422);
            }

            $originalName = (string) $file->getClientOriginalName();
            $safeOriginal = preg_replace('/[^\w.\- ()]+/u', '_', $originalName) ?: ('document.'.$extension);
            $filename = time().'_'.Str::random(10).'.'.$extension;
            $uploadsDir = storage_path('app/uploads');
            if (! is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }

            $target = $uploadsDir.DIRECTORY_SEPARATOR.$filename;
            if (! @copy($file->getRealPath(), $target)) {
                $file->move($uploadsDir, $filename);
            }

            if (! is_file($target)) {
                return response()->json([
                    'message' => 'Could not save the document. Check that storage/app/uploads is writable.',
                ], 500);
            }

            $relativePath = '/website-compliance/uploaded-images/'.$filename;

            return response()->json([
                'message' => 'Document uploaded successfully',
                'url' => $relativePath,
                'relative_url' => $relativePath,
                'filename' => $filename,
                'original_name' => Str::limit($safeOriginal, 200, ''),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Document upload failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Document upload failed: '.$e->getMessage(),
            ], 500);
        }
    }

    public function show(string $filename): BinaryFileResponse
    {
        $filename = basename($filename);
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            abort(404);
        }

        $paths = [
            storage_path('app/uploads/'.$filename),
            storage_path('app/private/uploads/'.$filename),
            public_path('uploads/'.$filename),
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return response()->file($path, [
                    'Cache-Control' => 'public, max-age=31536000',
                ]);
            }
        }

        abort(404);
    }
}
