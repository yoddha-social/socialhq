<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\RoleChanges;
use App\SocialHq\Access\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    Roles::ensureBuiltIns($this->workspace->account_id);
});

function rolesTestRole(Workspace $workspace, BuiltInRole $role): Role
{
    return Roles::builtIn($workspace->account_id, $role);
}

function rolesTestMember(Workspace $workspace, Role $role): User
{
    $member = workspaceMember($workspace, 'approval');
    Roles::assign($workspace, $member->id, $role);

    return $member->fresh();
}

/**
 * @return array{shq_role_id: string|null, is_admin: bool, requires_approval: bool}
 */
function rolesTestMembership(Workspace $workspace, User $member): array
{
    $row = DB::table('user_workspace')->where('workspace_id', $workspace->id)->where('user_id', $member->id)->first();

    return ['shq_role_id' => $row->shq_role_id, 'is_admin' => (bool) $row->is_admin, 'requires_approval' => (bool) $row->requires_approval];
}

test('every company gets the five built-in roles once', function () {
    Roles::ensureBuiltIns($this->workspace->account_id);

    $roles = Role::query()->forAccount($this->workspace->account_id)->ordered()->get();

    expect($roles->map(fn (Role $role): string => $role->key->value)->all())->toBe(['admin', 'manager', 'editor', 'designer', 'viewer'])
        ->and($roles->firstWhere('key', BuiltInRole::Admin)->grantedPermissions())->toBe(Permission::all())
        ->and($roles->firstWhere('key', BuiltInRole::Viewer)->grantedPermissions())->toBe([]);
});

test('the owner creates a custom role', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.store'), [
            'name' => '  Video   producer ',
            'description' => 'Makes the reels.',
            'permissions' => [Permission::AiUse, Permission::PlanEdit],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.banner', __('socialhq_roles.flash.created', ['name' => 'Video producer']));

    $role = Role::query()->forAccount($this->workspace->account_id)->whereNull('key')->sole();

    expect($role->name)->toBe('Video producer')
        ->and($role->description)->toBe('Makes the reels.')
        // Stored in catalog order, whatever order they were sent in.
        ->and($role->permissions)->toBe([Permission::PlanEdit, Permission::AiUse]);
});

test('only the owner changes roles', function () {
    $admin = rolesTestMember($this->workspace, rolesTestRole($this->workspace, BuiltInRole::Admin));
    $manager = rolesTestRole($this->workspace, BuiltInRole::Manager);

    $this->actingAs($admin)
        ->post(route('socialhq.roles.store'), ['name' => 'Intern', 'permissions' => []])
        ->assertForbidden();

    $this->actingAs($admin)
        ->put(route('socialhq.roles.update', $manager), ['permissions' => []])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('socialhq.roles.duplicate', $manager))
        ->assertForbidden();

    $custom = Role::factory()->create(['account_id' => $this->workspace->account_id]);

    $this->actingAs($admin)
        ->delete(route('socialhq.roles.destroy', $custom))
        ->assertForbidden();

    expect(Role::query()->whereKey($custom->id)->exists())->toBeTrue()
        ->and($manager->fresh()->grantedPermissions())->toBe(BuiltInRole::Manager->defaultPermissions());
});

test('an agent of the owner cannot change roles', function () {
    $owner = $this->owner->withAccessToken(new Laravel\Passport\AccessToken(['oauth_access_token_id' => 'agent', 'oauth_scopes' => ['mcp:use']]));

    $this->actingAs($owner)
        ->post(route('socialhq.roles.store'), ['name' => 'Intern', 'permissions' => []])
        ->assertForbidden();
});

test('a role of another company is not found', function () {
    $other = Role::factory()->create();

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $other), ['name' => 'Mine now'])
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $other))
        ->assertNotFound();

    expect($other->fresh()->name)->not->toBe('Mine now');
});

test('role names are checked', function (array $payload, string $field, string $message) {
    Role::factory()->create(['account_id' => $this->workspace->account_id, 'name' => 'Calendar manager']);

    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.store'), [...['permissions' => []], ...$payload])
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'missing' => [['name' => '   '], 'name', 'The name field is required.'],
    'too long' => [['name' => str_repeat('a', 41)], 'name', 'The name field must not be greater than 40 characters.'],
    'taken in another case' => [['name' => 'CALENDAR  Manager'], 'name', 'Another role already has this name.'],
    'a built-in name' => [['name' => 'editor'], 'name', '“editor” is the name of a built-in role. Pick another name.'],
    'owner' => [['name' => 'Owner'], 'name', '“Owner” is the name of a built-in role. Pick another name.'],
    'description too long' => [['name' => 'Intern', 'description' => str_repeat('a', 161)], 'description', 'The description field must not be greater than 160 characters.'],
]);

test('a permission set must hold what each permission needs', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.store'), ['name' => 'Approver', 'permissions' => [Permission::PostsApprove, Permission::PostsCreate]])
        ->assertSessionHasErrors(['permissions' => '“Approve posts” needs “Publish without approval”. Turn on both, or neither.']);

    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.store'), ['name' => 'Approver', 'permissions' => [Permission::PlanApprove]])
        ->assertSessionHasErrors(['permissions' => '“Approve plans” needs “Work on plans”. Turn on both, or neither.']);

    expect(Role::query()->forAccount($this->workspace->account_id)->whereNull('key')->exists())->toBeFalse();
});

test('unknown, repeated or missing permissions are refused', function (mixed $permissions, string $field) {
    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.store'), ['name' => 'Intern', 'permissions' => $permissions])
        ->assertSessionHasErrors($field);
})->with([
    'unknown' => [['posts.create', 'billing.manage'], 'permissions.1'],
    'repeated' => [['posts.create', 'posts.create'], 'permissions.0'],
    'not a list' => ['posts.create', 'permissions'],
    'missing' => [null, 'permissions'],
]);

test('a company has at most 30 custom roles', function () {
    Role::factory()->count(Role::MAX_CUSTOM)->create(['account_id' => $this->workspace->account_id]);

    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.store'), ['name' => 'One too many', 'permissions' => []])
        ->assertSessionHasErrors(['name' => __('socialhq_roles.errors.too_many', ['max' => Role::MAX_CUSTOM])]);
});

test('changing a role reaches everyone who has it at once', function () {
    $manager = rolesTestRole($this->workspace, BuiltInRole::Manager);
    $member = rolesTestMember($this->workspace, $manager);
    $invite = Invite::factory()->create(['account_id' => $this->workspace->account_id, 'shq_role_id' => $manager->id, 'workspaces' => [$this->workspace->id]]);

    expect($member->canPublishDirectlyIn($this->workspace))->toBeTrue();

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $manager), ['permissions' => [Permission::PostsCreate, Permission::PlanEdit, Permission::TeamManage]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.banner', 'Role saved. 1 person has the new access now.');

    $member = $member->fresh();

    expect($member->canPublishDirectlyIn($this->workspace))->toBeFalse()
        ->and($member->requiresApprovalIn($this->workspace))->toBeTrue()
        ->and($member->can('manageTeam', $this->workspace))->toBeTrue()
        // The flags follow the role, for code that still reads them.
        ->and(rolesTestMembership($this->workspace, $member))->toBe(['shq_role_id' => $manager->id, 'is_admin' => true, 'requires_approval' => true])
        ->and($invite->fresh()->only(['is_admin', 'requires_approval']))->toBe(['is_admin' => true, 'requires_approval' => true]);
});

test('a role that loses API keys stops its people’s keys', function () {
    $custom = Role::factory()->withPermissions([Permission::PostsCreate, Permission::ApiKeysManage])->create(['account_id' => $this->workspace->account_id]);
    $member = rolesTestMember($this->workspace, $custom);
    passportToken($member, $this->workspace);

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $custom), ['permissions' => [Permission::PostsCreate]])
        ->assertSessionHasNoErrors();

    expect(AccessToken::query()->where('user_id', $member->id)->where('revoked', false)->exists())->toBeFalse();
});

test('built-in roles keep their name, and Admin keeps every permission', function () {
    $editor = rolesTestRole($this->workspace, BuiltInRole::Editor);
    $admin = rolesTestRole($this->workspace, BuiltInRole::Admin);

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $editor), ['name' => 'Writer'])
        ->assertSessionHasErrors(['name' => __('socialhq_roles.errors.built_in_name')]);

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $admin), ['permissions' => [Permission::PostsCreate]])
        ->assertSessionHasErrors(['permissions' => __('socialhq_roles.errors.fixed')]);

    expect($editor->fresh()->name)->toBe('Editor')
        ->and($admin->fresh()->grantedPermissions())->toBe(Permission::all());
});

test('a changed built-in role shows its defaults, and saving them resets it', function () {
    $designer = rolesTestRole($this->workspace, BuiltInRole::Designer);

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $designer), ['permissions' => [Permission::PlanEdit]])
        ->assertSessionHasNoErrors();

    expect($designer->fresh()->isChangedFromDefault())->toBeTrue();

    $this->actingAs($this->owner)
        ->get(route('socialhq.roles.show', $designer))
        ->assertInertia(fn ($page) => $page
            ->component('socialhq/roles/Show')
            ->where('roleId', $designer->id)
            ->where('roles', fn ($roles) => collect($roles)->firstWhere('id', $designer->id)['changed'] === true
                && collect($roles)->firstWhere('id', $designer->id)['defaults'] === BuiltInRole::Designer->defaultPermissions()
                && collect($roles)->firstWhere('key', 'admin')['defaults'] === null));

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $designer), ['permissions' => BuiltInRole::Designer->defaultPermissions()])
        ->assertSessionHasNoErrors();

    expect($designer->fresh()->grantedPermissions())->toBe(BuiltInRole::Designer->defaultPermissions())
        ->and($designer->fresh()->isChangedFromDefault())->toBeFalse();
});

test('a custom role is renamed, and its name stays unique', function () {
    $custom = Role::factory()->create(['account_id' => $this->workspace->account_id, 'name' => 'Intern']);
    Role::factory()->create(['account_id' => $this->workspace->account_id, 'name' => 'Reviewer']);

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $custom), ['name' => 'reviewer'])
        ->assertSessionHasErrors(['name' => 'Another role already has this name.']);

    // Its own name in another case is fine.
    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $custom), ['name' => 'INTERN', 'description' => ''])
        ->assertSessionHasNoErrors();

    expect($custom->fresh()->name)->toBe('INTERN')
        ->and($custom->fresh()->description)->toBeNull();
});

test('built-in roles cannot be deleted', function () {
    $viewer = rolesTestRole($this->workspace, BuiltInRole::Viewer);

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $viewer))
        ->assertSessionHasErrors(['role' => __('socialhq_roles.errors.built_in_delete')]);

    expect($viewer->fresh())->not->toBeNull();
});

test('a role in use is deleted only by moving its people and invites to another role', function () {
    $custom = Role::factory()->withPermissions([Permission::PostsCreate, Permission::PostsPublish])->create(['account_id' => $this->workspace->account_id]);
    $member = rolesTestMember($this->workspace, $custom);
    $invite = Invite::factory()->create(['account_id' => $this->workspace->account_id, 'shq_role_id' => $custom->id, 'workspaces' => [$this->workspace->id]]);
    $editor = rolesTestRole($this->workspace, BuiltInRole::Editor);

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $custom))
        ->assertSessionHasErrors(['move_to' => __('socialhq_roles.errors.move_to_required')]);

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $custom), ['move_to' => $custom->id])
        ->assertSessionHasErrors(['move_to' => __('socialhq_roles.errors.move_to_invalid')]);

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $custom), ['move_to' => Role::factory()->create()->id])
        ->assertSessionHasErrors(['move_to' => __('socialhq_roles.errors.move_to_invalid')]);

    expect($custom->fresh())->not->toBeNull();

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $custom), ['move_to' => $editor->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.banner', 'Role deleted. 1 person moved to Editor.');

    expect($custom->fresh())->toBeNull()
        ->and(rolesTestMembership($this->workspace, $member))->toBe(['shq_role_id' => $editor->id, 'is_admin' => false, 'requires_approval' => true])
        ->and($invite->fresh()->shq_role_id)->toBe($editor->id)
        ->and($member->fresh()->canPublishDirectlyIn($this->workspace))->toBeFalse();
});

test('an unused custom role is deleted at once', function () {
    $custom = Role::factory()->create(['account_id' => $this->workspace->account_id]);

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $custom))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.banner', 'Role deleted.');

    expect($custom->fresh())->toBeNull();
});

test('role routes need a uuid', function () {
    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', 'not-a-uuid'), ['name' => 'x'])
        ->assertStatus(Response::HTTP_NOT_FOUND);
});

test('accepted invites don’t keep a deleted role alive, and a role in use can’t be deleted behind the app’s back', function () {
    $custom = Role::factory()->create(['account_id' => $this->workspace->account_id]);
    $accepted = Invite::factory()->create(['account_id' => $this->workspace->account_id, 'shq_role_id' => $custom->id, 'accepted_at' => now()]);

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $custom))
        ->assertSessionHasNoErrors();

    expect($custom->fresh())->toBeNull()
        ->and($accepted->fresh()->shq_role_id)->toBeNull();

    // The foreign key refuses to orphan a membership (it would fall back to its flags).
    $inUse = Role::factory()->create(['account_id' => $this->workspace->account_id]);
    rolesTestMember($this->workspace, $inUse);

    expect(fn () => DB::transaction(fn () => $inUse->delete()))->toThrow(QueryException::class);
});

test('two roles can’t share a name even when created at once', function () {
    RoleChanges::create($this->workspace->account_id, 'Intern', null, []);

    expect(fn () => RoleChanges::create($this->workspace->account_id, 'intern', null, []))
        ->toThrow(ValidationException::class);
});
