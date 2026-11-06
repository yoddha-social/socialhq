<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Company\CompanyAiEvent;
use App\SocialHq\Ai\Company\CredentialStatus;
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
 * LiteLLM's admin API, faked. A key test passes unless told otherwise.
 */
function fakeLiteLlmAdmin(bool $keyPasses = true): void
{
    Http::fake([
        'litellm.test/health/test_connection' => Http::response($keyPasses
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
        ->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'groq', 'provider_key' => COMPANY_AI_PROVIDER_KEY])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $credential = ProviderCredential::query()->sole();
    expect($credential->provider)->toBe('groq')
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
        && $request['litellm_params']['litellm_credential_name'] === "{$teamId}-groq"
        && ! array_key_exists('api_key', $request['litellm_params']));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/model/new') && str_starts_with((string) $request['model_name'], 'shq-image'));

    expect(CompanyAiEvent::query()->pluck('action')->all())->toContain(CompanyAiEvent::GATEWAY_CREATED, CompanyAiEvent::PROVIDER_ADDED);
});

test('a key that fails its test is not saved, not flashed back, and the reason is ours', function () {
    fakeLiteLlmAdmin(keyPasses: false);

    $response = $this->actingAs($this->owner)
        ->from(route('socialhq.ai-settings.show'))
        ->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'groq', 'provider_key' => COMPANY_AI_PROVIDER_KEY])
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
    'Mistral without the training opt-out' => [['provider' => 'mistral', 'provider_key' => 'mistralTestKey000000000000000001'], 'confirmed'],
    'Gemini without billing' => [['provider' => 'gemini', 'provider_key' => 'AIzaTestKey00000000000000000000001'], 'confirmed'],
    'an environment variable instead of a key' => [['provider' => 'groq', 'provider_key' => 'os.environ/GROQ_API_KEY'], 'provider_key'],
    'Cloudflare without its account id' => [['provider' => 'cloudflare', 'provider_key' => 'cfTestToken0000000000000000000001'], 'account_id'],
    'a provider outside the catalogue' => [['provider' => 'openrouter', 'provider_key' => 'sk-or-TestKey000000000000000001'], 'provider'],
]);

test('Mistral with the box ticked is added', function () {
    fakeLiteLlmAdmin();

    $this->actingAs($this->owner)
        ->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'mistral', 'provider_key' => 'mistralTestKey000000000000000001', 'confirmed' => true])
        ->assertSessionHasNoErrors();

    expect(ProviderCredential::query()->sole()->data_confirmed_at)->not->toBeNull();
});

test('only the owner of an approved company manages its AI', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, membershipPivot('admin'));
    $member->update(['current_workspace_id' => $this->workspace->id]);
    Http::fake();

    $this->actingAs($member)->get(route('socialhq.ai-settings.show'))->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'groq', 'provider_key' => COMPANY_AI_PROVIDER_KEY])->assertForbidden();

    // A paused company's owner is sent to the paused page before anything else.
    config(['trypost.self_hosted' => true, 'socialhq.companies.required' => true]);
    $this->company->update(['status' => 'suspended']);
    $this->actingAs($this->owner)->get(route('socialhq.ai-settings.show'))->assertRedirect(route('socialhq.company.paused'));

    Http::assertNothingSent();
});

test('another company\'s provider can\'t be touched', function () {
    $other = ProviderCredential::factory()->create();
    Http::fake();

    $this->actingAs($this->owner)->delete(route('socialhq.ai-settings.providers.destroy', $other->id))->assertForbidden();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.test', $other->id))->assertForbidden();
    $this->actingAs($this->owner)->put(route('socialhq.ai-settings.providers.update', $other->id), ['provider_key' => COMPANY_AI_PROVIDER_KEY])->assertForbidden();

    Http::assertNothingSent();
});

test('the page shows providers by their last four characters and never a key', function () {
    fakeLiteLlmAdmin();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'groq', 'provider_key' => COMPANY_AI_PROVIDER_KEY]);

    $response = $this->actingAs($this->owner)->get(route('socialhq.ai-settings.show'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('socialhq/settings/Ai')
        ->where('blocker', null)
        ->where('providers.0.provider', 'groq')
        ->where('providers.0.last4', '0001')
        ->where('tasks.0', ['task' => 'write', 'provider' => 'groq'])
        ->where('tasks.3', ['task' => 'image', 'provider' => null])
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
        ->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'groq', 'provider_key' => COMPANY_AI_PROVIDER_KEY])
        ->assertSessionHasErrors(['provider_key' => __('socialhq_ai.errors.secrets_key_missing')]);

    Http::assertNothingSent();
});

test('test, replace, move, remove and the budget are logged', function () {
    fakeLiteLlmAdmin();
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'groq', 'provider_key' => COMPANY_AI_PROVIDER_KEY]);
    $this->actingAs($this->owner)->post(route('socialhq.ai-settings.providers.store'), ['provider' => 'openai', 'provider_key' => 'sk-proj-TestKey0000000000000002']);
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
