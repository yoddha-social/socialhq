<?php

declare(strict_types=1);

use App\Actions\Invite\AcceptInvite;
use App\Models\AccessToken;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\MemberRoles;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\Roles;
use App\SocialHq\Access\WorkspaceAccess;
use App\SocialHq\Signup\AccessRequest;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->roles = Roles::ensureBuiltIns($this->workspace->account_id);

    // Someone who manages members but holds little else: a custom role.
    $this->teamLead = Role::factory()->withPermissions([Permission::PostsCreate, Permission::PlanEdit, Permission::AiUse, Permission::TeamManage])
        ->create(['account_id' => $this->workspace->account_id, 'name' => 'Team lead']);
});

function memberRolesTestMember(Workspace $workspace, Role $role): User
{
    $member = workspaceMember($workspace, 'approval');
    Roles::assign($workspace, $member->id, $role);

    return $member->fresh();
}

function memberRolesTestRoleId(Workspace $workspace, User $member): ?string
{
    return DB::table('user_workspace')->where('workspace_id', $workspace->id)->where('user_id', $member->id)->value('shq_role_id');
}

test('the owner gives a member a role', function () {
    $member = memberRolesTestMember($this->workspace, $this->roles['editor']);

    $this->actingAs($this->owner)
        ->put(route('app.members.update', $member), ['role_id' => $this->roles['manager']->id])
        ->assertSessionHasNoErrors();

    expect(memberRolesTestRoleId($this->workspace, $member))->toBe($this->roles['manager']->id)
        ->and($member->fresh()->canPublishDirectlyIn($this->workspace))->toBeTrue()
        ->and($member->fresh()->can('approvePosts', $this->workspace))->toBeTrue();
});

test('TryPost’s access flags still pick the matching built-in role', function (array $flags, string $expected) {
    config(['socialhq.approvals.members_always_reviewed' => false]);
    $member = memberRolesTestMember($this->workspace, $this->roles['viewer']);

    $this->actingAs($this->owner)
        ->put(route('app.members.update', $member), $flags)
        ->assertSessionHasNoErrors();

    expect(memberRolesTestRoleId($this->workspace, $member))->toBe($this->roles[$expected]->id);
})->with([
    'admin' => [['is_admin' => true, 'requires_approval' => false], 'admin'],
    'publishes directly' => [['is_admin' => false, 'requires_approval' => false], 'manager'],
    'needs approval' => [['is_admin' => false, 'requires_approval' => true], 'editor'],
]);

test('a role of another company is refused', function () {
    $member = memberRolesTestMember($this->workspace, $this->roles['editor']);
    $foreign = Role::factory()->withPermissions(Permission::all())->create();

    $this->actingAs($this->owner)
        ->put(route('app.members.update', $member), ['role_id' => $foreign->id])
        ->assertSessionHasErrors(['role_id' => __('socialhq_roles.errors.role_missing')]);

    expect(memberRolesTestRoleId($this->workspace, $member))->toBe($this->roles['editor']->id);
});

test('nobody gives a role with more access than they hold', function () {
    $lead = memberRolesTestMember($this->workspace, $this->teamLead);
    $member = memberRolesTestMember($this->workspace, $this->roles['viewer']);

    // Editor's permissions are all within Team lead's: allowed.
    $this->actingAs($lead)
        ->put(route('app.members.update', $member), ['role_id' => $this->roles['editor']->id])
        ->assertSessionHasNoErrors();

    // Manager publishes and approves, which Team lead can't: refused.
    $this->actingAs($lead)
        ->put(route('app.members.update', $member), ['role_id' => $this->roles['manager']->id])
        ->assertSessionHasErrors(['role_id' => __('socialhq_roles.errors.above_your_access')]);

    // Nor through TryPost's admin switch.
    $this->actingAs($lead)
        ->put(route('app.members.update', $member), ['is_admin' => true, 'requires_approval' => false])
        ->assertSessionHasErrors(['role_id' => __('socialhq_roles.errors.above_your_access')]);

    expect(memberRolesTestRoleId($this->workspace, $member))->toBe($this->roles['editor']->id);
});

test('nobody changes or removes someone with more access than they hold', function () {
    $lead = memberRolesTestMember($this->workspace, $this->teamLead);
    $manager = memberRolesTestMember($this->workspace, $this->roles['manager']);

    $this->actingAs($lead)
        ->put(route('app.members.update', $manager), ['role_id' => $this->roles['viewer']->id])
        ->assertSessionHasErrors(['role_id' => __('socialhq_roles.errors.member_above_your_access')]);

    $this->actingAs($lead)
        ->delete(route('app.members.remove', $manager))
        ->assertSessionHasErrors(['member' => __('socialhq_roles.errors.member_above_your_access')]);

    expect(memberRolesTestRoleId($this->workspace, $manager))->toBe($this->roles['manager']->id);
});

test('an admin may make another member admin, as before roles', function () {
    $admin = memberRolesTestMember($this->workspace, $this->roles['admin']);
    $member = memberRolesTestMember($this->workspace, $this->roles['editor']);

    $this->actingAs($admin)
        ->put(route('app.members.update', $member), ['role_id' => $this->roles['admin']->id])
        ->assertSessionHasNoErrors();

    expect(memberRolesTestRoleId($this->workspace, $member))->toBe($this->roles['admin']->id);
});

test('a member without Manage members changes nobody’s role', function () {
    $manager = memberRolesTestMember($this->workspace, $this->roles['manager']);
    $member = memberRolesTestMember($this->workspace, $this->roles['viewer']);

    $this->actingAs($manager)
        ->put(route('app.members.update', $member), ['role_id' => $this->roles['editor']->id])
        ->assertForbidden();

    expect(memberRolesTestRoleId($this->workspace, $member))->toBe($this->roles['viewer']->id);
});

test('losing API keys with a new role stops the member’s keys', function () {
    $member = memberRolesTestMember($this->workspace, $this->roles['admin']);
    passportToken($member, $this->workspace);

    $this->actingAs($this->owner)
        ->put(route('app.members.update', $member), ['role_id' => $this->roles['manager']->id])
        ->assertSessionHasNoErrors();

    expect(AccessToken::query()->where('user_id', $member->id)->where('revoked', false)->exists())->toBeFalse();
});

test('an invite carries its role, and the person joins with it', function () {
    $this->actingAs($this->owner)
        ->post(route('app.invites.store'), ['email' => 'designer@example.com', 'role_id' => $this->roles['designer']->id])
        ->assertSessionHasNoErrors();

    $invite = Invite::query()->where('email', 'designer@example.com')->sole();

    expect($invite->shq_role_id)->toBe($this->roles['designer']->id)
        ->and($invite->is_admin)->toBeFalse()
        ->and($invite->requires_approval)->toBeTrue();

    // A new sign-up's account is empty (no company), so the invite moves them in.
    $person = AccountFactory::withoutCompany(fn (): User => User::factory()->create(['email' => 'designer@example.com']));
    AcceptInvite::execute($person, $invite);

    $access = WorkspaceAccess::of($person->fresh(), $this->workspace);

    expect($access->role?->id)->toBe($this->roles['designer']->id)
        ->and($access->permissions)->toBe([Permission::PlanEdit, Permission::AiUse]);
});

test('an invite with a role above the inviter’s access is refused', function () {
    $lead = memberRolesTestMember($this->workspace, $this->teamLead);

    $this->actingAs($lead)
        ->post(route('app.invites.store'), ['email' => 'boss@example.com', 'role_id' => $this->roles['admin']->id])
        ->assertSessionHasErrors(['role_id' => __('socialhq_roles.errors.above_your_access')]);

    expect(Invite::query()->where('email', 'boss@example.com')->exists())->toBeFalse();
});

test('the members page lists each member’s role and the roles one may give', function () {
    $lead = memberRolesTestMember($this->workspace, $this->teamLead);
    $manager = memberRolesTestMember($this->workspace, $this->roles['manager']);

    $this->actingAs($lead)
        ->get(route('app.members'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('members', fn ($members) => collect($members)->firstWhere('id', $manager->id)['role']['name'] === 'Manager'
                && collect($members)->firstWhere('id', $manager->id)['manageable'] === false)
            ->where('roles', fn ($roles) => collect($roles)->firstWhere('key', 'editor')['assignable'] === true
                && collect($roles)->firstWhere('key', 'admin')['assignable'] === false
                && collect($roles)->firstWhere('name', 'Team lead')['assignable'] === true));
});

test('assignable roles are those within the giver’s permissions', function () {
    $lead = memberRolesTestMember($this->workspace, $this->teamLead);

    $ids = MemberRoles::assignableIds($lead, $this->workspace);

    expect($ids)->toContain($this->roles['editor']->id, $this->roles['designer']->id, $this->roles['viewer']->id, $this->teamLead->id)
        ->not->toContain($this->roles['admin']->id)
        ->not->toContain($this->roles['manager']->id);
});

test('approving a sign-up gives a role only to someone who holds all of it', function () {
    config(['trypost.self_hosted' => true]);
    // Manage members, nothing else: an access-request decider through the is_admin mirror.
    $recruiter = memberRolesTestMember($this->workspace, Role::factory()->withPermissions([Permission::TeamManage])
        ->create(['account_id' => $this->workspace->account_id, 'name' => 'Recruiter']));
    $person = User::factory()->create(['account_id' => $this->workspace->account_id, 'email_verified_at' => now()]);
    $request = AccessRequest::query()->create([
        'account_id' => $this->workspace->account_id,
        'user_id' => $person->id,
        'name' => $person->name,
        'email' => $person->email,
    ]);

    $this->actingAs($recruiter)
        ->post(route('socialhq.access-requests.approve', $request->id), ['workspaces' => [$this->workspace->id], 'is_admin' => false])
        ->assertSessionHasErrors(['is_admin' => __('socialhq_roles.errors.above_your_access')]);

    expect($this->workspace->members()->whereKey($person->id)->exists())->toBeFalse();
});

test('ids compare whatever their case, so nobody removes the owner or edits themselves', function () {
    $admin = memberRolesTestMember($this->workspace, $this->roles['admin']);
    $this->workspace->members()->attach($this->owner->id, Roles::membershipColumns($this->roles['admin']));

    $this->actingAs($admin)
        ->delete(route('app.members.remove', strtoupper($this->owner->id)))
        ->assertSessionHasErrors(['member' => __('settings.members.errors.cannot_remove_owner')]);

    $this->actingAs($admin)
        ->put(route('app.members.update', strtoupper($admin->id)), ['role_id' => $this->roles['viewer']->id])
        ->assertSessionHasErrors('is_admin');

    expect($this->workspace->members()->whereKey($this->owner->id)->exists())->toBeTrue()
        ->and(memberRolesTestRoleId($this->workspace, $admin))->toBe($this->roles['admin']->id);
});

test('removing someone who is not a member of the workspace is not found', function () {
    $waiting = User::factory()->create(['account_id' => $this->workspace->account_id]);

    $this->actingAs($this->owner)
        ->delete(route('app.members.remove', $waiting))
        ->assertNotFound();

    expect(User::query()->whereKey($waiting->id)->exists())->toBeTrue();
});

test('an invite whose role is not the company’s joins as Viewer, never by its flags', function () {
    $foreign = Role::factory()->withPermissions(Permission::all())->create();
    $invite = Invite::factory()->create([
        'account_id' => $this->workspace->account_id,
        'email' => 'odd@example.com',
        'workspaces' => [$this->workspace->id],
        'shq_role_id' => $foreign->id,
        'is_admin' => true,
        'requires_approval' => false,
    ]);
    // A new sign-up's account is empty (no company), so the invite moves them in.
    $person = AccountFactory::withoutCompany(fn (): User => User::factory()->create(['email' => 'odd@example.com']));

    AcceptInvite::execute($person, $invite);

    expect(WorkspaceAccess::of($person->fresh(), $this->workspace)->role?->id)->toBe($this->roles['viewer']->id);
});

test('a membership pointing at a role that is not there holds nothing', function () {
    $member = memberRolesTestMember($this->workspace, $this->teamLead);
    $pivot = $this->workspace->members()->whereKey($member->id)->first()->pivot;

    expect(WorkspaceAccess::through($member, $this->workspace, $pivot, collect())->permissions)->toBe([]);
});
