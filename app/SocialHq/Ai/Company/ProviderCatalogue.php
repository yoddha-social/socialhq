<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\SocialHq\Ai\AiTask;
use SensitiveParameter;

/**
 * One provider a company may bring keys for, from
 * config('socialhq.company_ai.providers'): the plans (tiers) a key may be
 * on, per plan the tasks it serves with the LiteLLM model of each and the
 * checkbox the owner must tick first, and the fields its key needs.
 */
final readonly class ProviderCatalogue
{
    /** Confirmations an owner must tick before the key is sent anywhere. */
    public const CONFIRMATIONS = ['training_off', 'billing_on', 'may_train', 'openrouter_privacy'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(public string $id, private array $config) {}

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        $providers = [];

        foreach ((array) config('socialhq.company_ai.providers', []) as $id => $config) {
            $providers[(string) $id] = new self((string) $id, (array) $config);
        }

        return $providers;
    }

    public static function find(string $id): ?self
    {
        return self::all()[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    public function litellmProvider(): string
    {
        return (string) data_get($this->config, 'litellm_provider', $this->id);
    }

    /**
     * The plans a key for this provider may be on, free first.
     *
     * @return list<CredentialTier>
     */
    public function tiers(): array
    {
        return array_values(array_filter(CredentialTier::cases(), fn (CredentialTier $tier): bool => $this->offers($tier)));
    }

    public function offers(CredentialTier $tier): bool
    {
        return is_array(data_get($this->config, "tiers.{$tier->value}"));
    }

    public function confirmation(CredentialTier $tier): ?string
    {
        $confirm = data_get($this->config, "tiers.{$tier->value}.confirm");

        return in_array($confirm, self::CONFIRMATIONS, true) ? $confirm : null;
    }

    public function needsAccountId(): bool
    {
        return in_array('account_id', (array) data_get($this->config, 'fields', []), true);
    }

    public function serves(AiTask $task, CredentialTier $tier): bool
    {
        return filled($this->model($task, $tier));
    }

    /**
     * @return list<AiTask>
     */
    public function tasks(CredentialTier $tier): array
    {
        return array_values(array_filter(AiTask::cases(), fn (AiTask $task): bool => $this->serves($task, $tier)));
    }

    /**
     * Every task any plan of this provider serves: the only tasks a key of
     * it can have deployments for, whatever plan it was on before.
     *
     * @return list<AiTask>
     */
    public function tasksOfAnyTier(): array
    {
        return array_values(array_filter(AiTask::cases(), fn (AiTask $task): bool => array_any(CredentialTier::cases(), fn (CredentialTier $tier): bool => $this->serves($task, $tier))));
    }

    /**
     * The catalogue's LiteLLM model for a task on a plan ("groq/openai/gpt-oss-120b").
     */
    public function model(AiTask $task, CredentialTier $tier): ?string
    {
        $model = data_get($this->config, "tiers.{$tier->value}.tasks.{$task->value}.model");

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * A model id as the provider names it, without LiteLLM's provider
     * prefix: what the owner sees and types.
     */
    public function shortModel(string $model): string
    {
        $prefix = "{$this->litellmProvider()}/";

        return str_starts_with($model, $prefix) ? substr($model, strlen($prefix)) : $model;
    }

    /**
     * The LiteLLM model for a model id the owner typed.
     */
    public function fullModel(string $model): string
    {
        return "{$this->litellmProvider()}/{$this->shortModel($model)}";
    }

    /**
     * What LiteLLM stores as the credential: the key, plus the API base for
     * providers whose URL holds an account id (validated before it gets here).
     *
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    public function credentialValues(#[SensitiveParameter] string $key, array $options = []): array
    {
        $values = ['api_key' => $key];

        if ($this->needsAccountId()) {
            $values['api_base'] = str_replace('{account_id}', (string) data_get($options, 'account_id'), (string) config('socialhq.company_ai.cloudflare_api_base'));
        }

        return $values;
    }

    /**
     * The litellm_params of the deployment serving one task: the provider's
     * params, then the plan's for that task (e.g. reasoning off). A free
     * plan's calls are priced at zero, so they never use up the company's
     * budget.
     *
     * @return array<string, mixed>
     */
    public function deploymentParams(AiTask $task, CredentialTier $tier, string $model, string $credentialName, int $order): array
    {
        $free = $tier === CredentialTier::Free ? ['input_cost_per_token' => 0, 'output_cost_per_token' => 0] : [];

        return array_filter([
            ...(array) data_get($this->config, 'params', []),
            ...(array) data_get($this->config, "tiers.{$tier->value}.tasks.{$task->value}.params", []),
            ...$free,
            'model' => $model,
            'litellm_credential_name' => $credentialName,
            'order' => $order,
            'timeout' => data_get($this->config, "tiers.{$tier->value}.tasks.{$task->value}.timeout"),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * For the Settings › AI page. No secrets here: the catalogue has none.
     *
     * @return array{id: string, logo: string, consoleUrl: string, steps: int, needsAccountId: bool, tiers: list<array{tier: string, confirmation: string|null, tasks: list<array{task: string, model: string}>}>}
     */
    public function toProps(): array
    {
        return [
            'id' => $this->id,
            'logo' => (string) data_get($this->config, 'logo', $this->id),
            'consoleUrl' => (string) data_get($this->config, 'console_url', ''),
            'steps' => (int) data_get($this->config, 'steps', 0),
            'needsAccountId' => $this->needsAccountId(),
            'tiers' => array_map(fn (CredentialTier $tier): array => [
                'tier' => $tier->value,
                'confirmation' => $this->confirmation($tier),
                'tasks' => array_map(fn (AiTask $task): array => [
                    'task' => $task->value,
                    'model' => $this->shortModel((string) $this->model($task, $tier)),
                ], $this->tasks($tier)),
            ], $this->tiers()),
        ];
    }
}
