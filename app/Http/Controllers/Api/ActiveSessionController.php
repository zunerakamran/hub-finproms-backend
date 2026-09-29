<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActiveSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActiveSessionController extends Controller
{
    public function __construct(
        private readonly ActiveSessionService $sessions
    ) {}

    /**
     * List users who currently have active Sanctum sessions on the target hub.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $payload = $this->sessions->listForActor(
                $actor,
                $this->sessions->currentTokenId($actor)
            );
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        return response()->json($payload);
    }

    /**
     * End all sessions for a user on the target hub.
     */
    public function forceLogout(Request $request, int $userId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $result = $this->sessions->forceLogout(
                $actor,
                $userId,
                $this->sessions->currentTokenId($actor)
            );
        } catch (InvalidArgumentException $e) {
            throw new HttpException(404, $e->getMessage());
        }

        return response()->json($result);
    }
}
