<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\SocialHq\Ai\AiTask;
use SensitiveParameter;

/**
 * One provider a company may bring a key for, from
 * config('socialhq.company_ai.providers'): which tasks it serves, the
 * LiteLLM model of each, the fields its key needs and the checkbox the
 * owner must tick first.
 */
final readonly class ProviderCatalogue
{
    /** Confirmations an owner must tick before the key is sent anywhere. */
    public const CONFIRMATIONS = ['training_off', 'billing_on'];

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

    public function confirmation(): ?string
    {
        $confirm = data_get($this->config, 'confirm');

        return in_array($confirm, self::CONFIRMATIONS, true) ? $confirm : null;
    }

    public function needsAccountId(): bool
    {
        return in_array('account_id', (array) data_get($this->config, 'fields', []), true);
    }

    public function serves(AiTask $task): bool
    {
        return filled($this->model($task));
    }

    /**
     * @return list<AiTask>
     */
    public function tasks(): array
    {
        return array_values(array_filter(AiTask::cases(), fn (AiTask $task): bool => $this->serves($task)));
    }

    public function model(AiTask $task): ?string
    {
        $model = data_get($this->config, "tasks.{$task->value}.model");

        return is_string($model) && $model !== '' ? $model : null;
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
     * The litellm_params of the deployment serving one task.
     *
     * @return array<string, mixed>
     */
    public function deploymentParams(AiTask $task, string $credentialName, int $order): array
    {
        return array_filter([
            ...(array) data_get($this->config, 'params', []),
            'model' => $this->model($task),
            'litellm_credential_name' => $credentialName,
            'order' => $order,
            'timeout' => data_get($this->config, "tasks.{$task->value}.timeout"),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The model a key test calls: the write model, which every provider has.
     */
    public function testModel(): string
    {
        return (string) ($this->model(AiTask::Write) ?? $this->model($this->tasks()[0]));
    }

    /**
     * For the Settings › AI page. No secrets here: the catalogue has none.
     *
     * @return array{id: string, logo: string, consoleUrl: string, steps: int, confirmation: string|null, needsAccountId: bool, tasks: list<string>}
     */
    public function toProps(): array
    {
        return [
            'id' => $this->id,
            'logo' => (string) data_get($this->config, 'logo', $this->id),
            'consoleUrl' => (string) data_get($this->config, 'console_url', ''),
            'steps' => (int) data_get($this->config, 'steps', 0),
            'confirmation' => $this->confirmation(),
            'needsAccountId' => $this->needsAccountId(),
            'tasks' => array_map(fn (AiTask $task): string => $task->value, $this->tasks()),
        ];
    }
}
