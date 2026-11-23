<?php

declare(strict_types=1);

/*
| Settings › AI › Usage and the platform admins' AI usage page. English only;
| bin/socialhq-sync-lang copies this file to the other locales.
*/

return [
    'nav' => 'Usage',

    'page' => [
        'title' => 'AI usage',
        'description' => 'Each AI request, the provider that answered and what it cost.',
    ],

    'platform' => [
        'title' => 'AI usage: all companies',
        'description' => 'Every company\'s AI requests and how each one was routed.',
        'link' => 'AI usage',
    ],

    'kept' => 'Kept for 90 days. Prompts and answers are never stored.',

    'periods' => [
        '1d' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
    ],

    'periods_short' => [
        '1d' => '24 hours',
        '7d' => '7 days',
        '30d' => '30 days',
        '90d' => '90 days',
    ],

    'filters' => [
        'period' => 'Period',
        'status' => 'Result',
        'feature' => 'Feature',
        'provider' => 'Provider',
        'workspace' => 'Brand',
        'company' => 'Company',
        'person' => 'Person',
        'problems' => 'Failed or blocked',
        'all_people' => 'All people',
        'count' => ':shown of :total requests',
        'all_statuses' => 'All results',
        'all_features' => 'All features',
        'all_providers' => 'All providers',
        'all_workspaces' => 'All brands',
        'all_companies' => 'All companies',
        'clear' => 'Clear filters',
    ],

    'totals' => [
        'calls' => 'Requests',
        'cost' => 'Spent',
        'free' => 'Free',
        'paid' => 'Paid',
        'problems' => 'Failed or blocked',
        'free_share' => 'Answered free',
        'per_hour' => 'Per hour',
        'per_day' => 'Per day',
        'chart_label' => 'Requests over the period',
        'budget' => ':spent of :budget in the last :days days',
        'budget_share' => ':percent% of budget',
        'budget_share_small' => '<1% of budget',
        'no_budget' => ':spent in the last :days days',
        'free_paid' => ':free free · :paid paid',
        'nothing_answered' => 'Nothing answered yet',
        'all_answered' => 'All answered',
        'show_problems' => 'Show only these',
        'showing_problems' => 'Showing only these',
    ],

    'breakdown' => [
        'features' => 'By feature',
        'providers' => 'By provider',
        'filter_label' => 'Show only :name',
        'none' => 'No requests in this period.',
    ],

    'days' => [
        'title' => 'Requests by day',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'requests' => '{1} :count request|[0,*] :count requests',
        'tokens' => ':count tokens',
        'all_answered' => 'All answered',
        'problems' => ':count failed or blocked',
        'more_features' => '+:count more',
        'expand_all' => 'Expand all',
        'collapse_all' => 'Collapse all',
        'show_all' => 'Show all :count requests',
        'limited' => 'Showing the latest :shown of :total requests on this day.',
        'loading' => 'Loading requests…',
        'load_failed' => 'Couldn\'t load this day\'s requests.',
        'retry' => 'Try again',
    ],

    'columns' => [
        'time' => 'Time',
        'feature' => 'Feature',
        'person' => 'Person',
        'model' => 'Model',
        'tokens' => 'Tokens',
        'duration' => 'Time taken',
        'cost' => 'Cost',
        'result' => 'Result',
    ],

    'status' => [
        'ok' => 'Answered',
        'failed' => 'Failed',
        'cut_off' => 'Cut off',
        'rate_limited' => 'Rate-limited',
        'blocked_budget' => 'Budget reached',
        'blocked_no_route' => 'No AI provider',
    ],

    'route' => [
        'own' => 'Your keys',
        'platform' => 'Social HQ keys',
        'none' => 'No keys',
    ],

    'row' => [
        'free' => 'Free',
        'fallback' => 'Fell back',
        'unknown_provider' => 'Not sent',
        'deleted' => 'Deleted',
        'nobody' => 'System',
    ],

    'detail' => [
        'who' => 'Who',
        'routing' => 'Routing',
        'usage' => 'Usage',
        'copy' => 'Copy',
        'copy_label' => 'Copy the LiteLLM call id',
        'show' => 'Details',
        'hide' => 'Hide details',
        'company' => 'Company',
        'workspace' => 'Brand',
        'user' => 'Person',
        'task' => 'Task',
        'route' => 'Keys used',
        'requested_group' => 'Asked for',
        'answered_group' => 'Answered by group',
        'model' => 'Model',
        'fallbacks' => 'Fallbacks',
        'requests' => 'Requests to LiteLLM',
        'tokens' => 'Tokens (in / out)',
        'duration' => 'Time',
        'cost' => 'Cost',
        'error' => 'Error',
        'call_id' => 'LiteLLM call id',
        'none' => '—',
    ],

    'explain' => [
        'blocked_no_route' => 'Not sent: this company has no AI provider for this task. Add one in Settings › AI.',
        'blocked_budget' => 'LiteLLM refused it: the monthly budget in Settings › AI is spent.',
        'rate_limited' => 'Every provider for this task was busy or out of quota.',
        'failed' => 'The provider or LiteLLM answered with an error.',
        'cut_off' => 'The answer stopped at its length limit, so it was missing or incomplete. A model that reasons can spend the limit thinking.',
        'fell_back' => 'The free providers failed, so the paid group answered.',
    ],

    'empty' => [
        'title' => 'No AI requests yet',
        'description' => 'Requests show here as your team uses AI for plans, captions, briefs and graphics.',
    ],

    'no_results' => [
        'title' => 'No requests match these filters',
        'description' => 'Try another period, or clear the filters.',
    ],

    'features' => [
        'post_writing_assistant' => 'Caption assistant',
        'idea_generator' => 'Ideas',
        'media_alt_text_generator' => 'Alt text',
        'post_content_shortener' => 'Shorten caption',
        'content_planner' => 'Plan generation',
        'copy_writer' => 'Plan item copy',
        'brief_writer' => 'Creative brief',
        'website_reader' => 'Read our website',
        'graphic_safety_reviewer' => 'Graphic safety check',
        'image_generation' => 'AI graphic',
        'embeddings' => 'Knowledge search',
    ],
];
