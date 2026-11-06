<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a company's AI: who, what, when. Never a key or a
 * provider's error text.
 */
class CompanyAiEvent extends Model
{
    public const UPDATED_AT = null;

    public const PROVIDER_ADDED = 'provider_added';

    public const PROVIDER_REPLACED = 'provider_replaced';

    public const PROVIDER_REMOVED = 'provider_removed';

    public const PROVIDER_TESTED = 'provider_tested';

    public const PROVIDER_REFUSED = 'provider_refused';

    public const PROVIDER_MOVED = 'provider_moved';

    public const BUDGET_CHANGED = 'budget_changed';

    public const GATEWAY_CREATED = 'gateway_created';

    protected $table = 'shq_company_ai_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    /**
     * @param  array<string, scalar|null>  $details  no secrets, no provider text
     */
    public static function record(Account $account, ?User $user, string $action, ?string $provider = null, array $details = []): self
    {
        return self::query()->create([
            'account_id' => $account->id,
            'user_id' => $user?->id,
            'action' => $action,
            'provider' => $provider,
            'details' => $details === [] ? null : $details,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
