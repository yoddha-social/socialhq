<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\PostContentShortener;
use App\Enums\User\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiUnavailableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;

use function Laravel\Ai\agent;

beforeEach(function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['openai', 'anthropic', 'gemini'],
        'socialhq.ai.routes' => array_fill_keys(array_map(fn (AiTask $task): string => $task->value, AiTask::cases()), null),
        'ai.default' => 'openai',
        'ai.providers.openai.key' => 'test-openai-key',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
        'ai.providers.gemini.key' => 'test-gemini-key',
    ]);
});

/**
 * Two OpenAI-compatible providers for failover tests, as a free and a paid one.
 */
function fakeCompatibleProviders(): void
{
    config([
        'ai.providers.free-one' => ['driver' => 'openai-compatible', 'url' => 'https://free.test/v1', 'key' => 'free-key', 'models' => ['text' => ['default' => 'free-model']]],
        'ai.providers.paid-one' => ['driver' => 'openai-compatible', 'url' => 'https://paid.test/v1', 'key' => 'paid-key', 'models' => ['text' => ['default' => 'paid-model']]],
        'socialhq.ai.approved_providers' => ['free-one', 'paid-one'],
    ]);
}

/**
 * @return array<string, mixed>
 */
function chatCompletion(string $content, string $model = 'shq-write'): array
{
    return [
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'model' => $model,
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3, 'total_tokens' => 15],
    ];
}

function useLiteLlm(): void
{
    config([
        'socialhq.ai.gateway' => 'litellm',
        'socialhq.ai.approved_providers' => ['litellm', 'litellm-images'],
        'socialhq.ai.litellm.url' => 'http://litellm.test/v1',
        'socialhq.ai.litellm.key' => 'sk-virtual-key',
        'socialhq.ai.litellm.tasks' => ['write', 'structured', 'vision', 'image', 'embed'],
    ]);
    AiRouter::registerGateway();
    app(AiManager::class)->forgetInstance([AiRouter::LITELLM, AiRouter::LITELLM_IMAGES]);

    // Gateway calls run for a company: this one may use the platform's AI.
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $owner->update(['current_workspace_id' => $workspace->id]);
    Company::factory()->usingPlatformAi()->create(['account_id' => $owner->account_id]);
    test()->actingAs($owner);
}

test('direct routing uses the SDK default provider for text and Gemini for images', function () {
    expect(AiRouter::route(AiTask::Write))->toBe(['openai' => null])
        ->and(AiRouter::route(AiTask::Image))->toBe(['gemini' => null])
        ->and(AiRouter::isAvailable(AiTask::Write))->toBeTrue();
});

test('a route lists targets in order and keeps model ids that contain colons', function () {
    config(['socialhq.ai.routes.write' => 'anthropic:claude-haiku-5-5, openai:ft:gpt-6-mini:acme , anthropic:ignored-duplicate']);

    expect(AiRouter::route(AiTask::Write))->toBe([
        'anthropic' => 'claude-haiku-5-5',
        'openai' => 'ft:gpt-6-mini:acme',
    ]);
});

test('a provider that is not approved is never used, even with a key', function () {
    config([
        'socialhq.ai.routes.write' => 'mistral:mistral-small, openai',
        'ai.providers.mistral.key' => 'free-tier-key',
    ]);

    expect(AiRouter::route(AiTask::Write))->toBe(['openai' => null]);

    config(['socialhq.ai.routes.write' => 'mistral:mistral-small']);

    expect(AiRouter::isAvailable(AiTask::Write))->toBeFalse()
        ->and(fn () => AiRouter::route(AiTask::Write))->toThrow(AiUnavailableException::class);
});

test('a provider without the task\'s capability is dropped from its route', function () {
    config(['socialhq.ai.routes.image' => 'anthropic, gemini:gemini-3.1-flash-image-preview']);

    expect(AiRouter::route(AiTask::Image))->toBe(['gemini' => 'gemini-3.1-flash-image-preview'])
        ->and(collect(AiRouter::explain(AiTask::Image))->firstWhere('provider', 'anthropic'))
        ->toMatchArray(['used' => false, 'reason' => 'no image support']);
});

test('targets without a key are skipped while another has one', function () {
    config([
        'socialhq.ai.routes.write' => 'anthropic, openai',
        'ai.providers.anthropic.key' => null,
    ]);

    expect(AiRouter::route(AiTask::Write))->toBe(['openai' => null]);

    config(['ai.providers.openai.key' => null]);

    expect(AiRouter::isAvailable(AiTask::Write))->toBeFalse()
        ->and(AiRouter::route(AiTask::Write))->toBe(['anthropic' => null, 'openai' => null]);
});

test('an agent of the TryPost base calls its task\'s LiteLLM model group', function () {
    useLiteLlm();
    Http::fake(['litellm.test/v1/chat/completions' => Http::response(chatCompletion('Short caption.'))]);

    $response = (new PostContentShortener('X', 280))->prompt('A caption that is far too long.');

    expect((string) $response)->toBe('Short caption.')
        ->and($response->meta->provider)->toBe(AiRouter::LITELLM);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://litellm.test/v1/chat/completions'
        && $request['model'] === 'shq-write'
        && $request->hasHeader('Authorization', 'Bearer sk-virtual-key'));
});

test('structured agents use the structured group with a JSON schema', function () {
    useLiteLlm();
    Http::fake(['litellm.test/v1/chat/completions' => Http::response(chatCompletion(json_encode([
        'title' => 'Dashain offers', 'body' => 'Show the festival bundle.',
    ]), 'shq-structured'))]);

    (new IdeaGenerator(Locale::DEFAULT, 'A bakery', 'Families'))->prompt('Suggest one content idea.');

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'shq-structured'
        && data_get($request->data(), 'response_format.type') === 'json_schema');
});

test('direct routing fails over to the next provider when one is rate limited', function () {
    fakeCompatibleProviders();
    config(['socialhq.ai.routes.write' => 'free-one, paid-one']);
    Http::fake([
        'free.test/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429),
        'paid.test/*' => Http::response(chatCompletion('From the paid model.', 'paid-model')),
    ]);

    $response = (new PostContentShortener('X', 280))->prompt('A caption that is far too long.');

    expect((string) $response)->toBe('From the paid model.')
        ->and($response->meta->provider)->toBe('paid-one');
    Http::assertSentCount(2);
});

test('the gateway routes each task to its model group, images through the images entry', function () {
    useLiteLlm();

    expect(AiRouter::route(AiTask::Write))->toBe([AiRouter::LITELLM => 'shq-write'])
        ->and(AiRouter::route(AiTask::Vision))->toBe([AiRouter::LITELLM => 'shq-vision'])
        ->and(AiRouter::route(AiTask::Image))->toBe([AiRouter::LITELLM_IMAGES => 'shq-image'])
        ->and(AiRouter::route(AiTask::Embed))->toBe([AiRouter::LITELLM => 'shq-embed'])
        ->and(AiRouter::sharedDriver(AiTask::Image))->toBe('openai');
});

test('without a LiteLLM key the gateway is unavailable instead of falling back to direct keys', function () {
    config([
        'socialhq.ai.gateway' => 'litellm',
        'socialhq.ai.approved_providers' => ['litellm', 'litellm-images'],
    ]);

    expect(AiRouter::isAvailable(AiTask::Write))->toBeFalse()
        ->and(fn () => AiRouter::route(AiTask::Write))->toThrow(AiUnavailableException::class);
});

test('the routes command lists every task and why a target is unused', function () {
    config(['socialhq.ai.routes.write' => 'mistral, openai']);

    $this->artisan('socialhq:ai-routes')
        ->expectsOutputToContain('Gateway: direct')
        ->expectsTable(['Task', 'Target', 'Used'], [
            ['write', 'mistral', 'no (not approved)'],
            ['write', 'openai', 'yes'],
            ['structured', 'openai', 'yes'],
            ['vision', 'openai', 'yes'],
            ['image', 'gemini', 'yes'],
            ['embed', 'openai', 'yes'],
        ])
        ->assertSuccessful();
});

test('a request to a provider that is not approved is refused before it is sent', function () {
    Http::fake();
    config(['ai.providers.groq' => ['driver' => 'groq', 'key' => 'groq-key']]);

    expect(fn () => agent(instructions: 'Say hi.')->prompt('Hi', provider: 'groq'))
        ->toThrow(AiUnavailableException::class, 'not approved');

    Http::assertNothingSent();
});

test('an approved provider still gets the request', function () {
    Http::fake(['*' => Http::response(chatCompletion('Hello'))]);
    fakeCompatibleProviders();

    expect((string) agent(instructions: 'Say hi.')->prompt('Hi', provider: 'free-one'))->toBe('Hello');

    Http::assertSentCount(1);
});

test('the gateway serves only the tasks it is told to', function () {
    useLiteLlm();
    config(['socialhq.ai.litellm.tasks' => ['write', 'structured', 'vision']]);

    expect(AiRouter::isAvailable(AiTask::Write))->toBeTrue()
        ->and(AiRouter::isAvailable(AiTask::Image))->toBeFalse()
        ->and(AiRouter::isAvailable(AiTask::Embed))->toBeFalse()
        ->and(fn () => AiRouter::route(AiTask::Image))->toThrow(AiUnavailableException::class);

    config(['socialhq.ai.litellm.tasks' => ['write', 'image']]);

    expect(AiRouter::route(AiTask::Image))->toBe([AiRouter::LITELLM_IMAGES => 'shq-image']);
});

test('the image shape follows the driver every target shares', function () {
    config(['socialhq.ai.routes.image' => 'gemini']);
    expect(AiRouter::sharedDriver(AiTask::Image))->toBe('gemini');

    config(['socialhq.ai.routes.image' => 'gemini,openai']);
    expect(AiRouter::sharedDriver(AiTask::Image))->toBeNull();

    config(['socialhq.ai.routes.image' => 'openai,gemini', 'ai.providers.openai.key' => null]);
    expect(AiRouter::sharedDriver(AiTask::Image))->toBe('gemini');
});
