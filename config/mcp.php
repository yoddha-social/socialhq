<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    | Social HQ: only the assistants we support, so nobody can register an
    | "assistant" that sends a member's grant to their own site (audit
    | SEC-04). Claude's hosted apps (claude.ai, Desktop, mobile) return to
    | https://claude.ai/api/mcp/auth_callback, ChatGPT to a path on
    | https://chatgpt.com. Any loopback entry allows http callbacks to
    | localhost, 127.0.0.1 and [::1] on any port (RFC 8252): Claude Code and
    | other tools on the member's own computer listen there, and no other
    | computer can. Desktop apps with their own scheme are listed below.
    | SOCIALHQ_MCP_REDIRECT_DOMAINS (comma separated) replaces the list.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'SOCIALHQ_MCP_REDIRECT_DOMAINS',
        'https://claude.ai,https://chatgpt.com,http://localhost,http://127.0.0.1,http://[::1]',
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        'antigravity',
        'chatgpt',
        'claude',
        'claude-cli',
        'claude-code',
        'claude-desktop',
        'claudeai',
        'codex',
        'codium',
        'continue',
        'cursor',
        'devin',
        'goose',
        'grok',
        'hermes',
        'jetbrains',
        'kiro',
        'lmstudio',
        'opencode',
        'raycast',
        'trae',
        'vscode',
        'vscode-insiders',
        'warp',
        'windsurf',
        'xai',
        'zed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The maximum
    | number of tool calls limits how many tools each search request can run
    | while the maximum output bytes value caps the size of every result.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

];
