<?php

declare(strict_types=1);

/*
| Settings › AI: a company's own AI providers. English only; run
| bin/socialhq-sync-lang after changing it.
*/

return [
    'nav' => 'AI',

    'page' => [
        'title' => 'AI',
        'description' => 'The AI providers your company uses, and its budget.',
    ],

    'blocker' => [
        'title' => 'AI setup isn\'t available on this server yet',
        'gateway_off' => 'To add your own AI providers, the server needs the AI gateway turned on. Ask whoever runs Social HQ to set it up.',
        'secrets_key_missing' => 'To keep your keys safe, the server needs its secrets key. Ask whoever runs Social HQ to set SOCIALHQ_SECRETS_KEY.',
        'gateway_not_configured' => 'To add your own AI providers, the server needs access to the AI gateway. Ask whoever runs Social HQ to set LITELLM_ADMIN_KEY.',
    ],

    'status' => [
        'title' => 'What AI can do now',
        'platform' => 'Social HQ\'s AI',
        'none' => 'Not set up',
        'more' => '+:count more',
        'tasks' => [
            'write' => 'Writing captions',
            'structured' => 'Ideas and plans',
            'vision' => 'Alt text and image checks',
            'image' => 'Generating graphics',
            'embed' => 'Knowledge search',
        ],
    ],

    'empty' => [
        'title' => 'You haven\'t added an AI provider yet',
        'body' => 'To write captions, ideas and plans with AI, add a key from one of these providers. It takes about two minutes.',
        'platform_title' => 'You\'re using Social HQ\'s AI',
        'platform_body' => 'AI already works for your company. Add your own key only if you want to use your own providers, limits or budget.',
    ],

    'list' => [
        'title' => 'Keys',
        'order_hint' => 'AI tries your keys from top to bottom and moves on when one fails or hits its limit. Add more keys to get more free use.',
        'add' => 'Add a key',
        'key_ending' => 'Key ending in :last4',
        'tested' => 'Tested :time',
        'never_tested' => 'Not tested yet',
        'retired' => 'No longer offered: remove it and add another key.',
        'status' => [
            'ok' => 'Working',
            'failed' => 'Not working',
            'untested' => 'Not tested',
        ],
        'actions' => [
            'menu' => 'Key actions',
            'test' => 'Test',
            'edit' => 'Edit',
            'replace' => 'Replace key',
            'remove' => 'Remove',
            'move_up' => 'Move up',
            'move_down' => 'Move down',
        ],
    ],

    'tiers' => [
        'free' => 'Free',
        'paid' => 'Paid',
    ],

    'tier_notes' => [
        'free' => 'Costs nothing, within the provider\'s free limits. Not counted in your budget.',
        'paid' => 'Billed by the provider. Counted in your budget.',
    ],

    'add' => [
        'title' => 'Add an AI key',
        'description' => 'Pick a provider, follow its steps, then paste the key.',
        'provider' => 'Provider',
        'label' => 'Name',
        'label_hint' => 'To tell this key apart from your others, such as "Groq – marketing".',
        'tier' => 'Plan',
        'tasks' => 'Use this key for',
        'models' => 'Models',
        'models_hint' => 'Leave a field empty to use the model shown. To use another, type its ID as the provider lists it.',
        'serves' => 'Used for: :tasks',
        'open_console' => 'Open :provider',
        'key' => 'API key',
        'key_placeholder' => 'Paste the key',
        'account_id' => 'Account ID',
        'submit' => 'Test and add',
        'stored_note' => 'We test the key before saving it. It\'s kept encrypted in your company\'s AI gateway; Social HQ keeps only its last 4 characters.',
    ],

    'edit' => [
        'title' => 'Edit :provider',
        'description' => 'Change the name, plan, what the key is used for or its models. The key stays the same.',
        'submit' => 'Save',
    ],

    'replace' => [
        'title' => 'Replace the :provider key',
        'description' => 'The new key is tested first. Until it passes, the current key stays in use.',
        'submit' => 'Test and replace',
    ],

    'remove' => [
        'title' => 'Remove :provider?',
        'description' => 'AI stops using this key, and the key is deleted from your AI gateway.',
        'others_take_over' => 'Your other keys take over what it did.',
        'platform_takes_over' => 'Social HQ\'s AI takes over what it did.',
        'ai_stops' => 'AI features stop until you add another key.',
        'submit' => 'Remove',
    ],

    'budget' => [
        'title' => 'Monthly budget',
        'description' => 'AI stops when your company has spent this much in a period. Leave it empty for no limit.',
        'label' => 'Budget (USD)',
        'save' => 'Save budget',
        'spent' => ':spend spent this period',
        'spent_of' => ':spend of :budget spent this period',
        'resets' => 'Resets :date',
        'spend_unavailable' => 'Spending can\'t be shown right now.',
        'no_gateway_yet' => 'Spending shows here once you add a key.',
        'free_note' => 'Calls with keys on a free plan don\'t count toward the budget.',
        'platform_note' => 'It limits only your own keys: Social HQ\'s AI isn\'t counted.',
    ],

    'storage' => [
        'title' => 'What\'s stored where',
        'keys' => 'Provider keys are stored only in your company\'s AI gateway, encrypted. Social HQ never shows them again.',
        'app' => 'Social HQ keeps the provider, the key\'s last 4 characters, test results and who changed what.',
        'isolation' => 'Your company\'s AI calls use only your keys, and no other company can use them.',
    ],

    'confirm' => [
        'training_off' => 'I switched off "Anonymous improvement data" in Mistral\'s console.',
        'billing_on' => 'Billing is on for this key\'s Google project.',
        'may_train' => 'I understand the provider may use what we send with this key to train its AI, and its staff may read it.',
        'openrouter_privacy' => 'In OpenRouter\'s privacy settings, I turned off providers that may train on our data.',
    ],

    'providers' => [
        'groq' => [
            'name' => 'Groq',
            'step_1' => 'Sign in at console.groq.com.',
            'step_2' => 'Open API Keys and select Create API Key.',
            'step_3' => 'Copy the key (it starts with gsk_) and paste it below.',
            'data' => 'Groq doesn\'t train on prompts. In its console, turn on Zero Data Retention so prompts aren\'t kept.',
        ],
        'cloudflare' => [
            'name' => 'Cloudflare Workers AI',
            'step_1' => 'In the Cloudflare dashboard, copy your Account ID from the account home page.',
            'step_2' => 'Open My Profile › API Tokens, select Create Token and use the Workers AI template.',
            'step_3' => 'Paste the Account ID and the token below.',
            'data' => 'Cloudflare doesn\'t train on Workers AI prompts.',
        ],
        'mistral' => [
            'name' => 'Mistral',
            'step_1' => 'Sign in at console.mistral.ai.',
            'step_2' => 'In Admin › Privacy, switch off "Anonymous improvement data".',
            'step_3' => 'Open API Keys, create a key and paste it below.',
            'data' => 'Mistral trains on prompts until "Anonymous improvement data" is off.',
        ],
        'gemini' => [
            'name' => 'Google Gemini',
            'step_1' => 'In Google AI Studio, pick the project you\'ll use. For the paid plan, turn on billing for it.',
            'step_2' => 'Open Get API key and create a key in that project.',
            'step_3' => 'Paste the key below.',
            'data' => 'Google trains on prompts sent with free-plan keys and its staff may read them. Keys from a project with billing on are not used for training.',
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'step_1' => 'Sign in at openrouter.ai.',
            'step_2' => 'In Settings › Privacy, choose which providers may get your prompts. For the paid plan, turn off providers that may train on them.',
            'step_3' => 'Open Keys, create a key and paste it below.',
            'data' => 'OpenRouter sends prompts to many providers with their own rules. Free models are often run by providers that log or train on prompts.',
        ],
        'openai' => [
            'name' => 'OpenAI',
            'step_1' => 'Sign in at platform.openai.com.',
            'step_2' => 'Open API keys and select Create new secret key.',
            'step_3' => 'Paste the key below.',
            'data' => 'OpenAI doesn\'t train on data sent through its API.',
        ],
        'anthropic' => [
            'name' => 'Anthropic',
            'step_1' => 'Sign in at console.anthropic.com.',
            'step_2' => 'Open API Keys and select Create Key.',
            'step_3' => 'Paste the key below.',
            'data' => 'Anthropic doesn\'t train on data sent through its API.',
        ],
    ],

    'errors' => [
        'invalid_key' => 'The provider didn\'t accept this key. Check you copied all of it, or create a new one.',
        'rate_limited' => 'The provider is limiting requests for this key right now. Try again in a minute.',
        'no_access' => 'This key can\'t use the model Social HQ needs. Check the key\'s permissions or plan.',
        'timeout' => 'The provider took too long to answer. Try again.',
        'provider_error' => 'The provider couldn\'t answer the test. Try again, or check its status page.',
        'gateway_unreachable' => 'Couldn\'t reach the AI gateway. Try again in a minute.',
        'gateway_refused' => 'The AI gateway refused the request. Ask whoever runs Social HQ to check LITELLM_ADMIN_KEY.',
        'gateway_error' => 'The AI gateway couldn\'t save this change. Try again.',
        'gateway_not_configured' => 'The server can\'t reach the AI gateway yet. Ask whoever runs Social HQ to set LITELLM_ADMIN_KEY.',
        'gateway_off' => 'The server\'s AI gateway is off. Ask whoever runs Social HQ to turn it on.',
        'secrets_key_missing' => 'The server can\'t store keys safely yet. Ask whoever runs Social HQ to set SOCIALHQ_SECRETS_KEY.',
        'provider_retired' => 'This provider or plan is no longer offered. Remove the key and add another one.',
    ],

    'validation' => [
        'key_required' => 'Paste the API key.',
        'key_format' => 'That doesn\'t look like an API key. Copy it again from the provider.',
        'account_id' => 'Paste your Cloudflare Account ID: 32 letters and numbers.',
        'confirm_training_off' => 'To use Mistral, switch off "Anonymous improvement data" first and tick the box.',
        'confirm_billing_on' => 'To use Gemini\'s paid plan, turn on billing for the key\'s project and tick the box.',
        'confirm_may_train' => 'To use a free plan that may train on your data, tick the box.',
        'confirm_openrouter_privacy' => 'To use OpenRouter\'s paid plan, turn off providers that may train on your data and tick the box.',
        'too_many' => 'You\'ve added as many keys as Social HQ allows. Remove one first.',
        'label_required' => 'Give this key a name.',
        'label_long' => 'Use a name of 60 characters or fewer.',
        'label_taken' => 'Another key already has this name. Pick another one.',
        'tier' => 'Pick the plan this key is on.',
        'tasks' => 'Pick at least one thing this key is used for.',
        'model_format' => 'That doesn\'t look like a model ID. Copy it from the provider\'s model list.',
        'budget' => 'Enter a budget of at least 1 USD, or leave it empty.',
    ],

    'flash' => [
        'added' => ':provider is added and working.',
        'saved' => ':provider is saved.',
        'replaced' => 'The :provider key is replaced.',
        'removed' => ':provider is removed.',
        'test_passed' => ':provider answered the test.',
        'test_failed' => ':provider didn\'t answer the test. :reason',
        'budget_saved' => 'Budget saved.',
    ],

    'unavailable' => [
        'owner' => 'Your company hasn\'t set up AI yet. To use AI, add an AI provider in Settings › AI.',
        'member' => 'Your company hasn\'t set up AI yet. Ask your company\'s owner to add an AI provider in Settings › AI.',
    ],
];
