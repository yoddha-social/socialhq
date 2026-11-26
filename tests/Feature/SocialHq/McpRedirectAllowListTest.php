<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * An AI assistant registers itself (POST /oauth/register) with the addresses
 * a member's grant may be sent back to. Only the supported assistants'
 * addresses are taken (config/mcp.php, SOCIALHQ_MCP_REDIRECT_DOMAINS), so
 * nobody can register a fake "Claude" whose grant lands on their own site
 * (audit 2026-11-24, SEC-04).
 *
 * @param  list<string>  $redirectUris
 */
function registerMcpClient(array $redirectUris): TestResponse
{
    return test()->postJson(route('mcp.oauth.register'), [
        'client_name' => 'Claude',
        'redirect_uris' => $redirectUris,
        'grant_types' => ['authorization_code'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ]);
}

test('the supported assistants register with the addresses they really use', function (string $redirectUri) {
    registerMcpClient([$redirectUri])
        ->assertCreated()
        ->assertJsonPath('redirect_uris', [$redirectUri]);
})->with([
    'claude.ai, Claude Desktop and mobile' => 'https://claude.ai/api/mcp/auth_callback',
    'chatgpt' => 'https://chatgpt.com/connector_platform_oauth_redirect',
    'chatgpt, per connector' => 'https://chatgpt.com/connector/oauth/cb_0123456789abcdef',
    'claude code on localhost, any port' => 'http://localhost:3118/callback',
    'a cli on 127.0.0.1' => 'http://127.0.0.1:19876/mcp/oauth/callback',
    'a cli on ipv6 loopback' => 'http://[::1]:8765/callback',
    'cursor, its own scheme' => 'cursor://anysphere.cursor-mcp/oauth/callback',
]);

test('an assistant that would send the grant anywhere else is refused and nothing is registered', function (string $redirectUri) {
    $clients = DB::table('oauth_clients')->count();

    registerMcpClient([$redirectUri])
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_redirect_uri');

    expect(DB::table('oauth_clients')->count())->toBe($clients);
})->with([
    'an attacker\'s site' => 'https://attacker.example/callback',
    'a look-alike host' => 'https://claude.ai.attacker.example/api/mcp/auth_callback',
    'the real host in the query' => 'https://attacker.example/?next=https://claude.ai/',
    'the real host as a user name' => 'https://claude.ai@attacker.example/callback',
    'another port on the real host' => 'https://claude.ai:8443/api/mcp/auth_callback',
    'plain http to the real host' => 'http://claude.ai/api/mcp/auth_callback',
    'a look-alike loopback host' => 'http://localhost.attacker.example/callback',
    'a look-alike loopback address' => 'http://127.0.0.1.attacker.example/callback',
    'an assistant we don\'t list' => 'https://vscode.dev/redirect',
]);

test('one good address doesn\'t carry a bad one through', function () {
    $clients = DB::table('oauth_clients')->count();

    registerMcpClient(['https://claude.ai/api/mcp/auth_callback', 'https://attacker.example/callback'])
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_redirect_uri');

    expect(DB::table('oauth_clients')->count())->toBe($clients);
});

test('another assistant is allowed by adding its address to the list', function () {
    // VS Code registers its web relay with its loopback addresses.
    $vsCode = ['https://insiders.vscode.dev/redirect', 'https://vscode.dev/redirect', 'http://127.0.0.1/', 'http://127.0.0.1:33418/'];

    registerMcpClient($vsCode)->assertBadRequest();

    config()->set('mcp.redirect_domains', [...config('mcp.redirect_domains'), 'https://vscode.dev', 'https://insiders.vscode.dev']);

    registerMcpClient($vsCode)->assertCreated();
});

test('without a loopback entry, tools on the member\'s computer are refused too', function () {
    config()->set('mcp.redirect_domains', ['https://claude.ai', 'https://chatgpt.com']);

    registerMcpClient(['http://localhost:3118/callback'])->assertBadRequest();
    registerMcpClient(['https://claude.ai/api/mcp/auth_callback'])->assertCreated();
});
