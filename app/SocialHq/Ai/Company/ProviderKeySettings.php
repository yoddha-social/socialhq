<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

/**
 * What the owner sets on a key, apart from the key itself: its name, its
 * plan, the tasks it serves (null: every task the plan offers) and the
 * model chosen per task (short ids, as the provider names them).
 */
final readonly class ProviderKeySettings
{
    /**
     * @param  list<string>|null  $tasks
     * @param  array<string, string>  $models
     */
    public function __construct(
        public string $label,
        public CredentialTier $tier,
        public ?array $tasks = null,
        public array $models = [],
    ) {}

    /**
     * The row's columns for these settings.
     *
     * @return array{label: string, tier: CredentialTier, tasks: list<string>|null, models: array<string, string>|null}
     */
    public function attributes(): array
    {
        return [
            'label' => $this->label,
            'tier' => $this->tier,
            'tasks' => $this->tasks,
            'models' => $this->models === [] ? null : $this->models,
        ];
    }
}
