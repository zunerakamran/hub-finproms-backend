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

            try {
                $payload['categories'] = $this->whiteLabelDocuments->listCategories($hub);
            } catch (InvalidArgumentException) {
                $payload['categories'] = [];
            }

            return response()->json(array_merge($payload, [
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        $validated = $request->validate([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
            'scope' => ['sometimes', 'string', 'in:active,archived,all'],
        ]);

        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $hub = $this->rightsHub($request, $user);
        $scope = $validated['scope'] ?? 'active';
        $summary = $this->access->effectiveRightsSummary($user, $hub);

        $library = $this->documents->library($user, $firm, $scope, $hub);

        $categories = \App\Models\FirmDocumentCategory::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (\App\Models\FirmDocumentCategory $category) => $category->toApiArray())
            ->values()
            ->all();

        return response()->json([
            'firm' => $firm->load('headUser:id,name,email')->toApiArray(),
            'rights' => [
                'can_add' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_ADD, $hub),
                'can_view' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_VIEW, $hub),
                'can_delete' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_DELETE, $hub),
                'can_archive' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_ARCHIVE, $hub),
                'can_manage_member_rights' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS, $hub),
                'can_manage_categories' => (bool) ($summary['can_manage_categories'] ?? false),
                'is_firm_head' => $this->access->isHeadOfFirm($user, $firm),
                'functionality_enabled' => $this->access->functionalityEnabled($hub),
            ],
            'folders' => $library['folders'],
            'documents' => $library['documents'],
            'unfiled_documents' => $library['unfiled_documents'],
            'categories' => $categories,
            'acting_on_white_label' => false,
        ]);
    }

    public function folders(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
        ]);
        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $hub = $this->rightsHub($request, $user);

        return response()->json([
            'folders' => $this->documents->listFolders($user, $firm, $hub),
        ]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:firm_document_folders,id'],
        ]);

        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $hub = $this->rightsHub($request, $user);

        $folder = $this->documents->createFolder(
            $user,
            $firm,
            trim($validated['name']),
            isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            $request,
            $hub,
        );

        return response()->json([
            'message' => 'Folder created successfully.',
            'folder' => $folder->toApiArray(false),
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            $uploadRules = ComplianceSupportingFiles::optionalUploadRules('attachments');
            $uploadRules['attachments'] = ['nullable', 'array', 'max:1'];

            $validated = $request->validate(array_merge([
                'firm_id' => ['required', 'integer', 'min:1'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
            ], $uploadRules));

            $files = array_slice(ComplianceSupportingFiles::fromRequest($request, 'attachments'), 0, 1);

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

        $uploadRules = ComplianceSupportingFiles::optionalUploadRules('attachments');
        $uploadRules['attachments'] = ['nullable', 'array', 'max:1'];

        $validated = $request->validate(array_merge([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'folder_id' => ['nullable', 'integer', 'exists:firm_document_folders,id'],
            'folder_name' => ['nullable', 'string', 'max:255'],
            'parent_folder_id' => ['nullable', 'integer', 'exists:firm_document_folders,id'],
            'category_id' => ['nullable', 'integer', 'exists:firm_document_categories,id'],
        ], $uploadRules));

        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $hub = $this->rightsHub($request, $user);
        $files = array_slice(ComplianceSupportingFiles::fromRequest($request, 'attachments'), 0, 1);

        $document = $this->documents->create(
            $user,
            $firm,
            trim($validated['title']),
            isset($validated['description']) ? trim((string) $validated['description']) : null,
            $files,
            [
                'folder_id' => $validated['folder_id'] ?? null,
                'folder_name' => $validated['folder_name'] ?? null,
                'parent_folder_id' => $validated['parent_folder_id'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
            ],
            $request,
            $hub,
        );

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document->toApiArray(
                $this->access->documentRightsFor($user, $firm, $document, $hub)
            ),
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
        $firm = $model->firm ?? Firm::query()->findOrFail($model->firm_id);
        $hub = $this->rightsHub($request, $user);
        $updated = $this->documents->archive($user, $model, true, $request, $hub);

        return response()->json([
            'message' => 'Document archived successfully.',
            'document' => $updated->toApiArray(
                $this->access->documentRightsFor($user, $firm, $updated, $hub)
            ),
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
        $firm = $model->firm ?? Firm::query()->findOrFail($model->firm_id);
        $hub = $this->rightsHub($request, $user);
        $updated = $this->documents->archive($user, $model, false, $request, $hub);

        return response()->json([
            'message' => 'Document unarchived successfully.',
            'document' => $updated->toApiArray(
                $this->access->documentRightsFor($user, $firm, $updated, $hub)
            ),
        ]);
    }

    public function documentMemberRights(Request $request, int $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $model = FirmDocument::query()->findOrFail($document);
        $firm = $model->firm ?? Firm::query()->findOrFail($model->firm_id);
        $hub = $this->rightsHub($request, $user);

        $members = $this->documents->listDocumentMemberRights($user, $firm, $model, $hub);

        return response()->json([
            'firm' => $firm->load('headUser:id,name,email')->toApiArray(),
            'document' => [
                'id' => (int) $model->id,
                'title' => (string) $model->title,
            ],
            'members' => $members,
            'acting_on_white_label' => false,
        ]);
    }

    public function setDocumentMemberRights(Request $request, int $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'can_add' => ['sometimes', 'boolean'],
            'can_view' => ['sometimes', 'boolean'],
            'can_delete' => ['sometimes', 'boolean'],
            'can_archive' => ['sometimes', 'boolean'],
        ]);

        $model = FirmDocument::query()->findOrFail($document);
        $firm = $model->firm ?? Firm::query()->findOrFail($model->firm_id);
        $member = User::query()->findOrFail((int) $validated['user_id']);
        $hub = $this->rightsHub($request, $user);

        $row = $this->documents->setDocumentMemberRights(
            $user,
            $firm,
            $model,
            $member,
            $validated,
            $request,
            $hub,
        );

        return response()->json([
            'message' => 'Document access rights updated.',
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
                    'firm_document_id' => (int) $model->id,
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
