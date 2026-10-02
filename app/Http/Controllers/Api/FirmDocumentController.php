<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Firm;
use App\Models\FirmDocument;
use App\Models\FirmDocumentMemberRight;
use App\Models\User;
use App\Services\FirmDocumentAccessService;
use App\Services\FirmDocumentService;
use App\Support\ComplianceSupportingFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirmDocumentController extends Controller
{
    public function __construct(
        private readonly FirmDocumentService $documents,
        private readonly FirmDocumentAccessService $access,
    ) {}

    public function myRights(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'rights' => $this->access->effectiveRightsSummary($user),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
            'scope' => ['sometimes', 'string', 'in:active,archived,all'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $scope = $validated['scope'] ?? 'active';
        $perPage = (int) ($validated['per_page'] ?? 25);

        $paginator = $this->documents->paginate($user, $firm, $scope, $perPage);

        return response()->json([
            'firm' => $firm->load('headUser:id,name,email')->toApiArray(),
            'rights' => [
                'can_add' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_ADD),
                'can_view' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_VIEW),
                'can_delete' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_DELETE),
                'can_archive' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_ARCHIVE),
                'can_manage_member_rights' => $this->access->can($user, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS),
                'is_firm_head' => $this->access->isHeadOfFirm($user, $firm),
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
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(array_merge([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], ComplianceSupportingFiles::optionalUploadRules('attachments')));

        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $files = ComplianceSupportingFiles::fromRequest($request);

        $document = $this->documents->create(
            $user,
            $firm,
            trim($validated['title']),
            isset($validated['description']) ? trim((string) $validated['description']) : null,
            $files,
            $request,
        );

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document->toApiArray(),
        ], 201);
    }

    public function destroy(Request $request, int $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = FirmDocument::query()->with('attachments')->findOrFail($document);
        $this->documents->delete($user, $model, $request);

        return response()->json(['message' => 'Document deleted successfully.']);
    }

    public function archive(Request $request, int $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = FirmDocument::query()->findOrFail($document);
        $updated = $this->documents->archive($user, $model, true, $request);

        return response()->json([
            'message' => 'Document archived successfully.',
            'document' => $updated->toApiArray(),
        ]);
    }

    public function unarchive(Request $request, int $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = FirmDocument::query()->findOrFail($document);
        $updated = $this->documents->archive($user, $model, false, $request);

        return response()->json([
            'message' => 'Document unarchived successfully.',
            'document' => $updated->toApiArray(),
        ]);
    }

    public function memberRights(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'firm_id' => ['sometimes', 'nullable', 'integer', 'exists:firms,id'],
        ]);
        $firm = $this->resolveFirm($user, $validated['firm_id'] ?? null);
        $rows = $this->documents->listMemberRights($user, $firm);

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
        ]);
    }

    public function setMemberRights(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
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

        $row = $this->documents->setMemberRights($user, $firm, $member, $validated, $request);

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
        ]);
    }

    private function resolveFirm(User $user, mixed $firmId): Firm
    {
        if ($firmId) {
            return Firm::query()->findOrFail((int) $firmId);
        }

        if (! $user->firm_id) {
            abort(422, 'firm_id is required when you are not assigned to a firm.');
        }

        return Firm::query()->findOrFail((int) $user->firm_id);
    }
}
