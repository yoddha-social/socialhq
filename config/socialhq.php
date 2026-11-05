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
    | AI graphics
    |--------------------------------------------------------------------------
    |
    | "Generate with AI" in the media menu of ideas and posts: a still image
    | from a text prompt plus optional reference images (no video). Needs an
    | image provider (gemini, openai or xai in config/ai.php) with a key.
    |
    | The vision check reviews the prompt and reference images before
    | generating, and the result after, with the default text provider (it
    | must accept images). It fails closed: when the check cannot run,
    | nothing is generated.
    |
    */

    'ai_graphics' => [
        'enabled' => (bool) env('SOCIALHQ_AI_GRAPHICS', true),
        'provider' => env('SOCIALHQ_AI_GRAPHICS_PROVIDER', 'gemini'),
        'model' => env('SOCIALHQ_AI_GRAPHICS_MODEL'),
        'vision_check' => (bool) env('SOCIALHQ_AI_GRAPHICS_VISION_CHECK', true),
        'vision_provider' => env('SOCIALHQ_AI_GRAPHICS_VISION_PROVIDER'),
        'vision_model' => env('SOCIALHQ_AI_GRAPHICS_VISION_MODEL'),
        // How long the image model may take. The queue worker allows 630 s.
        'timeout_seconds' => (int) env('SOCIALHQ_AI_GRAPHICS_TIMEOUT', 150),
        'max_reference_images' => 3,
        'max_reference_kilobytes' => 10240,
        'per_user_per_hour' => (int) env('SOCIALHQ_AI_GRAPHICS_PER_USER_PER_HOUR', 20),
        'per_workspace_per_day' => (int) env('SOCIALHQ_AI_GRAPHICS_PER_WORKSPACE_PER_DAY', 100),
    ],

];
