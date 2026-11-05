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
    | AI routing
    |--------------------------------------------------------------------------
    |
    | Every AI call names a task (App\SocialHq\Ai\AiTask: write, structured,
    | vision, image, embed) and AiRouter picks the providers, in order.
    |
    | gateway "litellm": each task goes to its LiteLLM model group (shq-write,
    | ...), and LiteLLM tries the free providers first, falls back to paid
    | Gemini and holds the monthly budget (socialhq-deploy/litellm/config.yaml).
    | gateway "direct": the SDK calls providers with their own keys from
    | config/ai.php. For development, tests, or running without LiteLLM.
    |
    | A route (SOCIALHQ_AI_ROUTE_<TASK>) overrides either: "provider:model"
    | targets, comma separated, tried in order on rate limits and overload.
    | Without a model the provider's default model is used.
    |
    | approved_providers: the only provider entries ever called. None of them
    | may train on prompts or let people read them (no Gemini or Mistral free
    | tier), so a key for one can sit in .env without being used. LiteLLM's
    | own providers are approved in its config.yaml, reviewed in git.
    |
    */

    'ai' => [
        'gateway' => env('SOCIALHQ_AI_GATEWAY', 'direct'),
        'litellm' => [
            'url' => env('LITELLM_URL', 'http://litellm:4000/v1'),
            // A LiteLLM virtual key: its budget caps paid spending.
            'key' => env('LITELLM_API_KEY'),
            // Unset: the embedding model's native size. Fixed once the knowledge base stores vectors.
            'embedding_dimensions' => env('LITELLM_EMBEDDING_DIMENSIONS'),
        ],
        'approved_providers' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('SOCIALHQ_AI_APPROVED_PROVIDERS', 'litellm,litellm-images')),
        ))),
        'routes' => [
            'write' => env('SOCIALHQ_AI_ROUTE_WRITE'),
            'structured' => env('SOCIALHQ_AI_ROUTE_STRUCTURED'),
            'vision' => env('SOCIALHQ_AI_ROUTE_VISION'),
            'image' => env('SOCIALHQ_AI_ROUTE_IMAGE'),
            'embed' => env('SOCIALHQ_AI_ROUTE_EMBED'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Brand knowledge
    |--------------------------------------------------------------------------
    |
    | Each workspace's brand profile and knowledge entries, read by the
    | caption assistant and the idea generator. prompt_characters caps what
    | goes into one prompt (about 1,500 tokens): the profile plus the newest
    | entries marked for AI that fit, small enough for free models' limits.
    |
    */

    'knowledge' => [
        'prompt_characters' => (int) env('SOCIALHQ_KNOWLEDGE_PROMPT_CHARACTERS', 6000),
        'max_entries' => (int) env('SOCIALHQ_KNOWLEDGE_MAX_ENTRIES', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI graphics
    |--------------------------------------------------------------------------
    |
    | "Generate with AI" in the media menu of ideas and posts: a still image
    | from a text prompt plus optional reference images (no video). Uses the
    | image task's route (see AI routing).
    |
    | The vision check reviews the prompt and reference images before
    | generating, and the result after, through the vision task. It fails
    | closed: when the check cannot run, nothing is generated.
    |
    */

    'ai_graphics' => [
        'enabled' => (bool) env('SOCIALHQ_AI_GRAPHICS', true),
        'vision_check' => (bool) env('SOCIALHQ_AI_GRAPHICS_VISION_CHECK', true),
        // How long the image model may take. The queue worker allows 630 s.
        'timeout_seconds' => (int) env('SOCIALHQ_AI_GRAPHICS_TIMEOUT', 150),
        'max_reference_images' => 3,
        'max_reference_kilobytes' => 10240,
        'per_user_per_hour' => (int) env('SOCIALHQ_AI_GRAPHICS_PER_USER_PER_HOUR', 20),
        'per_workspace_per_day' => (int) env('SOCIALHQ_AI_GRAPHICS_PER_WORKSPACE_PER_DAY', 100),
    ],

];
