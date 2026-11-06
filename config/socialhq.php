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
            // LiteLLM's admin API (companies' teams, keys and provider keys).
            // Unset: the gateway's URL without /v1.
            'admin_url' => env('LITELLM_ADMIN_URL'),
            // A management key (socialhq-deploy/scripts/make-litellm-admin-key.sh):
            // a proxy-admin key limited to the routes the app needs. The master
            // key is read only in local development, as a fallback.
            'admin_key' => env('LITELLM_ADMIN_KEY') ?: (env('APP_ENV') === 'local' ? env('LITELLM_MASTER_KEY') : null),
            'admin_timeout' => (int) env('LITELLM_ADMIN_TIMEOUT', 20),
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
    | Company AI
    |--------------------------------------------------------------------------
    |
    | Each company brings its own AI provider keys (Settings › AI, account
    | owner only). A company is a LiteLLM team with its own virtual key and
    | budget; its keys are LiteLLM credentials and its deployments the model
    | groups shq-<task>@<team> (LiteLLM's team-scoped model names need an
    | Enterprise licence). The app keeps the provider, the last four
    | characters and the test status, never a provider key.
    |
    | providers: the catalogue. Only providers that neither train on prompts
    | nor let people read them (owner decision 2026-11-05). `confirm` names
    | a checkbox the owner must tick first (Mistral's training opt-out, Gemini
    | billing: the free tier trains on prompts). `tasks` maps each AI task to
    | the LiteLLM model it calls; a provider serves only the tasks it lists.
    | Embeddings take only the first provider in the order that has them
    | (vectors from two models can't be compared). OpenRouter is left out: a
    | key there reaches providers with other data rules.
    |
    | Copy (name, setup steps, data note) lives in lang/en/socialhq_ai.php.
    |
    */

    'company_ai' => [
        'budget_duration' => '30d',
        'max_providers' => 6,
        // Where LiteLLM reaches Workers AI for a company's Cloudflare account.
        'cloudflare_api_base' => env('SOCIALHQ_CLOUDFLARE_AI_API_BASE', 'https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run/'),
        'providers' => [
            'groq' => [
                'logo' => 'groq',
                'litellm_provider' => 'groq',
                'console_url' => 'https://console.groq.com/keys',
                'steps' => 3,
                'confirm' => null,
                'fields' => ['provider_key'],
                'params' => [],
                'tasks' => [
                    'write' => ['model' => 'groq/openai/gpt-oss-120b', 'timeout' => 20],
                    'structured' => ['model' => 'groq/openai/gpt-oss-120b', 'timeout' => 75],
                    'vision' => ['model' => 'groq/qwen/qwen3.8-27b', 'timeout' => 20],
                ],
            ],
            'cloudflare' => [
                'logo' => 'cloudflare',
                'litellm_provider' => 'cloudflare',
                'console_url' => 'https://dash.cloudflare.com/profile/api-tokens',
                'steps' => 3,
                'confirm' => null,
                'fields' => ['account_id', 'provider_key'],
                // Cloudflare's models answer 256 tokens unless asked for more.
                'params' => ['max_tokens' => 8192],
                'tasks' => [
                    'write' => ['model' => 'cloudflare/@cf/openai/gpt-oss-120b', 'timeout' => 20],
                    'structured' => ['model' => 'cloudflare/@cf/openai/gpt-oss-120b', 'timeout' => 75],
                    'vision' => ['model' => 'cloudflare/@cf/meta/llama-4-scout-17b-16e-instruct', 'timeout' => 20],
                ],
            ],
            'mistral' => [
                'logo' => 'mistral',
                'litellm_provider' => 'mistral',
                'console_url' => 'https://console.mistral.ai/api-keys',
                'steps' => 3,
                'confirm' => 'training_off',
                'fields' => ['provider_key'],
                'params' => [],
                'tasks' => [
                    'write' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 20],
                    'structured' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 75],
                    'vision' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 20],
                    'embed' => ['model' => 'mistral/mistral-embed', 'timeout' => 30],
                ],
            ],
            'gemini' => [
                'logo' => 'gemini',
                'litellm_provider' => 'gemini',
                'console_url' => 'https://aistudio.google.com/apikey',
                'steps' => 3,
                'confirm' => 'billing_on',
                'fields' => ['provider_key'],
                'params' => [],
                'tasks' => [
                    'write' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 30],
                    'structured' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 75],
                    'vision' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 30],
                    'image' => ['model' => 'gemini/gemini-3.1-flash-image', 'timeout' => 300],
                    'embed' => ['model' => 'gemini/gemini-embedding-001', 'timeout' => 30],
                ],
            ],
            'openai' => [
                'logo' => 'openai',
                'litellm_provider' => 'openai',
                'console_url' => 'https://platform.openai.com/api-keys',
                'steps' => 3,
                'confirm' => null,
                'fields' => ['provider_key'],
                'params' => [],
                'tasks' => [
                    'write' => ['model' => 'openai/gpt-5-mini', 'timeout' => 30],
                    'structured' => ['model' => 'openai/gpt-5-mini', 'timeout' => 75],
                    'vision' => ['model' => 'openai/gpt-5-mini', 'timeout' => 30],
                    'image' => ['model' => 'openai/gpt-image-1', 'timeout' => 300],
                    'embed' => ['model' => 'openai/text-embedding-3-small', 'timeout' => 30],
                ],
            ],
            'anthropic' => [
                'logo' => 'anthropic',
                'litellm_provider' => 'anthropic',
                'console_url' => 'https://console.anthropic.com/settings/keys',
                'steps' => 3,
                'confirm' => null,
                'fields' => ['provider_key'],
                'params' => [],
                'tasks' => [
                    'write' => ['model' => 'anthropic/claude-haiku-4-5', 'timeout' => 30],
                    'structured' => ['model' => 'anthropic/claude-haiku-4-5', 'timeout' => 75],
                    'vision' => ['model' => 'anthropic/claude-haiku-4-5', 'timeout' => 30],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Secrets
    |--------------------------------------------------------------------------
    |
    | The key that encrypts what Social HQ keeps for companies (their LiteLLM
    | virtual keys), separate from APP_KEY. "base64:" and 32 random bytes:
    | `php artisan socialhq:secrets-key`. To rotate, move the old key to
    | SOCIALHQ_SECRETS_PREVIOUS_KEYS (comma separated) and set a new one.
    | Without it, companies can't add AI providers.
    |
    */

    'secrets' => [
        'key' => env('SOCIALHQ_SECRETS_KEY'),
        'previous_keys' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('SOCIALHQ_SECRETS_PREVIOUS_KEYS', '')),
        ))),
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
        // Old calendars (CSV or XLSX) imported as past posts for the AI planner.
        // An upload never confirmed is deleted after a day.
        'calendars' => [
            'max_file_megabytes' => (int) env('SOCIALHQ_CALENDAR_MAX_FILE_MB', 5),
            'max_rows' => (int) env('SOCIALHQ_CALENDAR_MAX_ROWS', 5000),
            'max_imports' => (int) env('SOCIALHQ_CALENDAR_MAX_IMPORTS', 50),
        ],
        // Brand look: the logo (PNG, JPEG, WebP or a sanitised SVG) on the private disk.
        'look' => [
            'logo_kilobytes' => (int) env('SOCIALHQ_BRAND_LOGO_KILOBYTES', 2048),
        ],
        // Google Docs, Sheets and Slides links shared with "Anyone with the link",
        // read through Google's export URLs (no Google sign-in).
        'google' => [
            'host' => env('SOCIALHQ_GOOGLE_DOCS_HOST', 'docs.google.com'),
            'timeout_seconds' => (int) env('SOCIALHQ_GOOGLE_TIMEOUT_SECONDS', 20),
        ],
        // "Read our website": same site only, robots.txt respected, a pause between
        // requests, then one AI call on the cleaned, deduplicated, cut text.
        'website' => [
            'max_pages' => (int) env('SOCIALHQ_WEBSITE_MAX_PAGES', 25),
            'delay_ms' => (int) env('SOCIALHQ_WEBSITE_DELAY_MS', 1000),
            'max_seconds' => (int) env('SOCIALHQ_WEBSITE_MAX_SECONDS', 150),
            'timeout_seconds' => 15,
            'page_kilobytes' => 2048,
            'robots_kilobytes' => 512,
            'sitemap_kilobytes' => 5120,
            'max_sitemaps' => 3,
            // What the AI gets: at most this much per page, and in all.
            'page_characters' => (int) env('SOCIALHQ_WEBSITE_PAGE_CHARACTERS', 4000),
            'ai_characters' => (int) env('SOCIALHQ_WEBSITE_AI_CHARACTERS', 20000),
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

    /*
    |--------------------------------------------------------------------------
    | Companies (self-hosted)
    |--------------------------------------------------------------------------
    |
    | A company is an account a platform admin approved; its owner creates
    | the workspaces (brands). Someone whose email isn't on the sign-up lists
    | may ask to start a company (requests), and waits until a platform admin
    | approves it. With `required`, only an approved company's owner creates
    | workspaces (the test run turns it off: upstream's tests have no
    | companies). `contact_email` is who a paused company's owner writes to;
    | without it, the platform admin who paused it.
    |
    */

    'companies' => [
        'requests' => (bool) env('SOCIALHQ_COMPANY_REQUESTS', true),
        'required' => (bool) env('SOCIALHQ_COMPANIES_REQUIRED', true),
        'contact_email' => env('SOCIALHQ_PLATFORM_CONTACT_EMAIL'),
        // A declined company may ask again once this many hours have passed.
        'ask_again_after_hours' => 24,
    ],

];
