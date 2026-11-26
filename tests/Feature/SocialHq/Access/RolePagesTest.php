<?php

declare(strict_types=1);

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\Roles;
use Laravel\Passport\AccessToken;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Sita Owner']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Cafe']);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->workspace->members()->attach($this->owner->id, Roles::membershipColumns(Roles::builtIn($this->workspace->account_id, BuiltInRole::Admin)));
});

function rolePagesMember(Workspace $workspace, BuiltInRole|Role $role, array $attributes = []): User
{
    $member = workspaceMember($workspace, 'approval', $attributes);
    Roles::assign($workspace, $member->id, $role instanceof Role ? $role : Roles::builtIn($workspace->account_id, $role));

    return $member->fresh();
}

/**
 * @param  list<array<string, mixed>>  $roles
 * @return array<string, mixed>
 */
function rolePagesRole(array $roles, string $key): array
{
    return collect($roles)->firstWhere('key', $key) ?? collect($roles)->firstWhere('name', $key);
}

test('the owner sees every role with who has it in every workspace', function () {
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id, 'name' => 'Bakery']);
    $editor = rolePagesMember($this->workspace, BuiltInRole::Editor, ['name' => 'Rojan']);
    $other->members()->attach($editor->id, Roles::membershipColumns(Roles::builtIn($this->workspace->account_id, BuiltInRole::Manager)));
    Invite::factory()->create([
        'account_id' => $this->owner->account_id,
        'workspaces' => [$this->workspace->id],
        ...Roles::membershipColumns(Roles::builtIn($this->workspace->account_id, BuiltInRole::Viewer)),
    ]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('socialhq/roles/Index')
            ->where('canManage', true)
            ->where('scope', 'company')
            ->where('owner.id', $this->owner->id)
            ->has('catalog', 5)
            ->has('roles', 5)
            ->where('roles', function ($roles) use ($editor) {
                $roles = collect($roles)->all();

                return collect(rolePagesRole($roles, 'editor')['people'])->pluck('workspace')->all() === ['Cafe']
                    && rolePagesRole($roles, 'editor')['people'][0]['id'] === $editor->id
                    && collect(rolePagesRole($roles, 'manager')['people'])->pluck('workspace')->all() === ['Bakery']
                    // The owner is the owner, never listed under Admin.
                    && rolePagesRole($roles, 'admin')['people'] === []
                    && rolePagesRole($roles, 'viewer')['pending_invites'] === 1
                    && rolePagesRole($roles, 'editor')['description'] === __('socialhq_roles.built_in.editor');
            }));
});

test('an admin sees the roles read-only, with people of this workspace only', function () {
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $admin = rolePagesMember($this->workspace, BuiltInRole::Admin);
    $elsewhere = workspaceMember($other, 'approval');
    Roles::assign($other, $elsewhere->id, Roles::builtIn($this->workspace->account_id, BuiltInRole::Editor));

    $this->actingAs($admin)
        ->get(route('socialhq.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canManage', false)
            ->where('scope', 'workspace')
            ->where('roles', fn ($roles) => rolePagesRole(collect($roles)->all(), 'editor')['people'] === []
                && rolePagesRole(collect($roles)->all(), 'admin')['people'][0]['id'] === $admin->id
                && rolePagesRole(collect($roles)->all(), 'admin')['pending_invites'] === 0));
});

test('the roles pages need Manage members', function (string $route) {
    $editor = rolePagesMember($this->workspace, BuiltInRole::Editor);
    $role = Roles::builtIn($this->workspace->account_id, BuiltInRole::Editor);
    $url = $route === 'socialhq.roles.show' ? route($route, $role) : route($route);

    $this->actingAs($editor)->get($url)->assertForbidden();
})->with(['socialhq.roles.index', 'socialhq.roles.compare', 'socialhq.roles.show', 'socialhq.roles.assignable']);

test('one role and the compare table render for whoever gives roles', function () {
    $admin = rolePagesMember($this->workspace, BuiltInRole::Admin);
    $manager = Roles::builtIn($this->workspace->account_id, BuiltInRole::Manager);

    $this->actingAs($admin)
        ->get(route('socialhq.roles.show', $manager))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/roles/Show')->where('roleId', $manager->id));

    $this->actingAs($admin)
        ->get(route('socialhq.roles.compare'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/roles/Compare')->has('roles', 5));
});

test('a role of another company is not found', function () {
    $foreign = Role::factory()->create();

    $this->actingAs($this->owner)
        ->get(route('socialhq.roles.show', $foreign))
        ->assertNotFound();
});

test('the invite dialog gets the roles this person may give', function () {
    $lead = Role::factory()->withPermissions([Permission::PostsCreate, Permission::PlanEdit, Permission::AiUse, Permission::TeamManage])
        ->create(['account_id' => $this->workspace->account_id, 'name' => 'Team lead']);
    $member = rolePagesMember($this->workspace, $lead);

    $roles = $this->actingAs($member)
        ->getJson(route('socialhq.roles.assignable'))
        ->assertOk()
        ->json('roles');

    expect(collect($roles)->mapWithKeys(fn (array $role): array => [$role['name'] => $role['assignable']])->all())->toBe([
        'Admin' => false,
        'Manager' => false,
        'Editor' => true,
        'Designer' => true,
        'Viewer' => true,
        'Team lead' => true,
    ]);
});

test('creating a role opens it', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.store'), ['name' => 'Video producer', 'permissions' => [Permission::PlanEdit]])
        ->assertRedirect(route('socialhq.roles.show', Role::query()->where('name', 'Video producer')->sole()));
});

test('deleting a role goes back to the list', function () {
    $custom = Role::factory()->create(['account_id' => $this->workspace->account_id]);

    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $custom))
        ->assertRedirect(route('socialhq.roles.index'));
});

test('duplicating a role copies what it grants under a free name', function () {
    $manager = Roles::builtIn($this->workspace->account_id, BuiltInRole::Manager);

    $this->actingAs($this->owner)->post(route('socialhq.roles.duplicate', $manager))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.roles.duplicate', $manager))->assertSessionHasNoErrors();

    $first = Role::query()->where('name', 'Manager (copy)')->sole();
    $second = Role::query()->where('name', 'Manager (copy 2)')->sole();

    expect($first->isBuiltIn())->toBeFalse()
        ->and($first->grantedPermissions())->toBe($manager->grantedPermissions())
        ->and($first->description)->toBe(__('socialhq_roles.built_in.manager'))
        ->and($second->grantedPermissions())->toBe($manager->grantedPermissions());
});

test('a copy of a long name is cut to fit', function () {
    $long = Role::factory()->create(['account_id' => $this->workspace->account_id, 'name' => str_repeat('a', Role::NAME_MAX)]);

    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.duplicate', $long))
        ->assertSessionHasNoErrors();

    $copy = Role::query()->where('account_id', $this->workspace->account_id)->where('id', '!=', $long->id)->whereNull('key')->sole();

    expect(mb_strlen($copy->name))->toBe(Role::NAME_MAX)
        ->and($copy->name)->toEndWith(' (copy)');
});

test('duplicating stops at the custom roles limit', function () {
    Role::factory()->count(Role::MAX_CUSTOM)->create(['account_id' => $this->workspace->account_id]);
    $editor = Roles::builtIn($this->workspace->account_id, BuiltInRole::Editor);

    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.duplicate', $editor))
        ->assertSessionHasErrors('role');

    expect(Role::query()->forAccount($this->workspace->account_id)->whereNull('key')->count())->toBe(Role::MAX_CUSTOM);
});

test('only the owner, in the app, duplicates roles', function () {
    $admin = rolePagesMember($this->workspace, BuiltInRole::Admin);
    $editor = Roles::builtIn($this->workspace->account_id, BuiltInRole::Editor);
    $agent = $this->owner->withAccessToken(new AccessToken(['oauth_access_token_id' => 'agent', 'oauth_scopes' => ['mcp:use']]));

    $this->actingAs($admin)->post(route('socialhq.roles.duplicate', $editor))->assertForbidden();
    $this->actingAs($agent)->post(route('socialhq.roles.duplicate', $editor))->assertForbidden();

    expect(Role::query()->forAccount($this->workspace->account_id)->whereNull('key')->count())->toBe(0);
});
