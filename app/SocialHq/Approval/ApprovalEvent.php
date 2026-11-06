<?php

declare(strict_types=1);

namespace App\SocialHq\Approval;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a post's approval history: requested, approved, rejected or
 * withdrawn, by whom and from where (the app, an agent through an API key
 * or MCP grant, or the system).
 */
class ApprovalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'shq_approval_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
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
}
