<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActiveSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Current hub idle session timeout (minutes).
     */
    public function sessionSettings(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            return response()->json($this->sessions->sessionSettingsForActor($actor));
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    /**
     * Set hub idle session timeout (minutes). Applies to SPA cookies + Sanctum PATs.
     */
    public function updateSessionSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_lifetime_minutes' => [
                'required',
                'integer',
                'min:'.\App\Models\Hub::SESSION_LIFETIME_MIN_MINUTES,
                'max:'.\App\Models\Hub::SESSION_LIFETIME_MAX_MINUTES,
            ],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $result = $this->sessions->updateSessionSettings(
                $actor,
                (int) $validated['session_lifetime_minutes']
            );
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        // Local hub timeout change ends the actor’s own SPA cookie too.
        if (! empty($result['logged_out_self'])) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->json($result);
    }
}
