<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Ai\AiUnavailableException;
use App\SocialHq\Ai\ApprovedProvidersGuard;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Company\CompanyAiManager;
use App\SocialHq\Ai\Company\Jobs\BlockCompanyAi;
use App\SocialHq\Ai\Company\Jobs\TearDownCompanyAi;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyDecisions;
use App\SocialHq\Companies\PlatformAdmins;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'trypost.self_hosted' => true,
        'socialhq.companies.required' => true,
        'socialhq.ai.litellm.url' => 'http://litellm.test/v1',
        'socialhq.ai.litellm.admin_url' => null,
        'socialhq.ai.litellm.admin_key' => 'sk-admin-test',
        'socialhq.secrets.key' => 'base64:'.base64_encode(random_bytes(32)),
    ]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->company = Company::factory()->create(['account_id' => $this->owner->account_id]);
});

/**
 * Gives the company its LiteLLM team and a Groq key.
 */
function hardeningCompanyAi(Company $company): CompanyAi
{
    $ai = CompanyAi::query()->create([
        'account_id' => $company->account_id,
        'litellm_team_id' => CompanyAi::teamIdFor($company->account),
        'virtual_key' => 'sk-company-virtual',
        'status' => CompanyAi::STATUS_ACTIVE,
    ]);
    ProviderCredential::factory()->create([
        'account_id' => $company->account_id,
        'provider' => 'groq',
        'litellm_credential_name' => CompanyAiManager::credentialName((string) $ai->litellm_team_id, 'groq'),
    ]);

    return $ai;
}

test('direct mode uses the platform\'s keys only for the platform or a company allowed them', function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['openai'],
        'ai.default' => 'openai',
        'ai.providers.openai.key' => 'test-openai-key',
    ]);

    expect(fn () => AiTenant::run($this->company->account, fn () => AiRouter::route(AiTask::Write)))->toThrow(AiUnavailableException::class)
        ->and(fn () => AiTenant::run(null, fn () => AiRouter::route(AiTask::Write)))->toThrow(AiUnavailableException::class)
        ->and(AiTenant::run(AiTenant::PLATFORM, fn () => AiRouter::route(AiTask::Write)))->toBe(['openai' => null]);

    $this->company->update(['uses_platform_ai' => true]);

    expect(AiTenant::run($this->company->account, fn () => AiRouter::route(AiTask::Write)))->toBe(['openai' => null]);
});

test('the platform\'s LiteLLM entry is refused for a company, even when code names it directly', function () {
    config([
        'socialhq.ai.gateway' => 'litellm',
        'socialhq.ai.approved_providers' => ['litellm', 'litellm-images'],
        'socialhq.ai.litellm.key' => 'sk-platform-key',
    ]);
    hardeningCompanyAi($this->company);
    $event = (object) ['provider' => new class
    {
        public function name(): string
        {
            return 'litellm';
        }
    }];
    $guard = new ApprovedProvidersGuard;

    expect(fn () => AiTenant::run($this->company->account, fn () => $guard->handle($event)))->toThrow(AiUnavailableException::class);

    AiTenant::run(AiTenant::PLATFORM, fn () => $guard->handle($event));
});

test('pausing a company blocks its LiteLLM team and restoring unblocks it', function () {
    Bus::fake([BlockCompanyAi::class]);
    $ai = hardeningCompanyAi($this->company);
    $admin = User::factory()->create();
    PlatformAdmins::grant($admin);

    CompanyDecisions::suspend($this->company, $admin, 'Unpaid invoice.');
    Bus::assertDispatched(BlockCompanyAi::class, fn (BlockCompanyAi $job): bool => $job->teamId === $ai->litellm_team_id && $job->blocked);

    CompanyDecisions::restore($this->company->fresh(), $admin);
    Bus::assertDispatched(BlockCompanyAi::class, fn (BlockCompanyAi $job): bool => $job->teamId === $ai->litellm_team_id && ! $job->blocked);

    Http::fake(['litellm.test/team/update' => Http::response(['team_id' => 'ok'])]);
    (new BlockCompanyAi((string) $ai->litellm_team_id, true))->handle(app(CompanyAiManager::class));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/team/update') && $request['blocked'] === true);
});

test('deleting a company\'s account removes its key, deployments, provider keys and team from LiteLLM', function () {
    Bus::fake([TearDownCompanyAi::class]);
    $ai = hardeningCompanyAi($this->company);
    $teamId = (string) $ai->litellm_team_id;

    $this->owner->account->delete();

    Bus::assertDispatched(TearDownCompanyAi::class, fn (TearDownCompanyAi $job): bool => $job->teamId === $teamId && $job->providers === ['groq']);

    Http::fake(['litellm.test/*' => Http::response(['ok' => true])]);
    (new TearDownCompanyAi($teamId, ['groq']))->handle(app(CompanyAiManager::class));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/key/delete') && $request['key_aliases'] === ["socialhq-{$teamId}"]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), "/credentials/{$teamId}-groq") && $request->method() === 'DELETE');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/model/delete') && $request['id'] === "{$teamId}-groq-write");
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/team/delete') && $request['team_ids'] === [$teamId]);
});

test('a provider that fails to add leaves the company\'s other providers in place', function () {
    config(['socialhq.ai.gateway' => 'litellm']);
    $ai = hardeningCompanyAi($this->company);
    $teamId = (string) $ai->litellm_team_id;

    Http::fake(function (Request $request) use ($teamId) {
        if (str_ends_with($request->url(), '/model/new') && str_starts_with((string) data_get($request->data(), 'model_info.id'), "{$teamId}-openai")) {
            return Http::response(['error' => 'down'], 500);
        }

        return Http::response(str_ends_with($request->url(), '/health/test_connection') ? ['status' => 'success', 'result' => []] : ['ok' => true]);
    });

    $this->actingAs($this->owner)
        ->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'openai', 'provider_key' => 'sk-proj-TestKey00000000000000000000000001']);

    expect(ProviderCredential::query()->pluck('provider')->all())->toBe(['groq']);

    $groqAdds = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/model/new') && data_get($pair[0]->data(), 'model_info.id') === "{$teamId}-groq-write")
        ->count();
    expect($groqAdds)->toBe(2);
});
