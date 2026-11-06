<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Source code
    |--------------------------------------------------------------------------
    |
    | Social HQ is a modified TryPost, licensed under the AGPL-3.0. Section 13
    | requires offering the source of the running version to its users; the
    | app links to this repository.
    |
    */

    'source_url' => env('SOCIALHQ_SOURCE_URL', 'https://github.com/yoddha-social/socialhq'),

    /*
    |--------------------------------------------------------------------------
    | AI models without a temperature setting
    |--------------------------------------------------------------------------
    |
    | Models that reject the `temperature` parameter, as regular expressions.
    | TryPost's AI agents send their preferred temperature to every other model.
    | The Claude 5 family rejects it (claude-sonnet-5-5, claude-opus-5-5, ...);
    | Claude 4.x accepts it (claude-sonnet-4-5, claude-sonnet-4-5-20250929).
    |
    */

    'ai' => [
        'models_without_temperature' => [
            '/^claude-[a-z]+-5(-\d{1,2})?(-\d{8})?$/',
        ],
    ],

];
