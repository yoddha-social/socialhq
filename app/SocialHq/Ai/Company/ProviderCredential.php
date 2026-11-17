<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\Models\User;
use App\SocialHq\Ai\AiTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider key a company added. The key itself lives only in LiteLLM (a
 * credential, encrypted with its salt key); this row keeps which provider,
 * the owner's name for it, its plan (free or paid), the tasks it serves and
 * any model chosen per task, its last four characters, its test status and
 * its place in the fallback order (lowest position first). A company may
 * have several keys for one provider.
 */
class ProviderCredential extends Model
{
    use HasFactory;

    protected $table = 'shq_provider_credentials';

    protected $guarded = ['id'];

    protected $attributes = [
        'tier' => CredentialTier::DEFAULT->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => CredentialStatus::class,
            'tier' => CredentialTier::class,
            'options' => 'array',
            'tasks' => 'array',
            'models' => 'array',
            'deployments' => 'array',
            'last_tested_at' => 'datetime',
            'data_confirmed_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAccount(Builder $query, Account $account): Builder
    {
        return $query->where('account_id', $account->id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInFallbackOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /**
     * The catalogue entry for this provider, or null when it was taken out
     * of the catalogue (its deployments are then never added again).
     */
    public function catalogue(): ?ProviderCatalogue
    {
        return ProviderCatalogue::find($this->provider);
    }

    /**
     * The tasks this key serves: what its plan offers, less the tasks the
     * owner switched off. None once the provider or the plan is gone.
     *
     * @return list<AiTask>
     */
    public function servedTasks(): array
    {
        $catalogue = $this->catalogue();

        if ($catalogue === null || ! $catalogue->offers($this->tier)) {
            return [];
        }

        $chosen = $this->tasks;

        return array_values(array_filter(
            $catalogue->tasks($this->tier),
            fn (AiTask $task): bool => ! is_array($chosen) || in_array($task->value, $chosen, true),
        ));
    }

    public function serves(AiTask $task): bool
    {
        return in_array($task, $this->servedTasks(), true);
    }

    /**
     * The LiteLLM model this key calls for a task: the owner's choice, else
     * the catalogue's for its plan.
     */
    public function modelFor(AiTask $task): ?string
    {
        $catalogue = $this->catalogue();
        $chosen = data_get($this->models, $task->value);

        if ($catalogue === null) {
            return null;
        }

        return filled($chosen) ? $catalogue->fullModel((string) $chosen) : $catalogue->model($task, $this->tier);
    }

    /**
     * The model a key test calls: the first text task the key serves, else
     * its plan's first text model, so a key used only for images is still
     * checked with a short chat request.
     */
    public function testModel(): ?string
    {
        foreach ([AiTask::Write, AiTask::Structured, AiTask::Vision] as $task) {
            if ($this->serves($task)) {
                return $this->modelFor($task);
            }
        }

        $catalogue = $this->catalogue();

        foreach ([AiTask::Write, AiTask::Structured, AiTask::Vision] as $task) {
            if (filled($model = $catalogue?->model($task, $this->tier))) {
                return $model;
            }
        }

        return null;
    }

    /**
     * How the page and messages name the key: the owner's name, else the
     * provider's (keys added before names).
     */
    public function displayName(): string
    {
        return filled($this->label) ? (string) $this->label : (string) __("socialhq_ai.providers.{$this->provider}.name");
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
