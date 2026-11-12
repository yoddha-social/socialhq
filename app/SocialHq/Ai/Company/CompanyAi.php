<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\SocialHq\Secrets\EncryptedSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's own AI in LiteLLM: its team (budget) and the virtual key the
 * app calls with. The key is encrypted with SOCIALHQ_SECRETS_KEY and never
 * leaves the server: not in props, logs or exceptions.
 *
 * @property string|null $virtual_key
 */
class CompanyAi extends Model
{
    public const STATUS_ACTIVE = 'active';

    protected $table = 'shq_company_ai';

    protected $guarded = ['id'];

    protected $hidden = ['virtual_key'];

    protected function casts(): array
    {
        return [
            'virtual_key' => EncryptedSecret::class,
            'monthly_budget_usd' => 'decimal:2',
        ];
    }

    public static function forAccount(Account $account): ?self
    {
        return self::query()->where('account_id', $account->id)->first();
    }

    /**
     * The LiteLLM team id of an account. Fixed, so LiteLLM objects can be
     * found again from the account alone.
     */
    public static function teamIdFor(Account $account): string
    {
        return "shq-co-{$account->id}";
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_ACTIVE && filled($this->litellm_team_id) && filled($this->getRawOriginal('virtual_key'));
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
