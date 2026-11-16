<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentShortener;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Ai\AiUnavailableException;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Ai\Usage\AiUsageLog;
use App\SocialHq\Ai\Usage\AiUsageRecorder;
use App\SocialHq\Ai\Usage\AiUsageStatus;
use App\SocialHq\Companies\Company;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    config([
        'socialhq.ai.gateway' => 'litellm',
        'socialhq.ai.approved_providers' => ['litellm', 'litellm-images'],
        'socialhq.ai.litellm.url' => 'http://litellm.test/v1',
        'socialhq.ai.litellm.key' => 'sk-platform-key',
        'socialhq.ai.litellm.tasks' => ['write', 'structured', 'vision'],
        'socialhq.ai.routes' => array_fill_keys(array_map(fn (AiTask $task): string => $task->value, AiTask::cases()), null),
        'socialhq.secrets.key' => 'base64:'.base64_encode(random_bytes(32)),
    ]);
    AiRouter::registerGateway();
    app(AiManager::class)->forgetInstance([AiRouter::LITELLM, AiRouter::LITELLM_IMAGES]);
});

/**
 * A company with a workspace, and optionally its own Groq key in LiteLLM.
 *
 * @return array{owner: User, workspace: Workspace}
 */
function usageCompany(bool $ownKey = true, bool $usesPlatformAi = false): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $owner->update(['current_workspace_id' => $workspace->id]);
    Company::factory()->create(['account_id' => $owner->account_id, 'uses_platform_ai' => $usesPlatformAi]);

    if ($ownKey) {
        CompanyAi::query()->create([
            'account_id' => $owner->account_id,
            'litellm_team_id' => CompanyAi::teamIdFor($owner->account),
            'virtual_key' => 'sk-company-key',
            'status' => CompanyAi::STATUS_ACTIVE,
        ]);
        ProviderCredential::factory()->create(['account_id' => $owner->account_id, 'provider' => 'groq']);
    }

    return ['owner' => $owner->fresh(), 'workspace' => $workspace];
}

/**
 * LiteLLM's answer, with the headers its proxy adds.
 *
 * @param  array<string, string>  $headers
 */
function fakeGateway(int $status = 200, array $headers = [], ?array $body = null): void
{
    Http::fake(['litellm.test/v1/chat/completions' => Http::response($body ?? [
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'model' => 'shq-write',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Short.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
    ], $status, $headers)]);
}

function shortenCaption(): string
{
    return (string) (new PostContentShortener('X', 280))->prompt('A caption that is far too long.');
}

test('a gateway call is logged with what LiteLLM says about it', function () {
    ['owner' => $owner, 'workspace' => $workspace] = usageCompany();
    fakeGateway(headers: [
        'x-litellm-model-name' => 'groq/llama-3.3-70b-versatile',
        'x-litellm-model-group' => 'shq-write@'.CompanyAi::teamIdFor($owner->account),
        'x-litellm-model-id' => 'deployment-1',
        'x-litellm-response-cost' => '0',
    ]);

    $this->actingAs($owner);
    expect(shortenCaption())->toBe('Short.');

    $row = AiUsageLog::query()->sole();
    expect($row->status)->toBe(AiUsageStatus::Ok)
        ->and($row->account_id)->toBe($owner->account_id)
        ->and($row->workspace_id)->toBe($workspace->id)
        ->and($row->user_id)->toBe($owner->id)
        ->and($row->feature)->toBe('post_content_shortener')
        ->and($row->task)->toBe('write')
        ->and($row->route)->toBe('own')
        ->and($row->provider)->toBe('groq')
        ->and($row->model)->toBe('llama-3.3-70b-versatile')
        ->and($row->deployment)->toBe('deployment-1')
        ->and($row->cost)->toBe(0.0)
        ->and($row->prompt_tokens)->toBe(5)
        ->and($row->completion_tokens)->toBe(1)
        ->and($row->requests)->toBe(1)
        ->and($row->duration_ms)->toBeInt();

    // LiteLLM's spend log carries the same call id and our tags.
    Http::assertSent(fn (Request $request): bool => $request->header(AiUsageRecorder::CALL_HEADER)[0] === $row->litellm_call_id
        && $request->header(AiUsageRecorder::TAGS_HEADER)[0] === "shq-feature:post_content_shortener,shq-account:{$owner->account_id}");
});

test('a fallback to the paid group and its cost are kept', function () {
    ['owner' => $owner] = usageCompany(ownKey: false, usesPlatformAi: true);
    fakeGateway(headers: [
        'x-litellm-model-name' => 'gemini/gemini-2.5-flash',
        'x-litellm-model-group' => 'shq-write-paid',
        'x-litellm-attempted-fallbacks' => '1',
        'x-litellm-response-cost' => '0.00042',
    ]);

    $this->actingAs($owner);
    shortenCaption();

    $row = AiUsageLog::query()->sole();
    expect($row->route)->toBe('platform')
        ->and($row->model_group_requested)->toBe('shq-write')
        ->and($row->model_group)->toBe('shq-write-paid')
        ->and($row->fallbacks)->toBe(1)
        ->and($row->cost)->toBe(0.00042);
});

test('a spent budget is logged as blocked, without the error text', function () {
    ['owner' => $owner] = usageCompany();
    fakeGateway(422, body: ['error' => [
        'message' => 'Budget has been exceeded! Team=shq-co-x Current cost: 5.2, Max budget: 5.0',
        'type' => 'budget_exceeded',
        'code' => '422',
    ]]);

    $this->actingAs($owner);
    expect(fn () => shortenCaption())->toThrow(Exception::class);

    $row = AiUsageLog::query()->sole();
    expect($row->status)->toBe(AiUsageStatus::BlockedBudget)
        ->and($row->error_code)->toBe(422)
        ->and($row->error_type)->toBe('budget_exceeded')
        ->and(collect($row->getAttributes())->filter(fn ($value): bool => is_string($value) && str_contains($value, 'Budget has been exceeded')))->toBeEmpty();
});

test('a rate limit is logged as rate limited', function () {
    ['owner' => $owner] = usageCompany();
    fakeGateway(429, body: ['error' => ['message' => 'No deployments available', 'type' => 'rate_limit_error', 'code' => '429']]);

    $this->actingAs($owner);
    expect(fn () => shortenCaption())->toThrow(Exception::class);

    expect(AiUsageLog::query()->sole())
        ->status->toBe(AiUsageStatus::RateLimited)
        ->error_type->toBe('rate_limit_error');
});

test('a call refused before sending is logged as blocked, with nothing sent', function () {
    ['owner' => $owner] = usageCompany(ownKey: false);
    fakeGateway();

    $this->actingAs($owner);
    expect(fn () => shortenCaption())->toThrow(AiUnavailableException::class);

    Http::assertNothingSent();
    expect(AiUsageLog::query()->sole())
        ->status->toBe(AiUsageStatus::BlockedNoRoute)
        ->feature->toBe('post_content_shortener')
        ->account_id->toBe($owner->account_id)
        ->litellm_call_id->toBeNull();
});

test('a job logs the workspace it runs for and the person who queued it', function () {
    ['owner' => $owner, 'workspace' => $workspace] = usageCompany();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    fakeGateway();

    // No signed-in user in a job: the request that queued it handed the user on.
    Context::addHidden(AiUsageRecorder::USER_CONTEXT, $owner->id);
    AiTenant::run($otherWorkspace, fn () => shortenCaption());

    expect(AiUsageLog::query()->sole())
        ->workspace_id->toBe($otherWorkspace->id)
        ->user_id->toBe($owner->id)
        ->account_id->toBe($owner->account_id);
});

test('only the gateway\'s model endpoints are tagged, during an AI call', function () {
    ['owner' => $owner] = usageCompany();
    $this->actingAs($owner);
    $recorder = app(AiUsageRecorder::class);
    $agent = new PostContentShortener('X', 280);

    $gateway = new PsrRequest('POST', 'http://litellm.test/v1/chat/completions');
    expect($recorder->tag($gateway)->hasHeader(AiUsageRecorder::CALL_HEADER))->toBeFalse();

    $recorder->starting(new PromptingAgent('inv-1', new AgentPrompt($agent, 'Hi', [], app(AiManager::class)->textProvider(AiRouter::LITELLM), 'shq-write')));

    $tagged = fn (string $url): bool => $recorder->tag(new PsrRequest('POST', $url))->hasHeader(AiUsageRecorder::CALL_HEADER)
        || $recorder->tag(new PsrRequest('POST', $url))->hasHeader(AiUsageRecorder::TAGS_HEADER);

    expect($recorder->tag($gateway)->hasHeader(AiUsageRecorder::CALL_HEADER))->toBeTrue()
        ->and($tagged('http://litellm.test/team/info'))->toBeFalse()
        ->and($tagged('https://api.groq.com/openai/v1/chat/completions'))->toBeFalse()
        ->and($tagged('http://litellm.test.evil.example/v1/chat/completions'))->toBeFalse()
        ->and($tagged('http://litellm.test:4000/v1/chat/completions'))->toBeFalse();
});

test('rows older than 90 days are pruned', function () {
    $old = AiUsageLog::factory()->create(['created_at' => now()->subDays(AiUsageLog::RETENTION_DAYS + 1)]);
    $recent = AiUsageLog::factory()->create(['created_at' => now()->subDays(AiUsageLog::RETENTION_DAYS - 1)]);

    $this->artisan('model:prune', ['--model' => [AiUsageLog::class]])->assertSuccessful();

    expect(AiUsageLog::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(AiUsageLog::query()->find($old->id))->toBeNull();
});
