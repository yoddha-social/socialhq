<?php

declare(strict_types=1);

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Roles;

/**
 * QA 2026-11-18 item 10: the members page, a pending invite's access, the
 * invite dialog after sending, and the workspace page's delete section.
 *
 * @return array{0: User, 1: Workspace}
 */
function membersSettingsOwner(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($owner->account);

    return [$owner->fresh(), $workspace];
}

test('the invite dialog stays open after sending and invites another', function () {
    [$owner] = membersSettingsOwner();
    $this->actingAs($owner);

    $page = visit(route('app.members'));
    waitFor($page, '@invite-member-button');
    $page->click('@invite-member-button');
    waitFor($page, '@invite-email');
    $page->fill('@invite-email', 'first.person@example.com')
        ->click('@invite-member-submit');

    waitFor($page, '@invite-member-sent');
    $page->assertVisible('@invite-member-dialog')
        ->assertSeeIn('@invite-member-sent', 'first.person@example.com')
        ->assertMissing('@invite-email');

    expect(Invite::query()->where('email', 'first.person@example.com')->exists())->toBeTrue();

    $page->click('@invite-member-another');
    waitFor($page, '@invite-email');
    $page->assertValue('@invite-email', '')
        ->assertMissing('@invite-member-sent')
        ->fill('@invite-email', 'second.person@example.com')
        ->click('@invite-member-submit');

    waitForText($page, '@invite-member-sent', 'second.person@example.com');
    $page->click('@invite-member-done');
    waitForGone($page, '@invite-member-dialog');

    expect(Invite::query()->where('email', 'second.person@example.com')->exists())->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('editing a pending invite saves the role it joins with', function () {
    [$owner, $workspace] = membersSettingsOwner();
    $editor = Roles::builtIn($workspace->account_id, BuiltInRole::Editor);
    $manager = Roles::builtIn($workspace->account_id, BuiltInRole::Manager);
    $invite = Invite::factory()->create([
        'account_id' => $workspace->account_id,
        'invited_by' => $owner->id,
        'email' => 'pending.person@example.com',
        'workspaces' => [$workspace->id],
        ...Roles::membershipColumns($editor),
    ]);
    $this->actingAs($owner);

    $page = visit(route('app.members'));
    waitFor($page, "@invite-edit-{$invite->id}");
    $page->assertSeeIn("@invite-badge-{$invite->id}", 'Editor')
        ->click("@invite-edit-{$invite->id}");
    waitFor($page, '@edit-invite-dialog');

    $page->assertSeeIn('@edit-invite-email', 'pending.person@example.com')
        ->assertAttribute('@role-choice-editor', 'aria-checked', 'true')
        ->click('@role-choice-manager');
    waitForState($page, '@role-choice-manager', "element.getAttribute('aria-checked') === 'true'");
    $page->click('@edit-invite-submit');

    waitForGone($page, '@edit-invite-dialog');
    waitForDatabase($page, fn (): bool => $invite->fresh()->shq_role_id === $manager->id);
    waitForText($page, "@invite-badge-{$invite->id}", 'Manager');

    // The flags mirror the role: a Manager publishes without approval and doesn't manage members.
    expect($invite->fresh()->is_admin)->toBeFalse()
        ->and($invite->fresh()->requires_approval)->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('the workspace page keeps the delete section closed until it is opened', function () {
    [$owner] = membersSettingsOwner();
    Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $this->actingAs($owner);

    $page = visit(route('app.workspace.settings'));
    waitFor($page, '@workspace-danger-zone-toggle');
    $page->assertAriaAttribute('@workspace-danger-zone-toggle', 'expanded', 'false')
        ->assertMissing('@workspace-danger-zone-content')
        ->assertMissing('@workspace-delete-button')
        ->click('@workspace-danger-zone-toggle');

    waitFor($page, '@workspace-delete-button');
    $page->assertAriaAttribute('@workspace-danger-zone-toggle', 'expanded', 'true')
        ->assertVisible('@workspace-danger-zone-content')
        ->assertNoJavaScriptErrors();
});
