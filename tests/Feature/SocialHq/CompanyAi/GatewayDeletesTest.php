<?php

declare(strict_types=1);

use App\SocialHq\Ai\Gateway\GatewayException;
use App\SocialHq\Ai\Gateway\LiteLlmAdmin;
use Illuminate\Support\Facades\Http;

/*
 * Clean-up in LiteLLM counts a delete as done only when it happened or the
 * thing was already gone (audit 2026-11-24, BE-07). The answers are LiteLLM
 * v1.106.7's: an unknown deployment is a 400 saying "not found in db", an
 * unknown team a 404; any other refusal fails.
 */
beforeEach(function () {
    config([
        'socialhq.ai.litellm.url' => 'http://litellm.test/v1',
        'socialhq.ai.litellm.admin_url' => null,
        'socialhq.ai.litellm.admin_key' => 'sk-admin-test',
    ]);
});

test('a deployment LiteLLM no longer has counts as deleted', function () {
    Http::fake(['litellm.test/model/delete' => Http::response(['error' => ['message' => "{'error': 'Model with id=shq-co-1-groq-write not found in db'}", 'code' => '400']], 400)]);

    app(LiteLlmAdmin::class)->deleteDeployment('shq-co-1-groq-write');

    Http::assertSentCount(1);
});

test('a deployment delete LiteLLM refuses for another reason fails', function () {
    Http::fake(['litellm.test/model/delete' => Http::response(['error' => ['message' => 'Invalid model_info', 'code' => '400']], 400)]);

    expect(fn () => app(LiteLlmAdmin::class)->deleteDeployment('shq-co-1-groq-write'))
        ->toThrow(GatewayException::class, 'LiteLLM /model/delete answered HTTP 400.');
});

test('a team LiteLLM no longer has counts as deleted, and a refused team delete fails', function () {
    Http::fake(['litellm.test/team/delete' => Http::sequence()
        ->push(['detail' => ['error' => 'Team not found, passed team_id=shq-co-1']], 404)
        ->push(['detail' => ['error' => 'No team id passed in']], 400)]);

    app(LiteLlmAdmin::class)->deleteTeam('shq-co-1');

    expect(fn () => app(LiteLlmAdmin::class)->deleteTeam('shq-co-1'))
        ->toThrow(GatewayException::class, 'LiteLLM /team/delete answered HTTP 400.');
});
