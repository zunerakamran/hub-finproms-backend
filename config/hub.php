<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Current hub slug
    |--------------------------------------------------------------------------
    |
    | Each deploy (shared or white-labelled) resolves "this hub" by slug.
    | Shared hub defaults to "shared". White-labelled deploys set HUB_SLUG
    | to their hub slug (e.g. "acme-advisors").
    |
    */
    'current_slug' => env('HUB_SLUG', 'shared'),

];
