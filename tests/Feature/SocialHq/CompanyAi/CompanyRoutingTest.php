<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentShortener;
use App\Enums\User\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Ai\AiTimeout;
use App\SocialHq\Ai\AiUnavailableException;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Companies\Company;
use App\SocialHq\Knowledge\Website\WebsiteReader;
use App\SocialHq\Plan\Ai\ContentPlanner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\AiManager;

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

    Http::fake(['litellm.test/v1/chat/completions' => Http::response([
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'model' => 'shq-write',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Short.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
    ])]);
});

/**
 * A company with a workspace, and optionally its own Groq key in LiteLLM.
 *
 * @return array{owner: User, workspace: Workspace, company: Company}
 */
function routingCompany(bool $usesPlatformAi = false, ?string $virtualKey = null): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $owner->update(['current_workspace_id' => $workspace->id]);
    $company = Company::factory()->create(['account_id' => $owner->account_id, 'uses_platform_ai' => $usesPlatformAi]);

    if ($virtualKey !== null) {
        CompanyAi::query()->create([
            'account_id' => $owner->account_id,
            'litellm_team_id' => CompanyAi::teamIdFor($owner->account),
            'virtual_key' => $virtualKey,
            'status' => CompanyAi::STATUS_ACTIVE,
        ]);
        ProviderCredential::factory()->create(['account_id' => $owner->account_id, 'provider' => 'groq']);
    }

    return ['owner' => $owner->fresh(), 'workspace' => $workspace, 'company' => $company];
}

function shorten(): string
{
    return (string) (new PostContentShortener('X', 280))->prompt('A caption that is far too long.');
}

test('a company with its own key calls its own group with its own key', function () {
    ['owner' => $owner] = routingCompany(usesPlatformAi: true, virtualKey: 'sk-company-a');
    $teamId = CompanyAi::teamIdFor($owner->account);

    $this->actingAs($owner);

    expect(shorten())->toBe('Short.')
        ->and(AiRouter::isAvailable(AiTask::Write))->toBeTrue()
        // Groq has no image model: the button stays hidden instead of failing.
        ->and(AiRouter::isAvailable(AiTask::Image))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request['model'] === "shq-write@{$teamId}"
        && $request->hasHeader('Authorization', 'Bearer sk-company-a'));
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-platform-key'));
});

test('a company without keys uses the platform\'s AI only when allowed', function () {
    ['owner' => $allowed] = routingCompany(usesPlatformAi: true);

    $this->actingAs($allowed);
    expect(shorten())->toBe('Short.');

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'shq-write'
        && $request->hasHeader('Authorization', 'Bearer sk-platform-key'));
});

test('a company with neither fails closed and tells the owner where to set it up', function () {
    ['owner' => $owner, 'workspace' => $workspace] = routingCompany();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);

    $this->actingAs($owner);
    expect(AiRouter::isAvailable(AiTask::Write))->toBeFalse()
        ->and(fn () => shorten())->toThrow(AiUnavailableException::class, __('socialhq_ai.unavailable.owner'));

    $this->actingAs($member);
    expect(fn () => shorten())->toThrow(AiUnavailableException::class, __('socialhq_ai.unavailable.member'));

    Http::assertNothingSent();
});

test('a suspended company has no AI, even with its own keys', function () {
    ['owner' => $owner, 'company' => $company] = routingCompany(usesPlatformAi: true, virtualKey: 'sk-company-a');
    $company->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($owner);

    expect(AiRouter::isAvailable(AiTask::Write))->toBeFalse();
});

test('jobs set the tenant themselves; it is restored after, and no key carries over', function () {
    ['workspace' => $a] = routingCompany(virtualKey: 'sk-company-a');
    ['workspace' => $b] = routingCompany(virtualKey: 'sk-company-b');

    // No signed-in user and no tenant: a job that forgot to set one fails closed.
    expect(fn () => shorten())->toThrow(AiUnavailableException::class);

    AiTenant::run($a, fn () => shorten());
    AiTenant::run($b, function () use ($a): void {
        shorten();
        AiTenant::run($a, fn () => shorten());
        shorten();
    });

    expect(AiTenant::current())->toBeNull()
        ->and(fn () => shorten())->toThrow(AiUnavailableException::class);

    $keys = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->header('Authorization')[0])->all();
    expect($keys)->toBe(['Bearer sk-company-a', 'Bearer sk-company-b', 'Bearer sk-company-a', 'Bearer sk-company-b']);
});

test('the tenant is restored when the job\'s AI call throws', function () {
    ['workspace' => $a] = routingCompany(virtualKey: 'sk-company-a');

    expect(fn () => AiTenant::run($a, function (): void {
        shorten();

        throw new RuntimeException('The provider failed.');
    }))->toThrow(RuntimeException::class)
        ->and(AiTenant::current())->toBeNull();
});

test('a company\'s gateway entry passes the approved-providers guard; other names don\'t', function () {
    ['owner' => $owner] = routingCompany(virtualKey: 'sk-company-a');

    $provider = AiTenant::run($owner->account, fn (): string => (string) array_key_first(AiRouter::route(AiTask::Write)));

    expect($provider)->toBe("litellm-co-{$owner->account_id}")
        ->and(AiRouter::isApproved($provider))->toBeTrue()
        ->and(AiRouter::isApproved('litellm-co-not-registered'))->toBeFalse();
});

test('direct mode ignores companies', function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['openai'],
        'ai.default' => 'openai',
        'ai.providers.openai.key' => 'test-openai-key',
    ]);

    expect(AiRouter::route(AiTask::Write))->toBe(['openai' => null]);
});

test('a company key no secrets key opens turns its AI off instead of failing every page', function (string $broken) {
    ['owner' => $owner] = routingCompany(virtualKey: 'sk-company-a');
    Log::spy();

    config($broken === 'missing'
        ? ['socialhq.secrets.key' => null]
        : ['socialhq.secrets.key' => 'base64:'.base64_encode(random_bytes(32)), 'socialhq.secrets.previous_keys' => []]);

    $this->actingAs($owner)->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('aiTasks.write', false));

    // Logged once for the request, not on every check.
    Log::shouldHaveReceived('warning')->once();

    expect(AiRouter::isAvailable(AiTask::Write))->toBeFalse()
        ->and(fn () => shorten())->toThrow(AiUnavailableException::class, __('socialhq_ai.unavailable.owner'));

    Http::assertNothingSent();
})->with(['missing' => ['missing'], 'rotated without the previous key' => ['rotated']]);

test('a page load decides and decrypts once', function () {
    ['owner' => $owner] = routingCompany(virtualKey: 'sk-company-a');
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries += str_contains($query->sql, 'shq_company_ai') && ! str_contains($query->sql, 'shq_company_ai_events') ? 1 : 0;
    });

    $this->actingAs($owner)->get(route('app.posts.index'))->assertOk();

    expect($queries)->toBe(1);
});

test('gateway calls say which company, workspace and task they are for', function () {
    ['owner' => $owner, 'workspace' => $workspace] = routingCompany(usesPlatformAi: true);
    ['workspace' => $own] = routingCompany(virtualKey: 'sk-company-a');

    $this->actingAs($owner);
    shorten();
    AiTenant::run($own, fn () => shorten());

    foreach ([[(string) $owner->account_id, $workspace->id], [(string) $own->account_id, $own->id]] as [$accountId, $workspaceId]) {
        Http::assertSent(fn (Request $request): bool => $request['user'] === $accountId
            && $request['metadata'] === ['spend_logs_metadata' => ['account_id' => $accountId, 'workspace_id' => $workspaceId, 'task' => 'write']]);
    }
});

test('direct providers get no attribution fields', function () {
    expect(AiRouter::gatewayAttribution(AiTask::Write, 'anthropic'))->toBe([]);
});

test('agents wait 90 seconds on the web and 300 in queued jobs', function () {
    expect((new PostContentShortener('X', 280))->timeout())->toBe(AiTimeout::WEB)
        ->and((new ContentPlanner(Locale::DEFAULT))->timeout())->toBe(AiTimeout::QUEUED)
        ->and((new WebsiteReader('English'))->timeout())->toBe(AiTimeout::QUEUED);
});
