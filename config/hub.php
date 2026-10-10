<?php

$versionFile = is_file(base_path('VERSION'))
    ? trim((string) file_get_contents(base_path('VERSION')))
    : '';
$markerPath = storage_path('app/private/code-update-version.json');
$markerVersion = '';
$markerFrontend = '';
if (is_file($markerPath)) {
    $marker = json_decode((string) file_get_contents($markerPath), true);
    if (is_array($marker)) {
        $markerVersion = trim((string) ($marker['version'] ?? ''));
        $markerFrontend = trim((string) ($marker['frontend_version'] ?? ''));
    }
}
$defaultVersion = $markerVersion !== '' ? $markerVersion
    : ($versionFile !== '' ? $versionFile : '0.0.0');
$defaultFrontend = $markerFrontend !== '' ? $markerFrontend : $defaultVersion;

return [

    /*
    |--------------------------------------------------------------------------
    | Deployed code version (this instance)
    |--------------------------------------------------------------------------
    |
    | Bump VERSION in the repo (or set APP_VERSION / FRONTEND_VERSION in .env)
    | whenever you ship a release. Central Hub polls GET /api/version on each
    | hub to track who is on the latest release. Phase 2 apply also writes
    | storage/app/private/code-update-version.json.
    |
    */
    'version' => env('APP_VERSION', $defaultVersion),

    'frontend_version' => env('FRONTEND_VERSION', env('APP_VERSION', $defaultFrontend)),

    /*
    | Absolute or base_path-relative folder where frontend dist zip extracts
    | on this server (e.g. ../public_html or /home/user/public_html).
    | Per-hub override: hubs.code_frontend_path on Central registry.
    */
    'code_update_frontend_path' => env('CODE_UPDATE_FRONTEND_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Current hub slug
    |--------------------------------------------------------------------------
    |
    | Each deploy resolves "this hub" by slug. Slugs must be unique in the
    | Central Hub registry. Examples:
    |   - Central Hub Controller: HUB_SLUG=central
    |   - Shared content hub(s):  HUB_SLUG=shared  (or shared-uk, catalog-2, …)
    |   - White-labelled hub:     HUB_SLUG=<client-slug>
    |
    */
    'current_slug' => env('HUB_SLUG', 'shared'),

    /*
    |--------------------------------------------------------------------------
    | Hub type for this deploy
    |--------------------------------------------------------------------------
    |
    | Optional override used by seeders / firstOrCreate when the hubs row is
    | created. Allowed: central | shared | white_label
    | If empty, inferred from HUB_SLUG (central → central, else white_label
    | unless HUB_TYPE is set). Prefer setting HUB_TYPE=shared for Shared
    | content hubs whose slug is not literally "shared".
    |
    */
    'type' => env('HUB_TYPE'),

    /*
    |--------------------------------------------------------------------------
    | Control plane flag
    |--------------------------------------------------------------------------
    |
    | True ONLY on the Central Hub Controller deploy.
    | Defaults to true when HUB_SLUG=central (or HUB_TYPE=central).
    | Shared and White-label content deploys must leave this false — they are
    | controlled remotely FROM Central and must not host the hub registry.
    |
    | Legacy escape hatch: set HUB_IS_CONTROL_PLANE=true on an old Shared deploy
    | that still hosts Power Admin until Central is live, then turn it off.
    |
    */
    'is_control_plane' => filter_var(
        env(
            'HUB_IS_CONTROL_PLANE',
            (env('HUB_SLUG', 'shared') === 'central' || env('HUB_TYPE') === 'central')
                ? 'true'
                : 'false'
        ),
        FILTER_VALIDATE_BOOLEAN
    ),

];
