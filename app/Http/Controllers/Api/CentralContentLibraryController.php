<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCentralLibraryImportJob;
use App\Models\Post;
use App\Services\ContentPushService;
use App\Services\ContentTaxonomyService;
use App\Services\HubService;
use App\Services\PostImportService;
use App\Support\QueuesContentPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Central Hub content library: create (manual / Excel), distribute live posts,
 * archive / unarchive (retire or restore from distribute; keeps the library row).
 * AI generation is intentionally a stub (“under development”).
 */
class CentralContentLibraryController extends Controller
{
    use QueuesContentPush;

    public function __construct(
        private readonly HubService $hubs,
        private readonly PostImportService $imports,
        private readonly ContentPushService $pushes,
        private readonly ContentTaxonomyService $taxonomy
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCentralLibrary();

        $perPage = min(100, max(1, (int) $request->integer('per_page', 50)));
        $query = Post::query()
            ->with(['creator:id,name', 'archiver:id,name'])
            ->latest('updated_at');

        $status = $request->string('status')->toString();
        if ($status === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif ($status === 'active') {
            $query->whereNull('archived_at');
        }

        if ($request->filled('source')) {
            $query->where('creation_source', $request->string('source')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $posts = $query->paginate($perPage);

        $distributions = $this->pushes->successfulDistributionsForPosts(
            $posts->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $posts->getCollection()->transform(function (Post $post) use ($distributions) {
            $post->setAttribute('distributed_hubs', $distributions[(int) $post->id] ?? []);

            return $post;
        });

        return response()->json($posts);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCentralLibrary();

        if ($request->has('tags') && is_string($request->input('tags'))) {
            $decoded = json_decode($request->input('tags'), true);
            $request->merge([
                'tags' => (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [],
            ]);
        }
        if ($request->has('categories') && is_string($request->input('categories'))) {
            $decoded = json_decode($request->input('categories'), true);
            $request->merge([
                'categories' => (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [],
            ]);
        }
        if ($request->has('is_active') && ! is_bool($request->input('is_active'))) {
            $request->merge([
                'is_active' => filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('canva_link') && trim((string) $request->input('canva_link')) === '') {
            $request->merge(['canva_link' => null]);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:100'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'credits_cost' => ['required', 'integer', 'min:1'],
            'canva_link' => ['nullable', 'url', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:102400'],
        ]);

        $attachment = $this->storeAttachment($request);

        $this->taxonomy->ensureNames(
            $validated['type'],
            array_values($validated['categories']),
            array_values($validated['tags'] ?? [])
        );

        $post = Post::query()->create([
            'created_by' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'categories' => array_values($validated['categories']),
            'tags' => array_values($validated['tags'] ?? []),
            'credits_cost' => $validated['credits_cost'],
            'attachment_path' => $attachment['path'] ?? null,
            'attachment_name' => $attachment['name'] ?? null,
            'attachment_mime' => $attachment['mime'] ?? null,
            'canva_link' => $validated['canva_link'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'creation_source' => Post::SOURCE_MANUAL,
        ]);

        return response()->json([
            'message' => 'Post added to the Central content library.',
            'post' => $post->load('creator:id,name'),
        ], 201);
    }

    public function archive(Request $request, int $post): JsonResponse
    {
        $this->assertCentralLibrary();

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:2000'],
        ]);

        $model = Post::query()->findOrFail($post);
        if ($model->archived_at !== null) {
            return response()->json(['message' => 'Post is already archived.'], 422);
        }

        try {
            $model->archive($validated['remarks'], $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Post archived. It stays in the Central library but can no longer be distributed.',
            'post' => $model->fresh()->load(['creator:id,name', 'archiver:id,name']),
        ]);
    }

    public function unarchive(Request $request, int $post): JsonResponse
    {
        $this->assertCentralLibrary();

        $model = Post::query()->findOrFail($post);
        if ($model->archived_at === null) {
            return response()->json(['message' => 'Post is not archived.'], 422);
        }

        $model->unarchive();

        return response()->json([
            'message' => 'Post unarchived. It can be distributed again.',
            'post' => $model->fresh()->load(['creator:id,name']),
        ]);
    }

    public function template(): StreamedResponse
    {
        $this->assertCentralLibrary();

        try {
            $xlsx = $this->imports->templateXlsx();
        } catch (InvalidArgumentException|\RuntimeException $e) {
            abort(response()->json(['message' => $e->getMessage()], 422));
        }

        return response()->streamDownload(function () use ($xlsx) {
            echo $xlsx;
        }, 'central-posts-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->assertCentralLibrary();

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $jobId = (string) Str::uuid();
        $storedPath = $file->storeAs(
            'central-library-imports/tmp',
            $jobId.'.'.$extension,
            'local'
        );

        Cache::put(ProcessCentralLibraryImportJob::cacheKey($jobId), [
            'status' => 'queued',
            'user_id' => (int) $request->user()->id,
            'message' => 'Import queued. Waiting for a worker…',
        ], now()->addHour());

        ProcessCentralLibraryImportJob::dispatch(
            $jobId,
            $storedPath,
            $file->getClientOriginalName() ?: ('import.'.$extension),
            (int) $request->user()->id,
        );

        return response()->json([
            'queued' => true,
            'job_id' => $jobId,
            'message' => 'Import queued. Processing in the background…',
        ], 202);
    }

    public function importStatus(Request $request, string $jobId): JsonResponse
    {
        $this->assertCentralLibrary();

        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $jobId)) {
            return response()->json(['message' => 'Invalid import job id.'], 422);
        }

        $payload = Cache::get(ProcessCentralLibraryImportJob::cacheKey($jobId));
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $request->user()->id) {
            return response()->json(['message' => 'Import job not found.'], 404);
        }

        return response()->json($payload);
    }

    public function aiStub(): JsonResponse
    {
        $this->assertCentralLibrary();

        return response()->json([
            'status' => 'under_development',
            'message' => 'AI post generation is under development and not available yet.',
            'posts' => [],
        ]);
    }

    public function targets(): JsonResponse
    {
        $this->assertCentralLibrary();

        return response()->json([
            'hubs' => $this->pushes->eligibleTargetHubs(),
        ]);
    }

    public function push(Request $request): JsonResponse
    {
        $this->assertCentralLibrary();

        $validated = $request->validate([
            'post_ids' => ['required', 'array', 'min:1'],
            'post_ids.*' => ['integer', 'distinct', 'exists:posts,id'],
            'hub_ids' => ['required', 'array', 'min:1'],
            'hub_ids.*' => ['integer', 'distinct', 'exists:hubs,id'],
        ]);

        return $this->dispatchContentPush(
            array_map('intval', $validated['post_ids']),
            array_map('intval', $validated['hub_ids']),
            $request->user()
        );
    }

    public function pushStatus(Request $request, string $jobId): JsonResponse
    {
        $this->assertCentralLibrary();

        return $this->contentPushStatusPayload($jobId, $request->user());
    }

    private function assertCentralLibrary(): void
    {
        if (! $this->hubs->current()->isControlPlane()) {
            abort(403, 'Central content library is only available on the Central Hub Controller.');
        }
    }

    /**
     * @return array{path: ?string, name: ?string, mime: ?string}
     */
    private function storeAttachment(Request $request): array
    {
        if (! $request->hasFile('attachment')) {
            return ['path' => null, 'name' => null, 'mime' => null];
        }

        $file = $request->file('attachment');
        $path = $file->store('posts', 'public');

        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType() ?: $file->getMimeType(),
        ];
    }
}
