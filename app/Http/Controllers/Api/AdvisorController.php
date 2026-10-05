<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAdvisorImportJob;
use App\Models\AdvisorImportBatch;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\AdvisorImportHistoryService;
use App\Services\AdvisorImportService;
use App\Services\CapabilitiesMatrixService;
use App\Services\WhiteLabelDatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdvisorController extends Controller
{
    public function __construct(
        private readonly AdvisorImportService $importService,
        private readonly AdvisorImportHistoryService $importHistory,
        private readonly ActingHubService $actingHubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->assertCanAccessAdvisors($request);
        $status = (string) $request->query('status', 'active');
        $perPage = (int) $request->integer('per_page', 50);

        if ($this->shouldUseRemote($request, $hub)) {
            $payload = $this->remoteDb->run($hub, function (string $connection) use ($status, $perPage) {
                $query = User::on($connection)->with('firm:id,name')->where('is_advisor', true);

                if ($status === 'discontinued') {
                    $query->where('is_discontinued', true);
                } elseif ($status !== 'all') {
                    $query->where('is_suspended', false)->where('is_discontinued', false);
                }

                return $query->orderBy('name')->paginate($perPage);
            });

            return response()->json($payload);
        }

        $query = User::query()->with('firm:id,name')->where('is_advisor', true);

        if ($status === 'discontinued') {
            $query->where('is_discontinued', true);
        } elseif ($status !== 'all') {
            $query->where('is_suspended', false)->where('is_discontinued', false);
        }

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function importHistory(Request $request): JsonResponse
    {
        $hub = $this->assertCanAccessAdvisors($request);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $paginator = $this->importHistory->paginate($hub, $perPage, $page);

        return response()->json([
            'batches' => $paginator->getCollection()
                ->map(fn (AdvisorImportBatch $batch) => $batch->toApiArray())
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'target_hub' => $this->hubPayload($hub),
            'capabilities' => [
                'can_import' => $this->roleCan($request, $hub, 'advisor_excel_import'),
                'can_download_template' => $this->canDownloadTemplate($request, $hub),
                'can_submit' => $this->roleCan($request, $hub, 'advisor_excel_submit'),
            ],
        ]);
    }

    public function discontinue(Request $request, int $advisor): JsonResponse
    {
        $hub = $this->assertCanDiscontinue($request);

        if ($this->shouldUseRemote($request, $hub)) {
            try {
                $user = $this->remoteDb->run($hub, function (string $connection) use ($advisor) {
                    $model = User::on($connection)->find($advisor);
                    if (! $model) {
                        throw new HttpException(404, 'Advisor not found.');
                    }

                    return $this->importService->discontinue($model);
                });
            } catch (HttpException $e) {
                return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
            }

            return response()->json([
                'message' => sprintf('%s has been discontinued and can no longer access this hub.', $user->name),
                'advisor' => $user,
                'target_hub' => $this->hubPayload($hub),
            ]);
        }

        $model = User::query()->find($advisor);
        if (! $model) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (ActingHubService::isControlPlaneRole((string) $model->role)) {
            return response()->json([
                'message' => 'Control-plane admin accounts cannot be discontinued here.',
            ], 422);
        }

        if ($model->isDiscontinued()) {
            return response()->json([
                'message' => 'This user is already discontinued.',
                'advisor' => $model,
            ]);
        }

        $model = $this->importService->discontinue($model);

        return response()->json([
            'message' => sprintf('%s has been discontinued and can no longer access this hub.', $model->name),
            'advisor' => $model,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $hub = $this->assertImportEnabled($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        if (! in_array($extension, ['xlsx', 'xls'], true)) {
            return response()->json([
                'message' => 'Please upload an Excel file (.xlsx).',
            ], 422);
        }

        $useRemote = $this->shouldUseRemote($request, $hub);
        $jobId = (string) Str::uuid();
        $storedPath = $file->storeAs(
            'advisor-imports/tmp',
            $jobId.'.'.$extension,
            'local'
        );

        Cache::put(ProcessAdvisorImportJob::cacheKey($jobId), [
            'status' => 'queued',
            'user_id' => (int) $request->user()->id,
            'message' => 'Import queued. Waiting for a worker…',
        ], now()->addHour());

        ProcessAdvisorImportJob::dispatch(
            $jobId,
            $storedPath,
            $file->getClientOriginalName() ?: ('import.'.$extension),
            (int) $request->user()->id,
            (int) $hub->id,
            $useRemote,
        );

        return response()->json([
            'queued' => true,
            'job_id' => $jobId,
            'message' => 'Import queued. Processing in the background…',
            'target_hub' => $this->hubPayload($hub),
        ], 202);
    }

    public function importStatus(Request $request, string $jobId): JsonResponse
    {
        $this->assertImportEnabled($request);

        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $jobId)) {
            return response()->json(['message' => 'Invalid import job id.'], 422);
        }

        $payload = Cache::get(ProcessAdvisorImportJob::cacheKey($jobId));
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $request->user()->id) {
            return response()->json(['message' => 'Import job not found.'], 404);
        }

        return response()->json($payload);
    }

    public function template(Request $request): StreamedResponse
    {
        $hub = $this->assertTemplateEnabled($request);

        try {
            if ($this->shouldUseRemote($request, $hub)) {
                $xlsx = $this->remoteDb->run($hub, function (string $connection) use ($hub) {
                    return $this->importService->templateXlsx($hub, $connection);
                });
            } else {
                $xlsx = $this->importService->templateXlsx($hub);
            }
        } catch (InvalidArgumentException|\RuntimeException $e) {
            abort(response()->json(['message' => $e->getMessage()], 422));
        }

        return response()->streamDownload(function () use ($xlsx) {
            echo $xlsx;
        }, 'advisor-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Staff with submit capability send a filled sheet for an importer to process later.
     */
    public function submit(Request $request): JsonResponse
    {
        $hub = $this->assertSubmitEnabled($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        if (! in_array($extension, ['xlsx', 'xls'], true)) {
            return response()->json([
                'message' => 'Please upload an Excel file (.xlsx).',
            ], 422);
        }

        try {
            if ($this->shouldUseRemote($request, $hub)) {
                $plan = $this->remoteDb->run($hub, function (string $connection) use ($file, $hub) {
                    return $this->importService->buildPlan($file, $hub, $connection);
                });
            } else {
                $plan = $this->importService->buildPlan($file, $hub);
            }
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $userCount = (int) ($plan['summary']['created'] ?? 0)
            + (int) ($plan['summary']['updated'] ?? 0)
            + (int) ($plan['summary']['reactivated'] ?? 0);

        if ($userCount < 1) {
            return response()->json([
                'message' => 'No valid users were found in this sheet. Fix the rows and try again.',
                'skipped' => $plan['skipped'] ?? [],
            ], 422);
        }

        $storedPath = $file->storeAs(
            'advisor-imports/submissions',
            (string) Str::uuid().'.'.$extension,
            'local'
        );

        $batch = $this->importHistory->recordSubmission(
            $hub,
            $request->user(),
            $plan,
            $file,
            $storedPath
        );

        return response()->json([
            'message' => sprintf(
                'Excel sheet sent for import. %d user%s pending — waiting for someone with Import advisors.',
                $userCount,
                $userCount === 1 ? '' : 's'
            ),
            'batch' => $batch->toApiArray(),
            'target_hub' => $this->hubPayload($hub),
        ], 201);
    }

    /**
     * Importer processes a previously submitted (pending) Excel sheet.
     */
    public function importSubmission(Request $request, int $batch): JsonResponse
    {
        $hub = $this->assertImportEnabled($request);

        $model = AdvisorImportBatch::query()
            ->where('hub_id', $hub->id)
            ->where('id', $batch)
            ->first();

        if (! $model) {
            return response()->json(['message' => 'Import submission not found.'], 404);
        }

        if (! $model->isPendingSubmission() || ! filled($model->stored_path)) {
            return response()->json([
                'message' => 'This submission is not pending or the Excel file is no longer available.',
            ], 422);
        }

        if (! \Illuminate\Support\Facades\Storage::disk('local')->exists($model->stored_path)) {
            return response()->json([
                'message' => 'The submitted Excel file is missing from storage. Ask the sender to submit again.',
            ], 422);
        }

        $useRemote = $this->shouldUseRemote($request, $hub);
        $jobId = (string) Str::uuid();
        $extension = pathinfo($model->stored_path, PATHINFO_EXTENSION) ?: 'xlsx';
        $jobPath = 'advisor-imports/tmp/'.$jobId.'.'.$extension;

        \Illuminate\Support\Facades\Storage::disk('local')->copy($model->stored_path, $jobPath);

        Cache::put(ProcessAdvisorImportJob::cacheKey($jobId), [
            'status' => 'queued',
            'user_id' => (int) $request->user()->id,
            'message' => 'Import queued from submitted sheet. Waiting for a worker…',
            'submission_batch_id' => $model->id,
        ], now()->addHour());

        ProcessAdvisorImportJob::dispatch(
            $jobId,
            $jobPath,
            $model->original_filename ?: ('submission.'.$extension),
            (int) $request->user()->id,
            (int) $hub->id,
            $useRemote,
        );

        // Mark submission as processing / leave pending until job completes.
        // Job will create a completed import batch; clear the pending one after queue.
        $model->message = sprintf(
            'Import started by %s (%s). Processing…',
            $request->user()->name,
            $request->user()->email
        );
        $model->save();

        // Attach submission id so the job can finalize the pending row.
        Cache::put(ProcessAdvisorImportJob::cacheKey($jobId), array_merge(
            Cache::get(ProcessAdvisorImportJob::cacheKey($jobId), []),
            ['submission_batch_id' => $model->id]
        ), now()->addHour());

        return response()->json([
            'queued' => true,
            'job_id' => $jobId,
            'message' => 'Import queued from the submitted Excel sheet.',
            'submission_batch_id' => $model->id,
            'target_hub' => $this->hubPayload($hub),
        ], 202);
    }

    private function assertImportEnabled(Request $request): Hub
    {
        $hub = $this->assertPrivateHub($request);
        $this->assertRoleCapability(
            $request,
            $hub,
            'advisor_excel_import',
            'Advisor Excel import is disabled for your role on this hub. Enable it in Power Admin → Capabilities.'
        );

        return $hub;
    }

    private function assertSubmitEnabled(Request $request): Hub
    {
        $hub = $this->assertPrivateHub($request);
        $this->assertRoleCapability(
            $request,
            $hub,
            'advisor_excel_submit',
            'Submitting filled Excel sheets is disabled for your role on this hub. Enable “Submit filled Excel for import” under Power Admin → Capabilities.'
        );

        return $hub;
    }

    private function assertTemplateEnabled(Request $request): Hub
    {
        $hub = $this->assertPrivateHub($request);

        if (! $this->canDownloadTemplate($request, $hub)) {
            abort(response()->json([
                'message' => 'Downloading the advisor import template is disabled for your role on this hub. Enable “Download import Excel template”, “Submit filled Excel for import”, or “Import advisors” under Power Admin → Capabilities.',
                'capability' => 'advisor_excel_template',
            ], 403));
        }

        return $hub;
    }

    private function assertCanDiscontinue(Request $request): Hub
    {
        $hub = $this->assertPrivateHub($request);
        $this->assertRoleCapability(
            $request,
            $hub,
            'advisor_discontinue',
            'Discontinuing users is disabled for your role on this hub. Enable it in Power Admin → Capabilities.'
        );

        return $hub;
    }

    private function assertCanAccessAdvisors(Request $request): Hub
    {
        $hub = $this->assertPrivateHub($request);

        if (! $this->roleCan($request, $hub, 'advisor_excel_import')
            && ! $this->roleCan($request, $hub, 'advisor_excel_template')
            && ! $this->roleCan($request, $hub, 'advisor_excel_submit')
        ) {
            abort(response()->json([
                'message' => 'Advisor import tools are disabled for your role on this hub. Enable Import advisors, Download import Excel template, and/or Submit filled Excel for import under Power Admin → Capabilities.',
            ], 403));
        }

        return $hub;
    }

    private function assertPrivateHub(Request $request): Hub
    {
        $hub = $this->targetHub($request);

        if (! $hub->can('private_invite_only')) {
            abort(response()->json([
                'message' => 'Advisor tools are only available while this hub is white-labelled (invite-only).',
            ], 403));
        }

        return $hub;
    }

    private function assertRoleCapability(Request $request, Hub $hub, string $capability, string $message): void
    {
        if (! $this->roleCan($request, $hub, $capability)) {
            abort(response()->json([
                'message' => $message,
                'capability' => $capability,
            ], 403));
        }
    }

    private function roleCan(Request $request, Hub $hub, string $capability): bool
    {
        $user = $request->user();

        return $user
            ? $this->matrix->roleCan($hub, (string) $user->role, $capability)
            : $hub->can($capability);
    }

    private function canDownloadTemplate(Request $request, Hub $hub): bool
    {
        return $this->roleCan($request, $hub, 'advisor_excel_template')
            || $this->roleCan($request, $hub, 'advisor_excel_submit')
            || $this->roleCan($request, $hub, 'advisor_excel_import');
    }

    private function targetHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub($request->user());
    }

    private function shouldUseRemote(Request $request, Hub $hub): bool
    {
        $user = $request->user();

        return $user
            && $hub->isWhiteLabel()
            && $this->actingHubs->isActingRemotely($user)
            && $hub->hasRemoteDatabaseConfigured();
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function hubPayload(Hub $hub): array
    {
        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'slug' => $hub->slug,
        ];
    }
}
