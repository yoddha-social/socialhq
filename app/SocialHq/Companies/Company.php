<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
