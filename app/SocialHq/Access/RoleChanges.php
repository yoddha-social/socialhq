<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The owner's changes to the company's roles. Every change to what a role
 * grants reaches everyone who has it at once (Roles::applyChange()).
 */
final class RoleChanges
{
    /**
     * @param  list<string>  $permissions
     */
    public static function create(string $accountId, string $name, ?string $description, array $permissions): Role
    {
        Roles::ensureBuiltIns($accountId);

        return DB::transaction(function () use ($accountId, $name, $description, $permissions): Role {
            // One create at a time per company, so the limit and the name check hold.
            Account::query()->whereKey($accountId)->lockForUpdate()->first();

            if (Role::query()->forAccount($accountId)->whereNull('key')->count() >= Role::MAX_CUSTOM) {
                throw ValidationException::withMessages(['name' => __('socialhq_roles.errors.too_many', ['max' => Role::MAX_CUSTOM])]);
            }

            if (Role::query()->forAccount($accountId)->where('normalized_name', Role::normalizeName($name))->exists()) {
                throw ValidationException::withMessages(['name' => __('socialhq_roles.errors.name_taken')]);
            }

            $role = new Role;
            $role->forceFill([
                'account_id' => $accountId,
                'key' => null,
                'name' => $name,
                'description' => $description,
                'permissions' => Permission::normalize($permissions),
                'position' => 100,
            ])->save();

            AccessLog::roleSaved($role, AccessEventAction::RoleCreated);

            return $role;
        });
    }

    /**
     * @param  array{name?: string, description?: string|null, permissions?: list<string>}  $changes
     */
    public static function update(Role $role, array $changes): Role
    {
        return DB::transaction(function () use ($role, $changes): Role {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            $before = $locked->grantedPermissions();
            $nameBefore = $locked->name;

            if (! $locked->isBuiltIn()) {
                if (array_key_exists('name', $changes)) {
                    $locked->name = (string) $changes['name'];
                }

                if (array_key_exists('description', $changes)) {
                    $locked->description = $changes['description'];
                }
            }

            if (array_key_exists('permissions', $changes) && ! $locked->isFixed()) {
                $locked->forceFill(['permissions' => Permission::normalize($changes['permissions'])]);
            }

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->save();
            $permissionsChanged = $locked->grantedPermissions() !== $before;

            if ($permissionsChanged) {
                Roles::applyChange($locked);
            }

            AccessLog::roleSaved($locked, AccessEventAction::RoleUpdated, $permissionsChanged ? $before : null, $nameBefore);

            return $locked;
        });
    }

    /**
     * A custom copy of a role ("Manager (copy)") with a free name, the
     * role's description and what it grants. A copy of Admin grants
     * everything, but its switches can change.
     */
    public static function duplicate(Role $source): Role
    {
        return self::create(
            $source->account_id,
            self::copyName($source),
            RoleResource::description($source),
            $source->grantedPermissions(),
        );
    }

    /**
     * "Name (copy)", then "Name (copy 2)" and on, cut to fit the name's
     * length. The company has at most MAX_CUSTOM custom roles, so a free
     * name comes within that many tries; create() checks it again under
     * the company's lock.
     */
    private static function copyName(Role $source): string
    {
        $taken = Role::query()->forAccount($source->account_id)->pluck('normalized_name')->all();

        for ($number = 1; $number <= Role::MAX_CUSTOM + 1; $number++) {
            $suffix = $number === 1
                ? __('socialhq_roles.copy_suffix')
                : __('socialhq_roles.copy_suffix_numbered', ['number' => $number]);
            $base = Str::substr($source->name, 0, Role::NAME_MAX - Str::length($suffix));
            $name = Str::squish("{$base}{$suffix}");

            if (! in_array(Role::normalizeName($name), $taken, true)) {
                return $name;
            }
        }

        throw ValidationException::withMessages(['name' => __('socialhq_roles.errors.name_taken')]);
    }

    /**
     * Deletes a custom role. Its memberships and pending invites move to
     * $moveTo first. Returns how many memberships moved.
     */
    public static function delete(Role $role, ?Role $moveTo): int
    {
        abort_if($role->isBuiltIn(), 422, __('socialhq_roles.errors.built_in_delete'));
        abort_if($moveTo !== null && ($moveTo->account_id !== $role->account_id || $moveTo->is($role)), 422, __('socialhq_roles.errors.move_to_invalid'));

        return DB::transaction(function () use ($role, $moveTo): int {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            $moved = 0;

            // Accepted invites are history: they forget the role.
            DB::table('invites')->where('shq_role_id', $locked->id)->whereNotNull('accepted_at')->update(['shq_role_id' => null]);

            if ($moveTo !== null) {
                $columns = Roles::membershipColumns($moveTo);
                $moved = DB::table('user_workspace')->where('shq_role_id', $locked->id)->update($columns);
                DB::table('invites')->where('shq_role_id', $locked->id)->update($columns);
                Roles::applyChange($moveTo);
            }

            // Anything given the role after validation, with nowhere to move it: refuse.
            abort_if(
                DB::table('user_workspace')->where('shq_role_id', $locked->id)->exists()
                    || DB::table('invites')->where('shq_role_id', $locked->id)->exists(),
                422,
                __('socialhq_roles.errors.move_to_required'),
            );

            $locked->delete();
            AccessLog::roleDeleted($locked, $moveTo, $moved);

            return $moved;
        });
    }
}
