<?php

declare(strict_types=1);

namespace App\SocialHq\Notices;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in a person's notification bell: something that happened in a
 * workspace they belong to. The bell shows the last week; rows go after a month.
 *
 * @property int $id
 * @property string $user_id
 * @property string $workspace_id
 * @property NoticeKind $kind
 * @property string|null $post_id
 * @property string|null $social_account_id
 * @property array<string, mixed> $data
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface $created_at
 */
class Notice extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** The bell lists notices this recent. */
    public const int LISTED_DAYS = 7;

    /** Rows older than this are deleted (`model:prune`, daily). */
    public const int RETENTION_DAYS = 30;

    /** The bell never lists more than this many. */
    public const int LISTED_LIMIT = 50;

    protected $table = 'shq_notices';

    /**
     * Written by Notices only, never from a request.
     *
     * @var list<string>
     */
    protected $fillable = ['user_id', 'workspace_id', 'kind', 'post_id', 'social_account_id', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'kind' => NoticeKind::class,
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * A person's notices in one workspace, as the bell lists them.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeListedFor(Builder $query, User $user, Workspace $workspace): Builder
    {
        return $query
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->subDays(self::LISTED_DAYS));
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
