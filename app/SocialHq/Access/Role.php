<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\Account;
use Database\Factories\SocialHq\Access\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A company's set of permissions, given to members per workspace
 * (`user_workspace.shq_role_id`) and to invites (`invites.shq_role_id`).
 * Built-in roles carry a key (BuiltInRole); custom ones don't.
 *
 * @property string $id
 * @property string $account_id
 * @property BuiltInRole|null $key
 * @property string $name
 * @property string|null $description
 * @property list<string> $permissions
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasUuids;

    /** Custom roles a company may have, besides the built-in ones. */
    public const MAX_CUSTOM = 30;

    public const NAME_MAX = 40;

    public const DESCRIPTION_MAX = 160;

    protected $table = 'shq_roles';

    /**
     * Key, account and permissions are written by RoleActions and Roles only (forceFill).
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'key' => BuiltInRole::class,
            'permissions' => 'array',
            'position' => 'integer',
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
     * The name, and its normalized form for the unique index.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'name' => Str::squish($value),
                'normalized_name' => self::normalizeName($value),
            ],
        );
    }

    public static function normalizeName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    /**
     * @param  Builder<Role>  $query
     */
    public function scopeForAccount(Builder $query, string $accountId): void
    {
        $query->where('account_id', $accountId);
    }

    /**
     * Built-in roles first, in their order, then custom ones by name.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('normalized_name');
    }

    public function isBuiltIn(): bool
    {
        return $this->key !== null;
    }

    public function isFixed(): bool
    {
        return $this->key?->isFixed() ?? false;
    }

    /**
     * The permissions it grants: Admin always all of them, others what is
     * stored, known keys only.
     *
     * @return list<string>
     */
    public function grantedPermissions(): array
    {
        if ($this->isFixed()) {
            return Permission::all();
        }

        return Permission::normalize($this->permissions ?? []);
    }

    public function grants(string $permission): bool
    {
        return in_array($permission, $this->grantedPermissions(), true);
    }

    /**
     * Whether every permission of this role is also in $permissions: what one
     * person may hand out to another (no one gives more than they have).
     *
     * @param  list<string>  $permissions
     */
    public function fitsWithin(array $permissions): bool
    {
        return array_diff($this->grantedPermissions(), $permissions) === [];
    }

    /**
     * Whether its stored permissions differ from the built-in defaults.
     */
    public function isChangedFromDefault(): bool
    {
        if ($this->key === null || $this->isFixed()) {
            return false;
        }

        $defaults = $this->key->defaultPermissions();
        $current = $this->grantedPermissions();
        sort($defaults);
        sort($current);

        return $defaults !== $current;
    }

    /**
     * How many memberships (in any workspace of its company) have this role.
     */
    public function membershipCount(): int
    {
        return DB::table('user_workspace')->where('shq_role_id', $this->id)->count();
    }

    /**
     * Invites not yet accepted that join with this role.
     */
    public function pendingInviteCount(): int
    {
        return DB::table('invites')->where('shq_role_id', $this->id)->whereNull('accepted_at')->count();
    }
}
