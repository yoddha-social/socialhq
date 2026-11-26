<?php

declare(strict_types=1);

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\BuiltInRole;
use App\SocialHq\Access\Roles;

// Social HQ: members and invites carry a role (App\SocialHq\Access) instead of
// TryPost's admin switch and publishing choice.

/**
 * @return array{0: User, 1: Workspace}
 */
function memberAccessOwner(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($owner->account);

    return [$owner->fresh(), $workspace];
}

test('the invite picks a role and stores it', function () {
    [$owner, $workspace] = memberAccessOwner();
    $designer = Roles::builtIn($workspace->account_id, BuiltInRole::Designer);
    $this->actingAs($owner);

    $page = visit(route('app.members'));
    waitFor($page, '@invite-member-button');
    $page->click('@invite-member-button');
    waitFor($page, '@role-choice-editor');
    $page->assertAttribute('@role-choice-editor', 'aria-checked', 'true')
        ->click('@role-choice-designer')
        ->fill('@invite-email', 'designer@example.com')
        ->click('@invite-member-submit');

    // The dialog stays open and says who got the invite.
    waitFor($page, '@invite-member-sent');
    $page->assertSeeIn('@invite-member-sent', 'designer@example.com');
    $page->assertNoJavaScriptErrors();

    $invite = Invite::query()->where('email', 'designer@example.com')->sole();

    expect($invite->shq_role_id)->toBe($designer->id)
        ->and($invite->is_admin)->toBeFalse()
        ->and($invite->requires_approval)->toBeTrue();
});

test('changing a member’s role shows what changes, then saves it', function () {
    [$owner, $workspace] = memberAccessOwner();
    $member = workspaceMember($workspace, 'approval', ['name' => 'Mia Member']);
    Roles::assign($workspace, $member->id, Roles::builtIn($workspace->account_id, BuiltInRole::Editor));
    $manager = Roles::builtIn($workspace->account_id, BuiltInRole::Manager);
    $this->actingAs($owner);

    $page = visit(route('app.members'));
    waitFor($page, "@member-role-{$member->id}");
    // The owner's role is locked, with the reason on hover.
    $page->assertMissing("@member-role-{$owner->id}")
        ->assertVisible("@member-badge-{$owner->id}")
        ->click("@member-role-{$member->id}");

    waitFor($page, '@member-role-option-manager');
    $page->click('@member-role-option-manager');
    waitFor($page, '@change-role-dialog');
    $page->assertSeeIn('@change-role-gains', 'Publish without approval')
        ->assertSeeIn('@change-role-losses', 'Nothing')
        ->click('@change-role-submit');

    waitForGone($page, '@change-role-dialog');
    waitForText($page, "@member-role-{$member->id}", 'Manager');
    $page->assertNoJavaScriptErrors();

    expect($workspace->members()->whereKey($member->id)->value('user_workspace.shq_role_id'))->toBe($manager->id);
});
