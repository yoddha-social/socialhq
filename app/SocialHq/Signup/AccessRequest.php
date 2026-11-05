<?php

declare(strict_types=1);

namespace App\SocialHq\Signup;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who signed up without an invite and waits for an admin to give
 * them workspaces (pending), or the decision about them.
 */
class AccessRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected $table = 'shq_access_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
            'admins_notified_at' => 'immutable_datetime',
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Pending requests whose email is confirmed: the ones admins act on.
     *
     * @param  Builder<AccessRequest>  $query
     */
    public function scopeAwaitingDecision(Builder $query): void
    {
        $query->where('status', self::PENDING)
            ->whereHas('user', fn (Builder $user) => $user->whereNotNull('email_verified_at'));
    }
}
