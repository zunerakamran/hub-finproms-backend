<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\Firm;
use App\Models\User;
use App\Services\WhiteLabelFirmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FirmController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function __construct(
        private readonly WhiteLabelFirmService $whiteLabelFirms
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $listed = $this->whiteLabelFirms->list($hub);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(array_merge($listed, [
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        $counts = User::query()
            ->whereNotNull('firm_id')
            ->selectRaw('firm_id, COUNT(*) as users_count')
            ->groupBy('firm_id')
            ->pluck('users_count', 'firm_id');

        $firms = Firm::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Firm $firm) => [
                'id' => $firm->id,
                'name' => $firm->name,
                'users_count' => (int) ($counts[$firm->id] ?? 0),
            ])
            ->values();

        return response()->json([
            'firms' => $firms,
            'acting_on_white_label' => false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
            ]);

            try {
                $firm = $this->whiteLabelFirms->create($hub, $validated);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'message' => 'Firm created successfully.',
                'firm' => $firm,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ], 201);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:firms,name'],
        ]);

        $firm = Firm::query()->create([
            'name' => trim($validated['name']),
        ]);

        return response()->json([
            'message' => 'Firm created successfully.',
            'firm' => $firm,
            'acting_on_white_label' => false,
        ], 201);
    }

    public function update(Request $request, int $firm): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
            ]);

            try {
                $updated = $this->whiteLabelFirms->update($hub, $firm, $validated);
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            return response()->json([
                'message' => 'Firm updated successfully.',
                'firm' => $updated,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = Firm::query()->findOrFail($firm);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('firms', 'name')->ignore($model->id),
            ],
        ]);

        $model->update([
            'name' => trim($validated['name']),
        ]);

        return response()->json([
            'message' => 'Firm updated successfully.',
            'firm' => $model->fresh(),
            'acting_on_white_label' => false,
        ]);
    }

    public function destroy(Request $request, int $firm): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $this->whiteLabelFirms->delete($hub, $firm);
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            return response()->json([
                'message' => 'Firm deleted successfully.',
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = Firm::query()->findOrFail($firm);
        $inUse = User::query()->where('firm_id', $model->id)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a firm that is assigned to users.',
            ], 422);
        }

        $model->delete();

        return response()->json([
            'message' => 'Firm deleted successfully.',
            'acting_on_white_label' => false,
        ]);
    }
}
