<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\Roles;

/**
 * @return array{0: User, 1: Workspace}
 */
function rolesEditorOwner(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);

    return [$owner->fresh(), $workspace];
}

test('a switch turns on what it needs, says so, and saves', function () {
    [$owner, $workspace] = rolesEditorOwner();
    $editor = Roles::builtIn($workspace->account_id, BuiltInRole::Editor);
    $this->actingAs($owner);

    $page = visit(route('socialhq.roles.show', $editor));
    waitFor($page, '@role-switch-posts-approve');
    $page->assertMissing('@role-save-bar')
        ->click('@role-switch-posts-approve');

    waitFor($page, '@role-dependency-note');
    waitForState($page, '@role-switch-posts-publish', "element.getAttribute('aria-checked') === 'true'");
    $page->assertSeeIn('@role-dependency-note', 'Publish without approval')
        ->assertVisible('@role-reset-default')
        ->click('@role-save');

    waitForGone($page, '@role-save-bar');
    waitForDatabase($page, fn (): bool => $editor->fresh()->grants(Permission::PostsApprove));
    $page->assertNoJavaScriptErrors();

    expect($editor->fresh()->grantedPermissions())->toBe([
        Permission::PostsCreate, Permission::PostsPublish, Permission::PostsApprove, Permission::PlanEdit, Permission::AiUse,
    ]);
});

test('turning a switch off turns off what needs it, and discard brings it back', function () {
    [$owner, $workspace] = rolesEditorOwner();
    $manager = Roles::builtIn($workspace->account_id, BuiltInRole::Manager);
    $this->actingAs($owner);

    $page = visit(route('socialhq.roles.show', $manager));
    waitFor($page, '@role-switch-posts-create');
    $page->click('@role-switch-posts-create');

    waitForState($page, '@role-switch-posts-approve', "element.getAttribute('aria-checked') === 'false'");
    $page->assertAttribute('@role-switch-posts-publish', 'aria-checked', 'false')
        ->assertAttribute('@role-switch-repurpose-manage', 'aria-checked', 'false')
        ->click('@role-discard');

    waitForGone($page, '@role-save-bar');
    $page->assertAttribute('@role-switch-posts-approve', 'aria-checked', 'true')
        ->assertNoJavaScriptErrors();

    expect($manager->fresh()->grantedPermissions())->toBe(BuiltInRole::Manager->defaultPermissions());
});

test('a custom role is created from another and opened', function () {
    [$owner] = rolesEditorOwner();
    $this->actingAs($owner);

    $page = visit(route('socialhq.roles.index'));
    waitFor($page, '@roles-create');
    $page->click('@roles-create');
    waitFor($page, '@create-role-dialog');
    $page->fill('@create-role-name', 'Video producer')
        ->click('@create-role-from-designer')
        ->click('@create-role-submit');

    waitFor($page, '@role-editor');
    waitForDatabase($page, fn (): bool => Role::query()->where('name', 'Video producer')->exists());
    $page->assertSeeIn('@header-title', 'Video producer')->assertNoJavaScriptErrors();

    expect(Role::query()->where('name', 'Video producer')->sole()->grantedPermissions())
        ->toBe(BuiltInRole::Designer->defaultPermissions());
});
