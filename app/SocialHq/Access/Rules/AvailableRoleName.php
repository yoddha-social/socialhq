<?php

declare(strict_types=1);

namespace App\SocialHq\Access\Rules;

use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Role;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A role name no other role of the company has (whatever its case), and not
 * a built-in role's name or "Owner", even before the built-in roles exist.
 */
final readonly class AvailableRoleName implements ValidationRule
{
    public function __construct(
        private string $accountId,
        private ?string $ignoreRoleId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $normalized = Role::normalizeName($value);
        $reserved = ['owner', ...array_map(fn (BuiltInRole $role): string => Role::normalizeName($role->defaultName()), BuiltInRole::cases())];

        if (in_array($normalized, $reserved, true)) {
            $fail(__('socialhq_roles.errors.name_reserved', ['name' => $value]));

            return;
        }

        $taken = Role::query()
            ->forAccount($this->accountId)
            ->where('normalized_name', $normalized)
            ->when($this->ignoreRoleId !== null, fn ($query) => $query->whereKeyNot($this->ignoreRoleId))
            ->exists();

        if ($taken) {
            $fail(__('socialhq_roles.errors.name_taken'));
        }
    }
}
