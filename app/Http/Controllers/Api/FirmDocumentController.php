<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\Firm;
use App\Models\FirmDocument;
use App\Models\FirmDocumentMemberRight;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\FirmDocumentAccessService;
use App\Services\FirmDocumentService;
use App\Services\WhiteLabelFirmDocumentService;
use App\Support\ComplianceSupportingFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FirmDocumentController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function __construct(
        private readonly FirmDocumentService $documents,
        private readonly FirmDocumentAccessService $access,
        private readonly WhiteLabelFirmDocumentService $whiteLabelDocuments,
        private readonly ActingHubService $actingHubs,
    ) {}

    public function myRights(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->rightsHub($request, $user);

        return response()->json([
            'rights' => $this->access->effectiveRightsSummary($user, $hub),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'firm_id' => ['required', 'integer', 'min:1'],
                'scope' => ['sometimes', 'string', 'in:active,archived,all'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'],
            ]);

            try {
                $payload = $this->whiteLabelDocuments->index(
                    $hub,
                    $user,
                    (int) $validated['firm_id'],
                    $validated['scope'] ?? 'active',
                    (int) ($validated['per_page'] ?? 25),
                    (int) ($validated['page'] ?? $request->input('page', 1)),
                );
            } catch (InvalidArgumentException $e) {
                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json(array_merge($payload, [
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        $validated = $request->validate([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
            'scope' => ['sometimes', 'string', 'in:active,archived,all'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $hub = $this->rightsHub($request, $user);
        $scope = $validated['scope'] ?? 'active';
        $perPage = (int) ($validated['per_page'] ?? 25);

        $paginator = $this->documents->paginate($user, $firm, $scope, $perPage, $hub);

        return response()->json([
            'firm' => $firm->load('headUser:id,name,email')->toApiArray(),
            'rights' => [
                'can_add' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_ADD, $hub),
                'can_view' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_VIEW, $hub),
                'can_delete' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_DELETE, $hub),
                'can_archive' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_ARCHIVE, $hub),
                'can_manage_member_rights' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS, $hub),
                'is_firm_head' => $this->access->isHeadOfFirm($user, $firm),
                'functionality_enabled' => $this->access->functionalityEnabled($hub),
            ],
            'documents' => collect($paginator->items())
                ->map(fn (FirmDocument $doc) => $doc->toApiArray())
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'acting_on_white_label' => false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate(array_merge([
                'firm_id' => ['required', 'integer', 'min:1'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
            ], ComplianceSupportingFiles::optionalUploadRules('attachments')));

            $files = ComplianceSupportingFiles::fromRequest($request, 'attachments');

            try {
                $document = $this->whiteLabelDocuments->create(
                    $hub,
                    $user,
                    (int) $validated['firm_id'],
                    trim($validated['title']),
                    isset($validated['description']) ? trim((string) $validated['description']) : null,
                    $files,
                    $request,
                );
            } catch (InvalidArgumentException $e) {
                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'message' => 'Document uploaded successfully.',
                'document' => $document,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ], 201);
        }

        $validated = $request->validate(array_merge([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], ComplianceSupportingFiles::optionalUploadRules('attachments')));

        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $hub = $this->rightsHub($request, $user);
        $files = ComplianceSupportingFiles::fromRequest($request, 'attachments');

        $document = $this->documents->create(
            $user,
            $firm,
            trim($validated['title']),
            isset($validated['description']) ? trim((string) $validated['description']) : null,
            $files,
            $request,
            $hub,
        );

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document->toApiArray(),
            'acting_on_white_label' => false,
        ], 201);
    }

    public function destroy(Request $request, int $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $this->whiteLabelDocuments->delete($hub, $user, $document, $request);
            } catch (InvalidArgumentException $e) {
                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'message' => 'Document deleted successfully.',
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = FirmDocument::query()->with('attachments')->findOrFail($document);
        $this->documents->delete($user, $model, $request, $this->rightsHub($request, $user));

        return response()->json(['message' => 'Document deleted successfully.']);
    }

    public function archive(Request $request, int $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $updated = $this->whiteLabelDocuments->archive($hub, $user, $document, true, $request);
            } catch (InvalidArgumentException $e) {
                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'message' => 'Document archived successfully.',
                'document' => $updated,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = FirmDocument::query()->findOrFail($document);
        $updated = $this->documents->archive($user, $model, true, $request, $this->rightsHub($request, $user));

        return response()->json([
            'message' => 'Document archived successfully.',
            'document' => $updated->toApiArray(),
        ]);
    }

    public function unarchive(Request $request, int $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $updated = $this->whiteLabelDocuments->archive($hub, $user, $document, false, $request);
            } catch (InvalidArgumentException $e) {
                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'message' => 'Document unarchived successfully.',
                'document' => $updated,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = FirmDocument::query()->findOrFail($document);
        $updated = $this->documents->archive($user, $model, false, $request, $this->rightsHub($request, $user));

        return response()->json([
            'message' => 'Document unarchived successfully.',
            'document' => $updated->toApiArray(),
        ]);
    }

    public function memberRights(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'firm_id' => ['required', 'integer', 'min:1'],
            ]);

            try {
                $payload = $this->whiteLabelDocuments->memberRights($hub, $user, (int) $validated['firm_id']);
            } catch (InvalidArgumentException $e) {
                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json(array_merge($payload, [
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        $validated = $request->validate([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
        ]);
        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $hub = $this->rightsHub($request, $user);
        $rows = $this->documents->listMemberRights($user, $firm, $hub);

        $members = User::query()
            ->where('firm_id', $firm->id)
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'firm_id']);

        $byUser = collect($rows)->keyBy(fn (FirmDocumentMemberRight $r) => (int) $r->user_id);

        return response()->json([
            'firm' => $firm->load('headUser:id,name,email')->toApiArray(),
            'members' => $members->map(function (User $member) use ($firm, $byUser) {
                $grant = $byUser->get((int) $member->id);
                $isHead = $firm->head_user_id && (int) $firm->head_user_id === (int) $member->id;

                return [
                    'id' => (int) $member->id,
                    'name' => (string) $member->name,
                    'email' => (string) $member->email,
                    'is_firm_head' => $isHead,
                    'can_add' => $isHead ? true : (bool) ($grant?->can_add),
                    'can_view' => $isHead ? true : (bool) ($grant?->can_view),
                    'can_delete' => $isHead ? true : (bool) ($grant?->can_delete),
                    'can_archive' => $isHead ? true : (bool) ($grant?->can_archive),
                ];
            })->values(),
            'acting_on_white_label' => false,
        ]);
    }

    public function setMemberRights(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'firm_id' => ['required', 'integer', 'min:1'],
                'user_id' => ['required', 'integer', 'min:1'],
                'can_add' => ['sometimes', 'boolean'],
                'can_view' => ['sometimes', 'boolean'],
                'can_delete' => ['sometimes', 'boolean'],
                'can_archive' => ['sometimes', 'boolean'],
            ]);

            try {
                $rights = $this->whiteLabelDocuments->setMemberRights(
                    $hub,
                    $user,
                    (int) $validated['firm_id'],
                    (int) $validated['user_id'],
                    $validated,
                    $request,
                );
            } catch (InvalidArgumentException $e) {
                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'message' => 'Member document rights updated.',
                'rights' => $rights,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $validated = $request->validate([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'can_add' => ['sometimes', 'boolean'],
            'can_view' => ['sometimes', 'boolean'],
            'can_delete' => ['sometimes', 'boolean'],
            'can_archive' => ['sometimes', 'boolean'],
        ]);

        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $member = User::query()->findOrFail((int) $validated['user_id']);
        $hub = $this->rightsHub($request, $user);

        $row = $this->documents->setMemberRights($user, $firm, $member, $validated, $request, $hub);

        return response()->json([
            'message' => 'Member document rights updated.',
            'rights' => $row->relationLoaded('user') || $row->exists
                ? array_merge($row->toApiArray(), [
                    'user' => [
                        'id' => (int) $member->id,
                        'name' => (string) $member->name,
                        'email' => (string) $member->email,
                    ],
                ])
                : [
                    'firm_id' => (int) $firm->id,
                    'user_id' => (int) $member->id,
                    'can_add' => false,
                    'can_view' => false,
                    'can_delete' => false,
                    'can_archive' => false,
                    'user' => [
                        'id' => (int) $member->id,
                        'name' => (string) $member->name,
                        'email' => (string) $member->email,
                    ],
                ],
            'acting_on_white_label' => false,
        ]);
    }

    private function resolveFirm(User $user, mixed $firmId): Firm
    {
        if ($firmId) {
            return Firm::query()->findOrFail((int) $firmId);
        }

        $headedId = $this->access->headedFirmIdFor($user);
        if ($headedId) {
            return Firm::query()->findOrFail($headedId);
        }

        if (! $user->firm_id) {
            abort(422, 'firm_id is required when you are not assigned to a firm.');
        }

        return Firm::query()->findOrFail((int) $user->firm_id);
    }

    private function rightsHub(Request $request, User $user): \App\Models\Hub
    {
        return $this->actingWhiteLabelHub($request)
            ?? $this->actingHubs->actingHub($user);
    }
}
