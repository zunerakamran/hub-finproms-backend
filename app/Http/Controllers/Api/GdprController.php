<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\ActivityLogService;
use App\Services\GdprDataExportService;
use App\Services\WhiteLabelDatabaseService;
use App\Services\WhiteLabelUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GdprController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelUserService $whiteLabelUsers,
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly GdprDataExportService $exports,
        private readonly ActivityLogService $activityLogs
    ) {}

    /**
     * Search users on the current / acting hub for DSAR tooling.
     */
    public function users(Request $request): JsonResponse
    {
        $targetHub = $this->targetHub($request);
        $assignableRoles = $this->assignableRoles($targetHub);

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', Rule::in($assignableRoles)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $page = max(1, (int) ($validated['page'] ?? 1));

        if ($remoteHub = $this->actingContentHub($request)) {
            try {
                $listed = $this->whiteLabelUsers->paginate(
                    $remoteHub,
                    $validated['q'] ?? null,
                    $validated['role'] ?? null,
                    $perPage,
                    $page
                );
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'users' => $listed['users'],
                'meta' => $listed['meta'],
                'hub' => $this->hubPayload($remoteHub),
                'acting_remotely' => true,
            ]);
        }

        $query = User::query()->with('firm:id,name')->orderBy('name')->orderBy('id');

        if (! empty($validated['q'])) {
            $term = '%'.$validated['q'].'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $labelHub = $targetHub;

        return response()->json([
            'users' => $paginator->getCollection()
                ->map(fn (User $user) => $this->whiteLabelUsers->serialize($user, $labelHub))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'hub' => $labelHub ? $this->hubPayload($labelHub) : null,
            'acting_remotely' => false,
        ]);
    }

    /**
     * Download a JSON subject-access package for one user.
     */
    public function export(Request $request, int $user): JsonResponse|StreamedResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($remoteHub = $this->actingContentHub($request)) {
            try {
                $package = $this->remoteDb->run($remoteHub, function (string $connection) use ($user, $remoteHub, $actor) {
                    $subject = User::on($connection)->with('firm:id,name,is_central')->find($user);
                    if (! $subject) {
                        throw new HttpException(404, 'User not found on the selected hub.');
                    }

                    return $this->exports->build($subject, $remoteHub, $actor);
                });
            } catch (HttpException $e) {
                return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $this->logExport($request, $actor, $package, $remoteHub, true);

            return $this->downloadJson($package, $remoteHub);
        }

        $hub = $this->targetHub($request);
        if (! $hub) {
            return response()->json(['message' => 'Hub not available.'], 422);
        }

        $subject = User::query()->with('firm:id,name,is_central')->find($user);
        if (! $subject) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $package = $this->exports->build($subject, $hub, $actor);
        $this->logExport($request, $actor, $package, $hub, false);

        return $this->downloadJson($package, $hub);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function downloadJson(array $package, Hub $hub): StreamedResponse
    {
        $subjectId = (int) ($package['export_meta']['subject_user_id'] ?? 0);
        $slug = preg_replace('/[^a-z0-9\-]+/i', '-', (string) $hub->slug) ?: 'hub';
        $filename = sprintf(
            'gdpr-export-%s-user-%d-%s.json',
            $slug,
            $subjectId,
            now()->format('Ymd-His')
        );

        $json = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"message":"Failed to encode export."}';
        }

        return response()->streamDownload(function () use ($json) {
            echo $json;
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function logExport(Request $request, User $actor, array $package, Hub $hub, bool $remote): void
    {
        $subjectId = (int) ($package['export_meta']['subject_user_id'] ?? 0);
        $email = $package['profile']['email'] ?? null;

        try {
            $this->activityLogs->log([
                'action' => 'gdpr.export',
                'description' => 'GDPR subject-access export for user #'.$subjectId
                    .($email ? ' ('.$email.')' : '')
                    .' on hub '.$hub->slug
                    .($remote ? ' (remote)' : ''),
                'user' => $actor,
                'request' => $request,
                'status_code' => 200,
                'properties' => [
                    'subject_user_id' => $subjectId,
                    'subject_email' => $email,
                    'hub_id' => $hub->id,
                    'hub_slug' => $hub->slug,
                    'acting_remotely' => $remote,
                ],
            ]);
        } catch (\Throwable) {
            //
        }
    }

    private function targetHub(Request $request): ?Hub
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        try {
            return $this->actingHubs->targetHub($user);
        } catch (\Throwable) {
            return null;
        }
    }

    private function actingContentHub(Request $request): ?Hub
    {
        $user = $request->user();
        if (! $user || ! $this->actingHubs->isActingRemotely($user)) {
            return null;
        }

        try {
            return $this->actingHubs->requireActingContentHub($user);
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(?Hub $hub): array
    {
        return array_keys(User::ROLE_LABELS);
    }

    /**
     * @return array{id: int, name: string, slug: string, type: string}|null
     */
    private function hubPayload(?Hub $hub): ?array
    {
        if (! $hub) {
            return null;
        }

        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'slug' => $hub->slug,
            'type' => $hub->type,
        ];
    }
}
