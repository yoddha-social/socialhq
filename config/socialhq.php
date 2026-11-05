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

];
