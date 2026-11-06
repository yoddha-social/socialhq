<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Gateway;

use App\SocialHq\Ai\Company\ProviderCatalogue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * LiteLLM's admin API, for companies' AI: teams (budgets), team keys,
 * credentials (provider keys, encrypted by LiteLLM with its salt key) and
 * the deployments that use them. Checked against LiteLLM v1.106.7.
 *
 * Calls use LITELLM_ADMIN_KEY, a management key limited to these routes.
 * Writes are never retried (a retried create could leave two objects);
 * reads retry twice. Failures throw GatewayException with a status and a
 * route, never a request or response body: they carry keys.
 */
class LiteLlmAdmin
{
    public function isConfigured(): bool
    {
        return filled(config('socialhq.ai.litellm.admin_key'));
    }

    /**
     * Creates the company's team, or updates it when it already exists.
     *
     * @param  list<string>  $models  the only model groups the team's keys may call
     */
    public function saveTeam(string $teamId, string $alias, array $models, ?float $maxBudget, string $budgetDuration): void
    {
        $body = [
            'team_id' => $teamId,
            'team_alias' => $alias,
            'models' => $models,
            'max_budget' => $maxBudget,
            'budget_duration' => $budgetDuration,
        ];

        $response = $this->send('post', '/team/new', $body);

        if ($response->successful()) {
            return;
        }

        if ($response->status() === HttpStatus::HTTP_BAD_REQUEST && str_contains(mb_strtolower($response->body()), 'already exists')) {
            $this->expectSuccess($this->send('post', '/team/update', $body), '/team/update');

            return;
        }

        $this->fail($response, '/team/new');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function updateTeam(string $teamId, array $changes): void
    {
        $this->expectSuccess($this->send('post', '/team/update', ['team_id' => $teamId, ...$changes]), '/team/update');
    }

    /**
     * A new virtual key for the team. The caller encrypts it at once.
     */
    public function generateTeamKey(string $teamId, string $alias): string
    {
        $response = $this->send('post', '/key/generate', ['team_id' => $teamId, 'key_alias' => $alias]);
        $this->expectSuccess($response, '/key/generate');

        $key = $response->json('key');

        if (! is_string($key) || $key === '') {
            throw new GatewayException('gateway_error', 'LiteLLM /key/generate answered without a key.');
        }

        return $key;
    }

    public function deleteKeyByAlias(string $alias): void
    {
        $response = $this->send('post', '/key/delete', ['key_aliases' => [$alias]]);

        if ($response->successful() || $response->status() === HttpStatus::HTTP_NOT_FOUND) {
            return;
        }

        $this->fail($response, '/key/delete');
    }

    /**
     * Sends one short request with a key that is not stored anywhere yet.
     *
     * @param  array<string, string>  $options
     */
    public function testKey(ProviderCatalogue $provider, #[SensitiveParameter] string $key, array $options = []): KeyTestResult
    {
        return $this->testConnection(['model' => $provider->testModel(), ...$provider->credentialValues($key, $options)]);
    }

    /**
     * Sends one short request with a key already stored as a credential.
     */
    public function testCredential(ProviderCatalogue $provider, string $credentialName): KeyTestResult
    {
        return $this->testConnection(['model' => $provider->testModel(), 'litellm_credential_name' => $credentialName]);
    }

    /**
     * Stores (or replaces) a provider key as a LiteLLM credential.
     *
     * @param  array<string, string>  $values  api_key and, for some providers, api_base
     */
    public function saveCredential(string $name, string $litellmProvider, #[SensitiveParameter] array $values, bool $replace = false): void
    {
        $body = [
            'credential_name' => $name,
            'credential_info' => ['custom_llm_provider' => $litellmProvider, 'socialhq' => true],
            'credential_values' => $values,
        ];

        $route = $replace ? "/credentials/{$name}" : '/credentials';

        $this->expectSuccess($this->send($replace ? 'patch' : 'post', $route, $body), $replace ? '/credentials/{name}' : '/credentials');
    }

    public function deleteCredential(string $name): void
    {
        $response = $this->send('delete', "/credentials/{$name}");

        if ($response->successful() || $response->status() === HttpStatus::HTTP_NOT_FOUND) {
            return;
        }

        $this->fail($response, '/credentials/{name}');
    }

    /**
     * @param  array<string, mixed>  $litellmParams
     */
    public function addDeployment(string $id, string $modelGroup, array $litellmParams): void
    {
        $this->expectSuccess($this->send('post', '/model/new', [
            'model_name' => $modelGroup,
            'litellm_params' => $litellmParams,
            'model_info' => ['id' => $id],
        ]), '/model/new');
    }

    public function deleteDeployment(string $id): void
    {
        $response = $this->send('post', '/model/delete', ['id' => $id]);

        if ($response->successful() || in_array($response->status(), [HttpStatus::HTTP_NOT_FOUND, HttpStatus::HTTP_BAD_REQUEST], true)) {
            return;
        }

        $this->fail($response, '/model/delete');
    }

    /**
     * The team's spend this budget period, or null when LiteLLM can't say.
     *
     * @return array{spend: float, maxBudget: float|null, resetsAt: string|null}|null
     */
    public function teamSpend(string $teamId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client()->retry(2, 200, throw: false)->get('/team/info', ['team_id' => $teamId]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $budget = $response->json('team_info.max_budget');

        return [
            'spend' => (float) $response->json('team_info.spend', 0),
            'maxBudget' => is_numeric($budget) ? (float) $budget : null,
            'resetsAt' => $response->json('team_info.budget_reset_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $litellmParams
     */
    private function testConnection(#[SensitiveParameter] array $litellmParams): KeyTestResult
    {
        $response = $this->send('post', '/health/test_connection', ['mode' => 'chat', 'litellm_params' => $litellmParams]);
        $this->expectSuccess($response, '/health/test_connection');

        if ($response->json('status') === 'success') {
            return KeyTestResult::passed();
        }

        return KeyTestResult::fromProviderError((string) json_encode($response->json('result.error')));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $method, string $route, #[SensitiveParameter] array $body = []): Response
    {
        if (! $this->isConfigured()) {
            throw GatewayException::notConfigured();
        }

        try {
            return $this->client()->{$method}($route, $body);
        } catch (ConnectionException) {
            throw new GatewayException('gateway_unreachable', "LiteLLM {$route} could not be reached.");
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->adminUrl())
            ->withToken((string) config('socialhq.ai.litellm.admin_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout((int) config('socialhq.ai.litellm.admin_timeout', 20));
    }

    private function adminUrl(): string
    {
        $configured = config('socialhq.ai.litellm.admin_url');

        if (filled($configured)) {
            return rtrim((string) $configured, '/');
        }

        return rtrim((string) Uri::of((string) config('socialhq.ai.litellm.url'))->withPath(''), '/');
    }

    private function expectSuccess(Response $response, string $route): void
    {
        if (! $response->successful()) {
            $this->fail($response, $route);
        }
    }

    private function fail(Response $response, string $route): never
    {
        $key = in_array($response->status(), [HttpStatus::HTTP_UNAUTHORIZED, HttpStatus::HTTP_FORBIDDEN], true) ? 'gateway_refused' : 'gateway_error';

        throw new GatewayException($key, "LiteLLM {$route} answered HTTP {$response->status()}.");
    }
}
