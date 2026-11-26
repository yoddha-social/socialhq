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
    | A company may add several keys, several for one provider too (more free
    | quota, or one key per team), each with its own name. LiteLLM tries them
    | in the order on the page and moves on when one fails or is rate limited.
    |
    | providers: the catalogue. Each provider lists the plans (`tiers`) a key
    | may be on, and per plan the tasks it serves with the LiteLLM model of
    | each. A free plan's deployments carry zero prices, so they never use up
    | the company's budget. `confirm` names a checkbox the owner must tick
    | first: training_off (Mistral's training opt-out), billing_on (Gemini's
    | paid plan), may_train (a free plan whose provider may use prompts to
    | train: the Gemini free tier, OpenRouter's free models; the owner's
    | choice for their own company, never the platform's) and
    | openrouter_privacy (OpenRouter with training providers turned off).
    | An owner may switch a task off for a key and pick another model per
    | task. Embeddings take only the first key in the order that has them
    | (vectors from two models can't be compared).
    |
    | Copy (name, setup steps, data note, plans) lives in lang/en/socialhq_ai.php.
    |
    */

    'company_ai' => [
        'budget_duration' => '30d',
        // Keys per company, every provider together.
        'max_keys' => 20,
        // Where LiteLLM reaches Workers AI for a company's Cloudflare account.
        'cloudflare_api_base' => env('SOCIALHQ_CLOUDFLARE_AI_API_BASE', 'https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run/'),
        'providers' => [
            'groq' => [
                'logo' => 'groq',
                'litellm_provider' => 'groq',
                'console_url' => 'https://console.groq.com/keys',
                'steps' => 3,
                'fields' => ['provider_key'],
                'params' => [],
                'tiers' => [
                    'free' => [
                        'confirm' => null,
                        'tasks' => [
                            'write' => ['model' => 'groq/openai/gpt-oss-120b', 'timeout' => 20],
                            'structured' => ['model' => 'groq/openai/gpt-oss-120b', 'timeout' => 75],
                            'vision' => ['model' => 'groq/qwen/qwen3.8-27b', 'timeout' => 20],
                        ],
                    ],
                    'paid' => [
                        'confirm' => null,
                        'tasks' => [
                            'write' => ['model' => 'groq/openai/gpt-oss-120b', 'timeout' => 20],
                            'structured' => ['model' => 'groq/openai/gpt-oss-120b', 'timeout' => 75],
                            'vision' => ['model' => 'groq/qwen/qwen3.8-27b', 'timeout' => 20],
                        ],
                    ],
                ],
            ],
            'cloudflare' => [
                'logo' => 'cloudflare',
                'litellm_provider' => 'cloudflare',
                'console_url' => 'https://dash.cloudflare.com/profile/api-tokens',
                'steps' => 3,
                'fields' => ['account_id', 'provider_key'],
                // Cloudflare's models answer 256 tokens unless asked for more.
                'params' => ['max_tokens' => 8192],
                'tiers' => [
                    'free' => [
                        'confirm' => null,
                        'tasks' => [
                            'write' => ['model' => 'cloudflare/@cf/openai/gpt-oss-120b', 'timeout' => 20],
                            'structured' => ['model' => 'cloudflare/@cf/openai/gpt-oss-120b', 'timeout' => 75],
                            'vision' => ['model' => 'cloudflare/@cf/meta/llama-4-scout-17b-16e-instruct', 'timeout' => 20],
                        ],
                    ],
                    'paid' => [
                        'confirm' => null,
                        'tasks' => [
                            'write' => ['model' => 'cloudflare/@cf/openai/gpt-oss-120b', 'timeout' => 20],
                            'structured' => ['model' => 'cloudflare/@cf/openai/gpt-oss-120b', 'timeout' => 75],
                            'vision' => ['model' => 'cloudflare/@cf/meta/llama-4-scout-17b-16e-instruct', 'timeout' => 20],
                        ],
                    ],
                ],
            ],
            'mistral' => [
                'logo' => 'mistral',
                'litellm_provider' => 'mistral',
                'console_url' => 'https://console.mistral.ai/api-keys',
                'steps' => 3,
                'fields' => ['provider_key'],
                'params' => [],
                'tiers' => [
                    'free' => [
                        'confirm' => 'training_off',
                        'tasks' => [
                            'write' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 20],
                            'structured' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 75],
                            'vision' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 20],
                            'embed' => ['model' => 'mistral/mistral-embed', 'timeout' => 30],
                        ],
                    ],
                    'paid' => [
                        'confirm' => 'training_off',
                        'tasks' => [
                            'write' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 20],
                            'structured' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 75],
                            'vision' => ['model' => 'mistral/mistral-small-latest', 'timeout' => 20],
                            'embed' => ['model' => 'mistral/mistral-embed', 'timeout' => 30],
                        ],
                    ],
                ],
            ],
            'gemini' => [
                'logo' => 'gemini',
                'litellm_provider' => 'gemini',
                'console_url' => 'https://aistudio.google.com/apikey',
                'steps' => 3,
                'fields' => ['provider_key'],
                'params' => [],
                'tiers' => [
                    // Google's free tier has no image quota.
                    'free' => [
                        'confirm' => 'may_train',
                        'tasks' => [
                            'write' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 30],
                            'structured' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 75],
                            'vision' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 30],
                            'embed' => ['model' => 'gemini/gemini-embedding-001', 'timeout' => 30],
                        ],
                    ],
                    'paid' => [
                        'confirm' => 'billing_on',
                        'tasks' => [
                            'write' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 30],
                            'structured' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 75],
                            'vision' => ['model' => 'gemini/gemini-3.5-flash', 'timeout' => 30],
                            'image' => ['model' => 'gemini/gemini-3.1-flash-image', 'timeout' => 300],
                            'embed' => ['model' => 'gemini/gemini-embedding-001', 'timeout' => 30],
                        ],
                    ],
                ],
            ],
            'openrouter' => [
                'logo' => 'openrouter',
                'litellm_provider' => 'openrouter',
                'console_url' => 'https://openrouter.ai/settings/keys',
                'steps' => 3,
                'fields' => ['provider_key'],
                'params' => [],
                'tiers' => [
                    // OpenRouter's ":free" models change often: owners pick others per key.
                    // Reasoning is off: the free reasoning models spent a call's whole output
                    // limit thinking and answered nothing (QA 2026-11-23: plans, copy and
                    // briefs all came back empty). OpenRouter ignores it for models that
                    // don't reason.
                    'free' => [
                        'confirm' => 'may_train',
                        'tasks' => [
                            'write' => ['model' => 'openrouter/nvidia/nemotron-3.5-super-vl:free', 'timeout' => 45, 'params' => ['extra_body' => ['reasoning' => ['enabled' => false]]]],
                            'structured' => ['model' => 'openrouter/nvidia/nemotron-3.5-super-vl:free', 'timeout' => 90, 'params' => ['extra_body' => ['reasoning' => ['enabled' => false]]]],
                            'vision' => ['model' => 'openrouter/nvidia/nemotron-3.5-super-vl:free', 'timeout' => 45, 'params' => ['extra_body' => ['reasoning' => ['enabled' => false]]]],
                        ],
                    ],
                    'paid' => [
                        'confirm' => 'openrouter_privacy',
                        'tasks' => [
                            'write' => ['model' => 'openrouter/openai/gpt-5-mini', 'timeout' => 30],
                            'structured' => ['model' => 'openrouter/openai/gpt-5-mini', 'timeout' => 75],
                            'vision' => ['model' => 'openrouter/openai/gpt-5-mini', 'timeout' => 30],
                        ],
                    ],
                ],
            ],
            'openai' => [
                'logo' => 'openai',
                'litellm_provider' => 'openai',
                'console_url' => 'https://platform.openai.com/api-keys',
                'steps' => 3,
                'fields' => ['provider_key'],
                'params' => [],
                'tiers' => [
                    'paid' => [
                        'confirm' => null,
                        'tasks' => [
                            'write' => ['model' => 'openai/gpt-5-mini', 'timeout' => 30],
                            'structured' => ['model' => 'openai/gpt-5-mini', 'timeout' => 75],
                            'vision' => ['model' => 'openai/gpt-5-mini', 'timeout' => 30],
                            'image' => ['model' => 'openai/gpt-image-1', 'timeout' => 300],
                            'embed' => ['model' => 'openai/text-embedding-3-small', 'timeout' => 30],
                        ],
                    ],
                ],
            ],
            'anthropic' => [
                'logo' => 'anthropic',
                'litellm_provider' => 'anthropic',
                'console_url' => 'https://console.anthropic.com/settings/keys',
                'steps' => 3,
                'fields' => ['provider_key'],
                'params' => [],
                'tiers' => [
                    'paid' => [
                        'confirm' => null,
                        'tasks' => [
                            'write' => ['model' => 'anthropic/claude-haiku-4-5', 'timeout' => 30],
                            'structured' => ['model' => 'anthropic/claude-haiku-4-5', 'timeout' => 75],
                            'vision' => ['model' => 'anthropic/claude-haiku-4-5', 'timeout' => 30],
                        ],
                    ],
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
    | Publish now
    |--------------------------------------------------------------------------
    |
    | Every "Publish now" (the composer, a post's menu and details, approving a
    | post) asks first: on a real channel one misclick posted at once (QA
    | 2026-11-18). Off only for TryPost's browser tests (phpunit.xml).
    |
    */

    'publish_now' => [
        'confirm' => (bool) env('SOCIALHQ_PUBLISH_NOW_CONFIRM', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Cashier's Stripe webhook route is TryPost Cloud's billing; without a
    | webhook secret it accepts unsigned messages. A self-hosted install (ours)
    | doesn't register it unless this is on (TryPost's billing tests turn it on).
    |
    */

    'billing' => [
        'stripe_webhook' => (bool) env('SOCIALHQ_STRIPE_WEBHOOK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar
    |--------------------------------------------------------------------------
    |
    | The calendar's post list opens by itself, on Scheduled, until the person
    | hides it (their browser remembers). Off, it starts closed and opens on
    | Drafts, as TryPost's "No Date" panel did: TryPost's browser tests expect that.
    |
    */

    'calendar' => [
        'post_list_open' => (bool) env('SOCIALHQ_CALENDAR_POST_LIST_OPEN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agents (API keys and MCP grants)
    |--------------------------------------------------------------------------
    |
    | manage_admin_settings: an agent may manage API keys and webhooks with an
    | admin's rights. Off: an agent that reads untrusted text (a web page, a
    | comment) could be steered into minting a key or sending every post event
    | to an outside URL. The MCP server then hides those tools, and the REST
    | API refuses them (App\SocialHq\Agents\AdminSettings).
    |
    */

    'agents' => [
        'manage_admin_settings' => (bool) env('SOCIALHQ_AGENTS_MANAGE_ADMIN_SETTINGS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | require_credentials: offer and start a network's connect flow only when
    | the server has its OAuth client id and secret (or the shared Telegram or
    | Discord bot) set, besides its *_ENABLED flag. Off in upstream's tests.
    |
    */

    'channels' => [
        'require_credentials' => (bool) env('SOCIALHQ_CHANNELS_REQUIRE_CREDENTIALS', true),
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
        // SEO keywords (Strategy tab): up to max_main main and max_supporting supporting ones;
        // a prompt gets at most `prompt` of them, outside prompt_characters. The check reads the
        // brand's posts of the last recent_days, flags the claim_words, and never calls a word of
        // not_places a place (time zone names that are everyday words).
        'keywords' => [
            'max_main' => 5,
            'max_supporting' => 25,
            'prompt' => 8,
            'recent_days' => 90,
            'claim_words' => ['best', 'top', 'cheapest', 'leading', 'guaranteed', 'number one', 'no 1', '#1', 'finest', 'greatest'],
            'not_places' => ['christmas', 'easter', 'wake', 'midway', 'troll', 'casey', 'davis', 'palmer', 'vostok', 'mawson', 'syowa', 'macquarie', 'rothera', 'mcmurdo', 'currie', 'hope', 'universal', 'factory', 'zulu', 'greenwich'],
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
        // Days our copy of a generated graphic is kept (at least the 7 Recent AI graphics lists).
        'keep_results_days' => (int) env('SOCIALHQ_AI_GRAPHICS_KEEP_RESULTS_DAYS', 30),
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
    | Events: public holidays by country (Nager.Date)
    |--------------------------------------------------------------------------
    |
    | "Add a country" on the events page imports a country's public holidays
    | from Nager.Date (free, no key), unconfirmed. Nepal's dates come from our
    | official list instead. Each (country, year) answer is cached for
    | cache_hours and the country list for a day; failures are not cached.
    | At most max_countries per import, for this year and the next.
    |
    */

    'events' => [
        'nager' => [
            'host' => rtrim((string) env('SOCIALHQ_NAGER_HOST', 'https://date.nager.at'), '/'),
            'timeout_seconds' => (int) env('SOCIALHQ_NAGER_TIMEOUT', 10),
            'cache_hours' => (int) env('SOCIALHQ_NAGER_CACHE_HOURS', 12),
            'max_countries' => 5,
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Queue workers (Horizon)
    |--------------------------------------------------------------------------
    |
    | TryPost's config/horizon.php runs seven supervisors and, with
    | `balance: auto`, keeps a worker per queue even when idle (about 16 PHP
    | processes, plus one per supervisor). A small team's server doesn't need
    | that. With `compact` on (App\SocialHq\Queue\CompactHorizon), the same
    | queues are worked by three supervisors, each one pool that keeps one
    | worker when idle and adds workers as jobs wait (up to `max_processes`,
    | `balance_max_shift` at a time every `balance_cooldown` seconds):
    |
    |   core               default, broadcasts, webhooks, analytics, rss-feeds, posthog
    |   social-publishing  one queue per enabled network (name kept, so
    |                      horizon:pause-supervisor still works)
    |   media              media-imports, media-adoption
    |
    | Each takes the longest timeout and highest memory of what it absorbs.
    | Horizon refuses minProcesses 0, and an idle pool also has to move
    | delayed and retried jobs onto its queues, so one worker stays. A
    | supervisor TryPost adds later is kept as it is. Off in phpunit.xml:
    | upstream's tests check upstream's layout.
    |
    | Measured 2026-11-18 (production settings, 5 networks, a burst of 81 jobs
    | including 35 publishes at once): idle 578 MB instead of 1,408 MB, peak
    | 1,395 MB instead of 2,227 MB, the same publishing waits (p95 13 s) and
    | the same time to clear the burst.
    |
    */

    'horizon' => [
        'compact' => (bool) env('SOCIALHQ_HORIZON_COMPACT', true),
        'max_processes' => [
            'core' => (int) env('SOCIALHQ_HORIZON_CORE_MAX', 4),
            'social-publishing' => (int) env('SOCIALHQ_HORIZON_PUBLISHING_MAX', 8),
            'media' => (int) env('SOCIALHQ_HORIZON_MEDIA_MAX', 4),
        ],
        'balance_max_shift' => (int) env('SOCIALHQ_HORIZON_MAX_SHIFT', 2),
        'balance_cooldown' => (int) env('SOCIALHQ_HORIZON_COOLDOWN', 3),
    ],

];
