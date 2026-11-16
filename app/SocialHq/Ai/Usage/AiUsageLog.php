<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Usage;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI call for Settings › AI › Usage (AiUsageRecorder writes them).
 *
 * An admin audit log, not metering: nothing reads it to allow, limit or bill
 * AI (LiteLLM's team budget does that). Metadata only, never a prompt or an
 * answer.
 *
 * @property int $id
 * @property string|null $account_id
 * @property string|null $workspace_id
 * @property string|null $user_id
 * @property string $feature
 * @property string|null $task
 * @property string|null $route
 * @property AiUsageStatus $status
 * @property string|null $litellm_call_id
 * @property string|null $model_group_requested
 * @property string|null $model_group
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $deployment
 * @property int $fallbacks
 * @property int $requests
 * @property float|null $cost
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $duration_ms
 * @property int|null $error_code
 * @property string|null $error_type
 * @property CarbonInterface $created_at
 */
class AiUsageLog extends Model
{
    use HasFactory;
    use MassPrunable;

    /** Rows older than this are deleted (`model:prune`, daily). */
    public const int RETENTION_DAYS = 90;

    protected $table = 'shq_ai_usage_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AiUsageStatus::class,
            'cost' => 'float',
            'fallbacks' => 'integer',
            'requests' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'duration_ms' => 'integer',
            'error_code' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAccount(Builder $query, Account|string $account): Builder
    {
        return $query->where('account_id', $account instanceof Account ? $account->id : $account);
    }

    /**
     * Calls the provider charged for (a free plan's calls cost nothing).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->where('cost', '>', 0);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeProblems(Builder $query): Builder
    {
        return $query->where('status', '!=', AiUsageStatus::Ok->value);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
