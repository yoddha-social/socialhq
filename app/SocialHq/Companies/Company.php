<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Models\Account;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company: a TryPost account a platform admin let in. Its owner creates
 * workspaces (one per brand) and sets up the company's AI. An account
 * without a company row is not a company (an invited person's empty account).
 */
class Company extends Model
{
    use HasFactory;

    protected $table = 'shq_companies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'uses_platform_ai' => 'boolean',
            'decided_at' => 'datetime',
            'requested_at' => 'datetime',
            'admins_notified_at' => 'datetime',
        ];
    }

    public static function forAccount(?Account $account): ?self
    {
        return $account === null ? null : self::query()->where('account_id', $account->id)->first();
    }

    public function isApproved(): bool
    {
        return $this->status === CompanyStatus::Approved;
    }

    /**
     * Pending or declined: the owner waits on the waiting page.
     */
    public function isWaiting(): bool
    {
        return in_array($this->status, [CompanyStatus::Pending, CompanyStatus::Declined], true);
    }

    /**
     * When a declined company may ask again (null: it may now, or it isn't declined).
     */
    public function canAskAgainAt(): ?CarbonInterface
    {
        if ($this->status !== CompanyStatus::Declined || $this->decided_at === null) {
            return null;
        }

        $at = $this->decided_at->copy()->addHours((int) config('socialhq.companies.ask_again_after_hours', 24));

        return $at->isFuture() ? $at : null;
    }

    /**
     * @param  Builder<Company>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', CompanyStatus::Pending);
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<CompanyEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CompanyEvent::class);
    }

    /**
     * Writes one line of the company's history.
     */
    public function record(string $action, ?User $actor, ?string $note = null): void
    {
        $this->events()->create(['action' => $action, 'user_id' => $actor?->id, 'note' => $note]);
    }
}
