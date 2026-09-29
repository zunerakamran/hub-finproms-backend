<?php

return [

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
