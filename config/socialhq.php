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
    | approved_providers: the only provider entries ever called; any other SDK
    | request is refused before it is sent (ApprovedProvidersGuard). None of
    | them may train on prompts or let people read them (never the Gemini free
    | tier; Mistral only with "Anonymous improvement data" off in its console),
    | so a key for one can sit in .env without being used. LiteLLM's own
    | providers are approved in its config.yaml, reviewed in git.
    |
    | litellm.tasks (SOCIALHQ_AI_GATEWAY_TASKS): the tasks the gateway serves.
    | image and embed have only paid Gemini there; list them once its key is set.
    |
    | models_without_temperature: models that reject the `temperature`
    | parameter, as regular expressions. The agents send their preferred
    | temperature to every other model. The Claude 5 family rejects it
    | (claude-sonnet-5-5, claude-opus-5-5, ...); Claude 4.x accepts it. Through
    | LiteLLM, drop_params removes it for models that don't take it.
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
            // Tasks the gateway serves. image and embed have only paid Gemini in
            // LiteLLM: add them once GEMINI_PAID_API_KEY is set there.
            'tasks' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('SOCIALHQ_AI_GATEWAY_TASKS', 'write,structured,vision')),
            ))),
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
        'models_without_temperature' => [
            '/^claude-[a-z]+-5(-\d{1,2})?(-\d{8})?$/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | Stricter than TryPost's per-member flag (App\SocialHq\Approval\ApprovalRules).
    | members_always_reviewed: every member who is not an admin needs approval.
    | agent_posts_reviewed: posts written through an API key or MCP grant need
    | approval even with an admin's key, and agents cannot approve.
    |
    */

    'approvals' => [
        'members_always_reviewed' => (bool) env('SOCIALHQ_MEMBERS_ALWAYS_REVIEWED', true),
        'agent_posts_reviewed' => (bool) env('SOCIALHQ_AGENT_POSTS_REVIEWED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Brand knowledge
    |--------------------------------------------------------------------------
    |
    | Each workspace's brand profile and knowledge entries, read by the
    | caption assistant and the idea generator. prompt_characters caps what
    | goes into one prompt: the profile (at most half, shortened when longer),
    | then the pinned and newest entries marked for AI that fit. It is counted
    | by BrandKnowledge::size(), where characters outside Latin-1 (Devanagari,
    | emoji) count double. 6000 is about 1,500 tokens of English; Nepali text
    | costs more tokens per character, so the same budget holds less of it.
    | A rough guard for free models' limits, not a token count.
    |
    | When the caller says what the prompt is about (the focus), the entries
    | and document excerpts that match it go in right after the pinned ones,
    | and the budget is prompt_characters_focused (8000, about 2,000 tokens):
    | the extra room holds text picked for the request, not filler. Matches
    | scoring under min_relevance of the best one are left out.
    |
    | Documents: PDF, Word, PowerPoint, text and Markdown files, read into
    | excerpts of about chunk_characters. Zip parts are capped before reading
    | (max_expanded_bytes in all, max_part_bytes per part) against zip bombs.
    |
    */

    'knowledge' => [
        'prompt_characters' => (int) env('SOCIALHQ_KNOWLEDGE_PROMPT_CHARACTERS', 6000),
        'prompt_characters_focused' => (int) env('SOCIALHQ_KNOWLEDGE_PROMPT_CHARACTERS_FOCUSED', 8000),
        'max_entries' => (int) env('SOCIALHQ_KNOWLEDGE_MAX_ENTRIES', 300),
        'search' => [
            'min_relevance' => (float) env('SOCIALHQ_KNOWLEDGE_MIN_RELEVANCE', 0.25),
        ],
        'documents' => [
            'max_documents' => (int) env('SOCIALHQ_KNOWLEDGE_MAX_DOCUMENTS', 30),
            'max_kilobytes' => (int) env('SOCIALHQ_KNOWLEDGE_DOCUMENT_KILOBYTES', 10240),
            'max_characters' => (int) env('SOCIALHQ_KNOWLEDGE_DOCUMENT_CHARACTERS', 300000),
            'chunk_characters' => 900,
            'max_chunks' => 400,
            'prompt_excerpts' => (int) env('SOCIALHQ_KNOWLEDGE_PROMPT_EXCERPTS', 6),
            'max_expanded_bytes' => 100 * 1024 * 1024,
            'max_part_bytes' => 20 * 1024 * 1024,
            'pdf_decode_bytes' => 32 * 1024 * 1024,
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Sign-up
    |--------------------------------------------------------------------------
    |
    | Self-hosted TryPost only registers people with an invite. Social HQ also
    | lets people sign up whose email is listed here, either exactly or by
    | domain (comma separated; public mail domains such as gmail.com are
    | ignored). They join the account of account_owner (matched by email,
    | whatever its case) without any workspace, and wait until an admin
    | approves them. Blank: the oldest account that owns a workspace (every
    | invited person holds an empty account of their own until they accept,
    | so there is rarely just one). When the lists are set but no account is
    | found, sign-up stays closed and a warning is logged (once an hour).
    | Both lists empty: invites only, as upstream.
    |
    */

    'signup' => [
        'domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('SOCIALHQ_SIGNUP_DOMAINS', ''))))),
        'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('SOCIALHQ_SIGNUP_EMAILS', ''))))),
        'account_owner' => env('SOCIALHQ_SIGNUP_ACCOUNT_OWNER'),
    ],

];
