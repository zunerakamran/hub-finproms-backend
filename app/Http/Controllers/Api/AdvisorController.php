<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdvisorImportService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvisorController extends Controller
{
    public function __construct(
        private readonly AdvisorImportService $importService,
        private readonly HubService $hubs
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertImportEnabled($request);

        $advisors = User::query()
            ->where('is_advisor', true)
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json($advisors);
    }

    public function import(Request $request): JsonResponse
    {
        $this->assertImportEnabled($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        if (! in_array($extension, ['csv', 'txt'], true)) {
            return response()->json([
                'message' => 'Please upload a CSV file exported from Excel (Save As → CSV).',
            ], 422);
        }

        $result = $this->importService->import($file);

        return response()->json([
            'message' => sprintf(
                'Import finished: %d created, %d updated, %d skipped.',
                $result['summary']['created'],
                $result['summary']['updated'],
                $result['summary']['skipped']
            ),
            ...$result,
        ]);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->assertImportEnabled($request);

        $csv = $this->importService->templateCsv();

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'advisor-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function assertImportEnabled(Request $request): void
    {
        $hub = $this->hubs->current();
        $user = $request->user();

        $allowed = $user
            ? app(\App\Services\CapabilitiesMatrixService::class)->roleCan($hub, (string) $user->role, 'advisor_excel_import')
            : $hub->can('advisor_excel_import');

        if (! $allowed) {
            abort(response()->json([
                'message' => 'Advisor Excel import is disabled for your role on this hub. Enable it in Power Admin → Capabilities.',
            ], 403));
        }
    }
}
