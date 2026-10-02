<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Firm;
use App\Services\FirmHeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirmHeadController extends Controller
{
    public function __construct(
        private readonly FirmHeadService $heads,
    ) {}

    public function members(Request $request, int $firm): JsonResponse
    {
        $model = Firm::query()->findOrFail($firm);

        return response()->json([
            'firm' => $model->load('headUser:id,name,email')->toApiArray(),
            'members' => $this->heads->eligibleMembers($model),
        ]);
    }

    public function assign(Request $request, int $firm): JsonResponse
    {
        $model = Firm::query()->findOrFail($firm);

        if (! $request->exists('head_user_id')) {
            return response()->json([
                'message' => 'head_user_id is required (send null to clear).',
            ], 422);
        }

        $validated = $request->validate([
            'head_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $headUserId = $validated['head_user_id'] !== null
            ? (int) $validated['head_user_id']
            : null;

        $updated = $this->heads->assign(
            $model,
            $headUserId,
            $request->user(),
            $request,
        );

        $message = $updated->head_user_id
            ? 'Head of Firm updated successfully.'
            : 'Head of Firm cleared successfully.';

        return response()->json([
            'message' => $message,
            'firm' => $updated->toApiArray(),
        ]);
    }
}
