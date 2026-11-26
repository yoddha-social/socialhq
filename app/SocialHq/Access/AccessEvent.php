<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to who may do what in a company (AccessLog).
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $workspace_id
 * @property string|null $actor_id
 * @property AccessEventAction $action
 * @property string|null $role_id
 * @property string|null $member_id
 * @property array<string, mixed>|null $data
 */
class AccessEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'shq_access_events';

    /**
     * Written by AccessLog only, never from a request.
     *
     * @var list<string>
     */
    protected $fillable = ['account_id', 'workspace_id', 'actor_id', 'action', 'role_id', 'member_id', 'data'];

    protected function casts(): array
    {
        return [
            'action' => AccessEventAction::class,
            'data' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
