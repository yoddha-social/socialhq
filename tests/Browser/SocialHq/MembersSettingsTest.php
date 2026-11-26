<?php

declare(strict_types=1);

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

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

test('editing a pending invite saves its access', function () {
    [$owner, $workspace] = membersSettingsOwner();
    $invite = Invite::factory()->create([
        'account_id' => $workspace->account_id,
        'invited_by' => $owner->id,
        'email' => 'pending.person@example.com',
        'workspaces' => [$workspace->id],
        ...membershipPivot('member'),
    ]);
    $this->actingAs($owner);

    $page = visit(route('app.members'));
    waitFor($page, "@invite-menu-{$invite->id}");
    $page->click("@invite-menu-{$invite->id}");
    waitFor($page, "@invite-edit-{$invite->id}");
    $page->click("@invite-edit-{$invite->id}");
    waitFor($page, '@edit-invite-dialog');

    $page->assertSeeIn('@edit-invite-email', 'pending.person@example.com')
        ->click('@member-access-admin');
    waitForState($page, '@member-access-admin', "element.getAttribute('aria-checked') === 'true'");
    $page->click('@edit-invite-submit');

    waitForGone($page, '@edit-invite-dialog');
    waitForDatabase($page, fn (): bool => $invite->fresh()->is_admin);
    waitForText($page, "@invite-badge-{$invite->id}", 'Admin');

    expect($invite->fresh()->is_admin)->toBeTrue()
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
