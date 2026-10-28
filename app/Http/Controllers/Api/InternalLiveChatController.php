<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\HubInternalAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content hubs call Central to list Central Hub users for the Live Chat
 * contact picker (Shared / White-label access page).
 *
 * Auth: same hub identity as backups (X-Hub-Backup-Key + X-Hub-Slug).
 */
class InternalLiveChatController extends Controller
{
    public function centralUsers(Request $request): JsonResponse
    {
        if (! config('hub.is_control_plane')) {
            return response()->json(['message' => 'Not the Central Hub Controller.'], 404);
        }

        $slug = (string) ($request->header('X-Hub-Slug') ?: $request->query('hub_slug', ''));
        if (! HubInternalAuth::authorizeIncomingBackup($request, $slug)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $users = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->filter(function (User $u) {
                if (property_exists($u, 'is_discontinued') || isset($u->is_discontinued)) {
                    return ! (bool) $u->is_discontinued;
                }

                return true;
            })
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'role_label' => $u->role_label ?? null,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $users]);
    }
}
