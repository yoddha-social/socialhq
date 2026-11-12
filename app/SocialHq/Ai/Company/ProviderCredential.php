<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider key a company added. The key itself lives only in LiteLLM (a
 * credential, encrypted with its salt key); this row keeps which provider,
 * its last four characters, its test status and its place in the fallback
 * order (lowest position first).
 */
class ProviderCredential extends Model
{
    use HasFactory;

    protected $table = 'shq_provider_credentials';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CredentialStatus::class,
            'options' => 'array',
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
