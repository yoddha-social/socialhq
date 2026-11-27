<?php

declare(strict_types=1);

use App\Jobs\SendNotification;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\AccessEvent;
use App\SocialHq\Access\AccessEventAction;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\Roles;
use App\SocialHq\Signup\AccessRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->workspace->members()->attach($this->owner->id, Roles::membershipColumns(Roles::builtIn($this->workspace->account_id, BuiltInRole::Admin)));
});

function accessLogMember(Workspace $workspace, BuiltInRole $role): User
{
    $member = workspaceMember($workspace, 'approval', ['name' => 'Rojan Karki', 'email' => 'rojan@example.com']);
    Roles::assign($workspace, $member->id, Roles::builtIn($workspace->account_id, $role));

    return $member->fresh();
}

function accessLogLatest(AccessEventAction $action): AccessEvent
{
    return AccessEvent::query()->where('action', $action)->latest('id')->firstOrFail();
}

test('creating, changing and deleting a role is logged with who did it', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.roles.store'), ['name' => 'Intern', 'permissions' => [Permission::PlanEdit]]);
    $role = Role::query()->where('name', 'Intern')->sole();

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $role), ['name' => 'Trainee', 'permissions' => [Permission::PlanEdit, Permission::AiUse]]);
    $this->actingAs($this->owner)
        ->delete(route('socialhq.roles.destroy', $role));

    $created = accessLogLatest(AccessEventAction::RoleCreated);
    $updated = accessLogLatest(AccessEventAction::RoleUpdated);
    $deleted = accessLogLatest(AccessEventAction::RoleDeleted);

    expect($created->actor_id)->toBe($this->owner->id)
        ->and($created->account_id)->toBe($this->workspace->account_id)
        ->and($created->workspace_id)->toBeNull()
        ->and($created->data)->toBe(['role' => 'Intern', 'permissions' => [Permission::PlanEdit]])
        ->and($updated->data)->toBe([
            'role' => 'Trainee',
            'name_before' => 'Intern',
            'permissions_before' => [Permission::PlanEdit],
            'permissions' => [Permission::PlanEdit, Permission::AiUse],
        ])
        ->and($deleted->role_id)->toBe($role->id)
        ->and($deleted->data['moved'])->toBe(0);
});

test('saving a role unchanged logs nothing', function () {
    $editor = Roles::builtIn($this->workspace->account_id, BuiltInRole::Editor);

    $this->actingAs($this->owner)
        ->put(route('socialhq.roles.update', $editor), ['permissions' => $editor->grantedPermissions()])
        ->assertSessionHasNoErrors();

    expect(AccessEvent::query()->where('action', AccessEventAction::RoleUpdated)->exists())->toBeFalse();
});

test('a member’s role change is logged with the role before and after', function () {
    $member = accessLogMember($this->workspace, BuiltInRole::Editor);
    $manager = Roles::builtIn($this->workspace->account_id, BuiltInRole::Manager);

    $this->actingAs($this->owner)
        ->put(route('app.members.update', $member->id), ['role_id' => $manager->id])
        ->assertSessionHas('flash.banner', 'Rojan Karki now has the Manager role.');

    $event = accessLogLatest(AccessEventAction::MemberRoleChanged);

    expect($event->actor_id)->toBe($this->owner->id)
        ->and($event->workspace_id)->toBe($this->workspace->id)
        ->and($event->member_id)->toBe($member->id)
        ->and($event->data['member'])->toBe(['name' => 'Rojan Karki', 'email' => 'rojan@example.com'])
        ->and($event->data['from']['name'])->toBe('Editor')
        ->and($event->data['to']['name'])->toBe('Manager');
});

test('giving the role a member already has logs nothing', function () {
    $member = accessLogMember($this->workspace, BuiltInRole::Editor);
    $before = AccessEvent::query()->count();

    Roles::assign($this->workspace, $member->id, Roles::builtIn($this->workspace->account_id, BuiltInRole::Editor));

    expect(AccessEvent::query()->count())->toBe($before);
});

test('an invite is logged with its role', function () {
    Mail::fake();
    $designer = Roles::builtIn($this->workspace->account_id, BuiltInRole::Designer);

    $this->actingAs($this->owner)
        ->post(route('app.invites.store'), ['email' => 'kiran@example.com', 'role_id' => $designer->id])
        ->assertSessionHasNoErrors();

    $event = accessLogLatest(AccessEventAction::InviteSent);

    expect($event->role_id)->toBe($designer->id)
        ->and($event->data)->toBe(['email' => 'kiran@example.com', 'role' => 'Designer']);
});

test('removing a member is logged, even when it deletes their user', function () {
    $member = accessLogMember($this->workspace, BuiltInRole::Editor);

    $this->actingAs($this->owner)
        ->delete(route('app.members.remove', $member->id))
        ->assertSessionHasNoErrors();

    $event = accessLogLatest(AccessEventAction::MemberRemoved);

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and($event->member_id)->toBe($member->id)
        ->and($event->data)->toBe(['member' => ['name' => 'Rojan Karki', 'email' => 'rojan@example.com'], 'role' => 'Editor']);
});

test('approving an access request gives the role picked, and is logged', function () {
    Bus::fake([SendNotification::class]);
    $person = User::factory()->create(['account_id' => $this->owner->account_id, 'email_verified_at' => now(), 'current_workspace_id' => null]);
    $request = AccessRequest::query()->create(['account_id' => $this->owner->account_id, 'user_id' => $person->id, 'name' => $person->name, 'email' => $person->email]);
    $designer = Roles::builtIn($this->workspace->account_id, BuiltInRole::Designer);

    $this->actingAs($this->owner)
        ->post(route('socialhq.access-requests.approve', $request->id), ['workspaces' => [$this->workspace->id], 'role_id' => $designer->id])
        ->assertSessionHasNoErrors();

    $event = accessLogLatest(AccessEventAction::AccessGranted);

    expect($this->workspace->members()->whereKey($person->id)->value('user_workspace.shq_role_id'))->toBe($designer->id)
        ->and($event->member_id)->toBe($person->id)
        ->and($event->data['role'])->toBe('Designer');
});

test('an access request is never approved with a role above the approver or of another company', function () {
    $admin = accessLogMember($this->workspace, BuiltInRole::Admin);
    $lead = Role::factory()->withPermissions([Permission::PostsCreate, Permission::TeamManage])
        ->create(['account_id' => $this->workspace->account_id, 'name' => 'Lead']);
    $leadMember = workspaceMember($this->workspace, 'approval');
    Roles::assign($this->workspace, $leadMember->id, $lead);
    $person = User::factory()->create(['account_id' => $this->owner->account_id, 'email_verified_at' => now()]);
    $request = AccessRequest::query()->create(['account_id' => $this->owner->account_id, 'user_id' => $person->id, 'name' => $person->name, 'email' => $person->email]);

    $this->actingAs($leadMember->fresh())
        ->post(route('socialhq.access-requests.approve', $request->id), [
            'workspaces' => [$this->workspace->id],
            'role_id' => Roles::builtIn($this->workspace->account_id, BuiltInRole::Manager)->id,
        ])
        ->assertSessionHasErrors(['role_id' => __('socialhq_roles.errors.above_your_access')]);

    $this->actingAs($admin)
        ->post(route('socialhq.access-requests.approve', $request->id), [
            'workspaces' => [$this->workspace->id],
            'role_id' => Role::factory()->create()->id,
        ])
        ->assertSessionHasErrors('role_id');

    expect($request->fresh()->status)->toBe(AccessRequest::PENDING);
});

test('the access requests page offers each role where the approver may give it', function () {
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $admin = accessLogMember($this->workspace, BuiltInRole::Admin);
    $other->members()->attach($admin->id, Roles::membershipColumns(Roles::builtIn($this->workspace->account_id, BuiltInRole::Editor)));

    $this->actingAs($admin)
        ->get(route('socialhq.access-requests.index'))
        ->assertInertia(fn ($page) => $page
            ->has('workspaces', 1)
            ->where('roles', fn ($roles) => collect($roles)->firstWhere('key', 'admin')['assignable_in'] === [$this->workspace->id]));
});
