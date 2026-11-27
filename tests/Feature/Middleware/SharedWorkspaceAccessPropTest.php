<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Roles;

test('the current workspace shares the access flags of the current user', function (string $access, array $expected) {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    $actor = $access === 'owner' ? $owner->fresh() : workspaceMember($workspace, $access);

    $this->actingAs($actor)
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.currentWorkspace.id', $workspace->id)
            ->where('auth.currentWorkspace.is_owner', $expected['is_owner'])
            ->where('auth.currentWorkspace.is_admin', $expected['is_admin'])
            ->where('auth.currentWorkspace.requires_approval', $expected['requires_approval'])
            // Social HQ roles: a membership without a role shares no role, and the permissions its flags held.
            ->where('auth.currentWorkspace.role', null)
            ->where('auth.currentWorkspace.permissions', $expected['permissions']));
})->with([
    'owner' => ['owner', ['is_owner' => true, 'is_admin' => true, 'requires_approval' => false, 'permissions' => Permission::all()]],
    'admin' => ['admin', ['is_owner' => false, 'is_admin' => true, 'requires_approval' => false, 'permissions' => Permission::all()]],
    'member' => ['member', ['is_owner' => false, 'is_admin' => false, 'requires_approval' => false, 'permissions' => ['posts.create', 'posts.publish', 'posts.approve', 'repurpose.manage', 'plan.edit', 'plan.approve', 'ai.use']]],
    'needs approval' => ['approval', ['is_owner' => false, 'is_admin' => false, 'requires_approval' => true, 'permissions' => ['posts.create', 'plan.edit', 'ai.use']]],
]);

test('the current workspace shares the role of the current user and what it grants', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $designer = workspaceMember($workspace, 'approval');
    $role = Roles::builtIn($workspace->account_id, BuiltInRole::Designer);
    Roles::assign($workspace, $designer->id, $role);

    $this->actingAs($designer)
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.currentWorkspace.role', ['id' => $role->id, 'key' => 'designer', 'name' => 'Designer'])
            ->where('auth.currentWorkspace.permissions', [Permission::PlanEdit, Permission::AiUse])
            ->where('auth.currentWorkspace.is_admin', false)
            ->where('auth.currentWorkspace.requires_approval', true));
});
