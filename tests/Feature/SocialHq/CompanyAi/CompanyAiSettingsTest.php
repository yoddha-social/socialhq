<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Company\CompanyAiEvent;
use App\SocialHq\Ai\Company\CompanyAiManager;
use App\SocialHq\Ai\Company\CompanyRoute;
use App\SocialHq\Ai\Company\CompanyRouting;
use App\SocialHq\Ai\Company\CredentialStatus;
use App\SocialHq\Ai\Company\CredentialTier;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Companies\Company;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

const COMPANY_AI_PROVIDER_KEY = 'gsk_TestProviderKey000000000000000001';
const COMPANY_AI_VIRTUAL_KEY = 'sk-team-virtual-key-9999';

beforeEach(function () {
    config([
        'socialhq.ai.gateway' => 'litellm',
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
 * LiteLLM's admin API, faked. A key test passes unless told otherwise
 * (a closure decides per request).
 *
 * @param  bool|Closure(): bool  $keyPasses
 */
function fakeLiteLlmAdmin(bool|Closure $keyPasses = true): void
{
    Http::fake([
        'litellm.test/health/test_connection' => fn () => Http::response(value($keyPasses)
            ? ['status' => 'success', 'result' => []]
            : ['status' => 'error', 'result' => ['error' => 'litellm.AuthenticationError: GroqException - Invalid API Key '.COMPANY_AI_PROVIDER_KEY]]),
        'litellm.test/team/new' => Http::response(['team_id' => 'ok']),
        'litellm.test/team/update' => Http::response(['team_id' => 'ok']),
        'litellm.test/team/info*' => Http::response(['team_info' => ['spend' => 1.25, 'max_budget' => 20, 'budget_reset_at' => null]]),
        'litellm.test/key/delete' => Http::response(['deleted_keys' => []]),
        'litellm.test/key/generate' => Http::response(['key' => COMPANY_AI_VIRTUAL_KEY]),
        'litellm.test/credentials*' => Http::response(['success' => true]),
        'litellm.test/model/*' => Http::response(['ok' => true]),
    ]);
}

/**
 * A store request for a free Groq key, with its name, plan and tasks.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyAiKey(array $overrides = []): array
{
    return [
        'provider' => 'groq',
        'label' => 'Groq',
        'tier' => 'free',
        'tasks' => ['write', 'structured', 'vision'],
        'provider_key' => COMPANY_AI_PROVIDER_KEY,
        ...$overrides,
    ];
}

/**
 * Every row of the company AI tables, as one string, to search for secrets.
 */
function companyAiRows(): string
{
    return json_encode([
        DB::table('shq_company_ai')->get(),
        DB::table('shq_provider_credentials')->get(),
        DB::table('shq_company_ai_events')->get(),
    ]);
}

test('the owner adds a provider: the key is tested first, sent only to LiteLLM, and never stored or logged', function () {
    fakeLiteLlmAdmin();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    $this->actingAs($this->owner)
        ->post(route('socialhq.ai-settings.providers.store'), companyAiKey())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $credential = ProviderCredential::query()->sole();
    expect($credential->provider)->toBe('groq')
        ->and($credential->label)->toBe('Groq')
        ->and($credential->tier)->toBe(CredentialTier::Free)
        ->and($credential->tasks)->toBeNull()
        ->and($credential->last4)->toBe('0001')
        ->and($credential->status)->toBe(CredentialStatus::Ok)
        ->and($credential->position)->toBe(1);

    $teamId = CompanyAi::teamIdFor($this->owner->account);
    expect(CompanyAi::forAccount($this->owner->account)->virtual_key)->toBe(COMPANY_AI_VIRTUAL_KEY)
        ->and(companyAiRows())->not->toContain(COMPANY_AI_PROVIDER_KEY)
        ->and(companyAiRows())->not->toContain(COMPANY_AI_VIRTUAL_KEY)
        ->and(implode("\n", $logged))->not->toContain(COMPANY_AI_PROVIDER_KEY);

    $urls = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->values();
    expect($urls->search(fn (string $url): bool => str_ends_with($url, '/health/test_connection')))
        ->toBeLessThan($urls->search(fn (string $url): bool => str_ends_with($url, '/credentials')));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/credentials')
        && $request['credential_values']['api_key'] === COMPANY_AI_PROVIDER_KEY
        && $request->hasHeader('Authorization', 'Bearer sk-admin-test'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/team/new')
        && $request['team_id'] === $teamId
        && in_array("shq-write@{$teamId}", $request['models'], true)
        && ! in_array('shq-write', $request['models'], true));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/model/new')
        && $request['model_name'] === "shq-write@{$teamId}"
        && $request['litellm_params']['litellm_credential_name'] === $credential->litellm_credential_name
        && str_starts_with($credential->litellm_credential_name, "{$teamId}-groq-")
        && $request['litellm_params']['input_cost_per_token'] === 0
        && $request['litellm_params']['output_cost_per_token'] === 0
        && ! array_key_exists('api_key', $request['litellm_params']));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/model/new') && str_starts_with((string) $request['model_name'], 'shq-image'));

    expect(CompanyAiEvent::query()->pluck('action')->all())->toContain(CompanyAiEvent::GATEWAY_CREATED, CompanyAiEvent::PROVIDER_ADDED);
});

test('a key that fails its test is not saved, not flashed back, and the reason is ours', function () {
    fakeLiteLlmAdmin(keyPasses: false);

    $response = $this->actingAs($this->owner)
        ->from(route('socialhq.ai-settings.show'))
        ->post(route('socialhq.ai-settings.providers.store'), companyAiKey())
        ->assertSessionHasErrors(['provider_key' => __('socialhq_ai.errors.invalid_key')]);

    expect(session()->getOldInput('provider_key'))->toBeNull()
        ->and(session()->getOldInput('provider'))->toBe('groq')
        ->and(json_encode(session()->get('errors')?->getBag('default')->toArray()))->not->toContain(COMPANY_AI_PROVIDER_KEY)
        ->and(ProviderCredential::query()->count())->toBe(0)
        ->and(CompanyAi::query()->count())->toBe(0)
        ->and(companyAiRows())->not->toContain(COMPANY_AI_PROVIDER_KEY)
        ->and(CompanyAiEvent::query()->sole()->action)->toBe(CompanyAiEvent::PROVIDER_REFUSED);

    $response->assertRedirect(route('socialhq.ai-settings.show'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/credentials') || str_contains($request->url(), '/team/'));
});

test('the catalogue asks for the data checkbox and a well-formed key before anything is sent', function (array $input, string $field) {
    Http::fake();

    $this->actingAs($this->owner)
        ->post(route('socialhq.ai-settings.providers.store'), $input)
        ->assertSessionHasErrors($field);

    Http::assertNothingSent();
})->with([
    'Mistral without the training opt-out' => [companyAiKey(['provider' => 'mistral', 'provider_key' => 'mistralTestKey000000000000000001']), 'confirmed'],
    'Gemini paid without billing' => [companyAiKey(['provider' => 'gemini', 'tier' => 'paid', 'provider_key' => 'AIzaTestKey00000000000000000000001']), 'confirmed'],
    'Gemini free without accepting training' => [companyAiKey(['provider' => 'gemini', 'provider_key' => 'AIzaTestKey00000000000000000000001']), 'confirmed'],
    'OpenRouter free without accepting training' => [companyAiKey(['provider' => 'openrouter', 'provider_key' => 'sk-or-v1-TestKey000000000000000001']), 'confirmed'],
    'an environment variable instead of a key' => [companyAiKey(['provider_key' => 'os.environ/GROQ_API_KEY']), 'provider_key'],
    'an environment variable instead of a model' => [companyAiKey(['models' => ['write' => 'os.environ/GROQ_MODEL']]), 'models.write'],
    'a model for a task the plan lacks' => [companyAiKey(['models' => ['image' => 'some-model']]), 'models'],
    'Cloudflare without its account id' => [companyAiKey(['provider' => 'cloudflare', 'provider_key' => 'cfTestToken0000000000000000000001']), 'account_id'],
    'a provider outside the catalogue' => [companyAiKey(['provider' => 'deepseek', 'provider_key' => 'sk-TestKey000000000000000000000001']), 'provider'],
    'a plan the provider lacks' => [companyAiKey(['provider' => 'openai', 'provider_key' => 'sk-proj-TestKey0000000000000002']), 'tier'],
    'a task the plan lacks' => [companyAiKey(['tasks' => ['write', 'image']]), 'tasks.1'],
    'no task' => [companyAiKey(['tasks' => []]), 'tasks'],
    'no name' => [companyAiKey(['label' => '']), 'label'],
]);

test('Mistral with the box ticked is added', function () {
    fakeLiteLlmAdmin();

    $this->actingAs($this->owner)
        ->post(route('socialhq.ai-settings.providers.store'), companyAiKey(['provider' => 'mistral', 'provider_key' => 'mistralTestKey000000000000000001', 'confirmed' => true]))
        ->assertSessionHasNoErrors();

    expect(ProviderCredential::query()->sole()->data_confirmed_at)->not->toBeNull();
});

test('only the owner of an approved company manages its AI', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, membershipPivot('admin'));
    $member->update(['current_workspace_id' => $this->workspace->id]);
    Http::fake();

    $this->actingAs($member)->get(route('socialhq.ai-settings.show'))->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.ai-settings.providers.store'), companyAiKey())->assertForbidden();

    // A paused company's owner is sent to the paused page before anything else.
    config(['trypost.self_hosted' => true, 'socialhq.companies.required' => true]);
    $this->company->forceFill(['status' => 'suspended'])->save();
    $this->actingAs($this->owner)->get(route('socialhq.ai-settings.show'))->assertRedirect(route('socialhq.company.paused'));

    Http::assertNothingSent();
});

test('another company\'s provider can\'t be touched', function () {
    $other = ProviderCredential::factory()->create();
    Http::fake();

    $this->actingAs($this->owner)->delete(route('socialhq.ai-settings.providers.destroy', $other->id))->assertForbidden();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.test', $other->id))->assertForbidden();
    $this->actingAs($this->owner)->put(route('socialhq.ai-settings.providers.update', $other->id), ['provider_key' => COMPANY_AI_PROVIDER_KEY])->assertForbidden();
    $this->actingAs($this->owner)->put(route('socialhq.ai-settings.providers.settings', $other->id), ['label' => 'Mine now', 'tier' => 'paid', 'tasks' => ['write']])->assertForbidden();

    Http::assertNothingSent();
});

test('the page shows providers by their last four characters and never a key', function () {
    fakeLiteLlmAdmin();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey());

    $response = $this->actingAs($this->owner)->get(route('socialhq.ai-settings.show'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('socialhq/settings/Ai')
        ->where('blocker', null)
        ->where('providers.0.provider', 'groq')
        ->where('providers.0.label', 'Groq')
        ->where('providers.0.tier', 'free')
        ->where('providers.0.last4', '0001')
        ->where('tasks.0', ['task' => 'write', 'provider' => 'groq', 'label' => 'Groq', 'count' => 1])
        ->where('tasks.3', ['task' => 'image', 'provider' => null, 'label' => null, 'count' => 0])
        ->where('socialhq.companyAi.canManage', true));

    expect($response->getContent())->not->toContain(COMPANY_AI_PROVIDER_KEY)
        ->and($response->getContent())->not->toContain(COMPANY_AI_VIRTUAL_KEY);
});

test('without the secrets key nothing is sent and the owner is told why', function () {
    config(['socialhq.secrets.key' => null]);
    Http::fake();

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-settings.show'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('blocker', 'secrets_key_missing'));

    $this->actingAs($this->owner)
        ->post(route('socialhq.ai-settings.providers.store'), companyAiKey())
        ->assertSessionHasErrors(['provider_key' => __('socialhq_ai.errors.secrets_key_missing')]);

    Http::assertNothingSent();
});

test('test, replace, move, remove and the budget are logged', function () {
    fakeLiteLlmAdmin();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey());
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey(['provider' => 'openai', 'label' => 'OpenAI', 'tier' => 'paid', 'tasks' => ['write', 'structured', 'vision', 'image', 'embed'], 'provider_key' => 'sk-proj-TestKey0000000000000002']));
    [$groq, $openai] = ProviderCredential::query()->inFallbackOrder()->get()->all();

    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.test', $groq->id))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->put(route('socialhq.ai-settings.providers.update', $groq->id), ['provider_key' => 'gsk_TestProviderKey000000000000000777'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.move', $openai->id), ['direction' => 'up'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->put(route('socialhq.ai-settings.budget.update'), ['monthly_budget_usd' => '15'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->delete(route('socialhq.ai-settings.providers.destroy', $groq->id))->assertSessionHasNoErrors();

    expect($openai->fresh()->position)->toBe(1)
        ->and(ProviderCredential::query()->count())->toBe(1)
        ->and((float) CompanyAi::forAccount($this->owner->account)->monthly_budget_usd)->toBe(15.0)
        ->and(CompanyAiEvent::query()->pluck('action')->all())->toContain(
            CompanyAiEvent::PROVIDER_TESTED,
            CompanyAiEvent::PROVIDER_REPLACED,
            CompanyAiEvent::PROVIDER_MOVED,
            CompanyAiEvent::BUDGET_CHANGED,
            CompanyAiEvent::PROVIDER_REMOVED,
        )
        ->and(CompanyAiEvent::query()->where('user_id', $this->owner->id)->count())->toBe(CompanyAiEvent::query()->count())
        ->and(companyAiRows())->not->toContain('gsk_TestProviderKey000000000000000777');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/team/update') && (float) $request['max_budget'] === 15.0);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), "/credentials/{$groq->litellm_credential_name}"));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), "/credentials/{$groq->litellm_credential_name}"));
});

/**
 * The /model/new requests sent, as [model_info.id => litellm_params], in order.
 *
 * @return array<string, array<string, mixed>>
 */
function companyAiDeployments(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/model/new'))
        ->mapWithKeys(fn (array $pair): array => [(string) data_get($pair[0]->data(), 'model_info.id') => ['group' => data_get($pair[0]->data(), 'model_name'), ...(array) data_get($pair[0]->data(), 'litellm_params')]])
        ->all();
}

test('a company adds several named keys for one provider, tried in the order on the page', function () {
    fakeLiteLlmAdmin();

    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey(['label' => 'Groq marketing']))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey(['label' => 'Groq sales', 'provider_key' => 'gsk_TestProviderKey000000000000000002']))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey(['label' => 'groq SALES', 'provider_key' => 'gsk_TestProviderKey000000000000000003']))
        ->assertSessionHasErrors(['label' => __('socialhq_ai.validation.label_taken')]);

    [$first, $second] = ProviderCredential::query()->inFallbackOrder()->get()->all();
    $teamId = CompanyAi::teamIdFor($this->owner->account);
    $deployments = companyAiDeployments();

    expect(ProviderCredential::query()->count())->toBe(2)
        ->and([$first->label, $second->label])->toBe(['Groq marketing', 'Groq sales'])
        ->and($first->litellm_credential_name)->not->toBe($second->litellm_credential_name)
        ->and($deployments["{$second->litellm_credential_name}-write"])->toMatchArray([
            'group' => "shq-write@{$teamId}",
            'litellm_credential_name' => $second->litellm_credential_name,
            'order' => 2,
        ])
        ->and($deployments["{$first->litellm_credential_name}-write"]['order'])->toBe(1);

    // Groq has no image or embedding model on any plan: nothing to delete there.
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/model/delete')
        && preg_match('/-(image|embed)$/', (string) $request['id']) === 1);
});

test('a change removes and adds only the deployments that differ', function () {
    fakeLiteLlmAdmin();
    $deploymentCalls = fn (int $from): array => collect(Http::recorded())->slice($from)
        ->filter(fn (array $pair): bool => preg_match('#/model/(new|delete)$#', $pair[0]->url()) === 1)
        ->map(fn (array $pair): string => basename($pair[0]->url()).' '.(data_get($pair[0]->data(), 'model_info.id') ?? data_get($pair[0]->data(), 'id')))
        ->values()->all();

    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey(['label' => 'Groq one']));
    $first = ProviderCredential::query()->sole();
    $name = $first->litellm_credential_name;

    expect($deploymentCalls(0))->toBe(["new {$name}-write", "new {$name}-structured", "new {$name}-vision"])
        ->and(array_keys($first->fresh()->deployments))->toEqualCanonicalizing(['write', 'structured', 'vision']);

    $before = count(Http::recorded());
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey(['label' => 'Groq two', 'provider_key' => 'gsk_TestProviderKey000000000000000002']));
    $second = ProviderCredential::query()->where('label', 'Groq two')->sole()->litellm_credential_name;

    // The first key is untouched; the new one is only added.
    expect($deploymentCalls($before))->toBe(["new {$second}-write", "new {$second}-structured", "new {$second}-vision"]);

    $before = count(Http::recorded());
    $this->actingAs($this->owner)
        ->put(route('socialhq.ai-settings.providers.settings', $first->id), ['label' => 'Groq one', 'tier' => 'free', 'tasks' => ['write']])
        ->assertSessionHasNoErrors();

    expect($deploymentCalls($before))->toBe(["delete {$name}-structured", "delete {$name}-vision"])
        ->and(array_keys($first->fresh()->deployments))->toEqualCanonicalizing(['write']);

    $before = count(Http::recorded());
    $this->actingAs($this->owner)->delete(route('socialhq.ai-settings.providers.destroy', $first->id));

    expect($deploymentCalls($before))->toBe(["delete {$name}-write", "delete {$second}-write", "new {$second}-write", "delete {$second}-structured", "new {$second}-structured", "delete {$second}-vision", "new {$second}-vision"]);
});

test('a key whose deployments are not known has them all removed and added once', function () {
    fakeLiteLlmAdmin();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey());
    $credential = ProviderCredential::query()->sole();
    $credential->forceFill(['deployments' => null])->save();
    $manager = app(CompanyAiManager::class);

    $before = count(Http::recorded());
    $manager->syncDeployments($this->owner->account, CompanyAi::forAccount($this->owner->account));
    $deletes = collect(Http::recorded())->slice($before)->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/model/delete'))->count();

    $before = count(Http::recorded());
    $manager->syncDeployments($this->owner->account, CompanyAi::forAccount($this->owner->account));

    expect($deletes)->toBe(3)
        ->and(array_keys($credential->fresh()->deployments))->toEqualCanonicalizing(['write', 'structured', 'vision'])
        ->and(count(Http::recorded()))->toBe($before);
});

test('a paid key counts toward the budget and a free plan offers only its own tasks', function () {
    fakeLiteLlmAdmin();

    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey([
        'provider' => 'gemini', 'label' => 'Gemini paid', 'tier' => 'paid', 'tasks' => ['write', 'image'],
        'provider_key' => 'AIzaTestKey00000000000000000000001', 'confirmed' => true,
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey([
        'provider' => 'gemini', 'label' => 'Gemini free', 'tasks' => ['write', 'image'],
        'provider_key' => 'AIzaTestKey00000000000000000000002', 'confirmed' => true,
    ]))->assertSessionHasErrors('tasks.1');

    $paid = ProviderCredential::query()->sole();
    $deployments = companyAiDeployments();

    expect($paid->tier)->toBe(CredentialTier::Paid)
        ->and($paid->tasks)->toBe(['write', 'image'])
        ->and($deployments)->toHaveKeys(["{$paid->litellm_credential_name}-write", "{$paid->litellm_credential_name}-image"])
        ->and($deployments)->not->toHaveKey("{$paid->litellm_credential_name}-structured")
        ->and($deployments["{$paid->litellm_credential_name}-image"]['model'])->toBe('gemini/gemini-3.1-flash-image')
        ->and($deployments["{$paid->litellm_credential_name}-write"])->not->toHaveKey('input_cost_per_token');

    $route = AiTenant::run($this->owner->account, fn (): CompanyRoute => CompanyRouting::current());
    expect($route->serves(AiTask::Image))->toBeTrue()
        ->and($route->serves(AiTask::Structured))->toBeFalse();
});

test('a free key on a plan that may train is added only with the box ticked', function () {
    fakeLiteLlmAdmin();

    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey([
        'provider' => 'openrouter', 'label' => 'OpenRouter free', 'provider_key' => 'sk-or-v1-TestKey000000000000000001', 'confirmed' => true,
    ]))->assertSessionHasNoErrors();

    $credential = ProviderCredential::query()->sole();

    expect($credential->data_confirmed_at)->not->toBeNull()
        ->and(companyAiDeployments()["{$credential->litellm_credential_name}-write"]['model'])->toEndWith(':free');
});

test('a model chosen for a task is tested with the key and used instead of the catalogue\'s', function () {
    fakeLiteLlmAdmin();

    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey([
        'models' => ['write' => 'meta-llama/llama-5-70b', 'vision' => 'qwen/qwen3.8-27b', 'structured' => ''],
    ]))->assertSessionHasNoErrors();

    $credential = ProviderCredential::query()->sole();
    $deployments = companyAiDeployments();

    expect($credential->models)->toBe(['write' => 'meta-llama/llama-5-70b'])
        ->and($deployments["{$credential->litellm_credential_name}-write"]['model'])->toBe('groq/meta-llama/llama-5-70b')
        ->and($deployments["{$credential->litellm_credential_name}-vision"]['model'])->toBe('groq/qwen/qwen3.8-27b');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/health/test_connection')
        && $request['litellm_params']['model'] === 'groq/meta-llama/llama-5-70b');
});

test('editing a key renames it, tests a new model with the stored key first and refuses one that fails', function () {
    $passes = true;
    fakeLiteLlmAdmin(function () use (&$passes): bool {
        return $passes;
    });
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey());
    $credential = ProviderCredential::query()->sole();

    $this->actingAs($this->owner)
        ->put(route('socialhq.ai-settings.providers.settings', $credential->id), ['label' => 'Groq main', 'tier' => 'free', 'tasks' => ['write', 'structured']])
        ->assertSessionHasNoErrors();

    expect($credential->fresh()->label)->toBe('Groq main')
        ->and($credential->fresh()->tasks)->toBe(['write', 'structured'])
        ->and(CompanyAiEvent::query()->latest('id')->first()->action)->toBe(CompanyAiEvent::PROVIDER_CHANGED);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/health/test_connection')
        && ($request['litellm_params']['litellm_credential_name'] ?? null) === $credential->litellm_credential_name);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/model/delete')
        && $request['id'] === "{$credential->litellm_credential_name}-vision");

    $passes = false;
    $sentBefore = count(Http::recorded());

    $this->actingAs($this->owner)
        ->put(route('socialhq.ai-settings.providers.settings', $credential->id), ['label' => 'Groq main', 'tier' => 'free', 'tasks' => ['write'], 'models' => ['write' => 'no-such/model']])
        ->assertSessionHasErrors(['label' => __('socialhq_ai.errors.invalid_key')]);

    $sentAfter = collect(Http::recorded())->slice($sentBefore)->values()->map(fn (array $pair): Request => $pair[0]);

    expect($credential->fresh()->models)->toBeNull()
        ->and($sentAfter->map(fn (Request $request): string => $request->url())->all())->toBe(['http://litellm.test/health/test_connection'])
        ->and($sentAfter->first()['litellm_params'])->toBe(['model' => 'groq/no-such/model', 'litellm_credential_name' => $credential->litellm_credential_name]);
});

test('moving a key to a plan with a checkbox asks for it again', function () {
    fakeLiteLlmAdmin();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), companyAiKey([
        'provider' => 'gemini', 'label' => 'Gemini', 'tier' => 'paid', 'tasks' => ['write'], 'provider_key' => 'AIzaTestKey00000000000000000000001', 'confirmed' => true,
    ]));
    $credential = ProviderCredential::query()->sole();

    $this->actingAs($this->owner)
        ->put(route('socialhq.ai-settings.providers.settings', $credential->id), ['label' => 'Gemini', 'tier' => 'free', 'tasks' => ['write']])
        ->assertSessionHasErrors(['confirmed' => __('socialhq_ai.validation.confirm_may_train')]);

    $this->actingAs($this->owner)
        ->put(route('socialhq.ai-settings.providers.settings', $credential->id), ['label' => 'Gemini', 'tier' => 'free', 'tasks' => ['write'], 'confirmed' => true])
        ->assertSessionHasNoErrors();

    expect($credential->fresh()->tier)->toBe(CredentialTier::Free);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/model/new')
        && $request['model_info']['id'] === "{$credential->litellm_credential_name}-write"
        && ($request['litellm_params']['input_cost_per_token'] ?? null) === 0);
});
