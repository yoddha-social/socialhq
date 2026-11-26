<?php

declare(strict_types=1);

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'user_id' => $this->owner->id,
        'account_id' => $this->owner->account_id,
    ]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
});

function editInviteTestInvite(Workspace $workspace, array $attributes = []): Invite
{
    return Invite::factory()->create([
        'account_id' => $workspace->account_id,
        'invited_by' => $workspace->user_id,
        'workspaces' => [$workspace->id],
        ...membershipPivot('member'),
        ...$attributes,
    ]);
}

function editInviteTestAccess(Invite $invite): array
{
    $invite->refresh();

    return ['is_admin' => $invite->is_admin, 'requires_approval' => $invite->requires_approval];
}

test('the owner makes a pending invite an admin', function () {
    $invite = editInviteTestInvite($this->workspace);

    $this->actingAs($this->owner)
        ->from(route('app.members'))
        ->put(route('app.invites.update', $invite), ['is_admin' => '1', 'requires_approval' => '0'])
        ->assertRedirect(route('app.members'))
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('flash.banner');

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => true, 'requires_approval' => false]);
});

test('a workspace admin makes a pending invite need approval', function () {
    $invite = editInviteTestInvite($this->workspace);
    $admin = workspaceMember($this->workspace, 'admin');

    $this->actingAs($admin)
        ->put(route('app.invites.update', $invite), ['is_admin' => '0', 'requires_approval' => '1'])
        ->assertRedirect();

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => false, 'requires_approval' => true]);
});

test('an admin invite is never stored as needing approval', function () {
    $invite = editInviteTestInvite($this->workspace, membershipPivot('approval'));

    $this->actingAs($this->owner)
        ->put(route('app.invites.update', $invite), ['is_admin' => '1', 'requires_approval' => '1'])
        ->assertRedirect();

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => true, 'requires_approval' => false]);
});

test('while members are always reviewed, an invite that is not an admin needs approval', function () {
    config(['socialhq.approvals.members_always_reviewed' => true]);
    $invite = editInviteTestInvite($this->workspace, membershipPivot('admin'));

    $this->actingAs($this->owner)
        ->put(route('app.invites.update', $invite), ['is_admin' => '0', 'requires_approval' => '0'])
        ->assertRedirect();

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => false, 'requires_approval' => true]);
});

test('editing an invite needs both access flags', function () {
    $invite = editInviteTestInvite($this->workspace);

    $this->actingAs($this->owner)
        ->put(route('app.invites.update', $invite), ['is_admin' => 'maybe'])
        ->assertSessionHasErrors(['is_admin', 'requires_approval']);

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => false, 'requires_approval' => false]);
});

test('a guest is sent to log in', function () {
    $invite = editInviteTestInvite($this->workspace);

    $this->put(route('app.invites.update', $invite), membershipPivot('admin'))
        ->assertRedirect(route('login'));

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => false, 'requires_approval' => false]);
});

test('a member who is not an admin cannot edit an invite, before validation', function () {
    $invite = editInviteTestInvite($this->workspace);
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)
        ->put(route('app.invites.update', $invite), ['is_admin' => '1', 'requires_approval' => '0'])
        ->assertForbidden();

    $this->actingAs($member)
        ->put(route('app.invites.update', $invite), ['is_admin' => 'maybe'])
        ->assertForbidden();

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => false, 'requires_approval' => false]);
});

test('an invite of another account is not found', function () {
    $otherOwner = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create([
        'user_id' => $otherOwner->id,
        'account_id' => $otherOwner->account_id,
    ]);
    $invite = editInviteTestInvite($otherWorkspace);

    $this->actingAs($this->owner)
        ->put(route('app.invites.update', $invite), membershipPivot('admin'))
        ->assertNotFound();

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => false, 'requires_approval' => false]);
});

test('an invite to another workspace of the same account is not found', function () {
    $otherWorkspace = Workspace::factory()->create([
        'user_id' => $this->owner->id,
        'account_id' => $this->owner->account_id,
    ]);
    $invite = editInviteTestInvite($otherWorkspace);
    $admin = workspaceMember($this->workspace, 'admin');

    $this->actingAs($admin)
        ->put(route('app.invites.update', $invite), membershipPivot('admin'))
        ->assertNotFound();

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => false, 'requires_approval' => false]);
});

test('an accepted invite can no longer be edited', function () {
    $invite = editInviteTestInvite($this->workspace, ['accepted_at' => now()]);

    $this->actingAs($this->owner)
        ->put(route('app.invites.update', $invite), membershipPivot('admin'))
        ->assertNotFound();

    expect(editInviteTestAccess($invite))->toBe(['is_admin' => false, 'requires_approval' => false]);
});

test('an admin of one brand can\'t cancel another brand\'s invite, nor change an invite that also covers a brand they don\'t manage', function () {
    $otherWorkspace = Workspace::factory()->create([
        'user_id' => $this->owner->id,
        'account_id' => $this->owner->account_id,
    ]);
    $theirs = editInviteTestInvite($otherWorkspace);
    $both = editInviteTestInvite($this->workspace, ['workspaces' => [$this->workspace->id, $otherWorkspace->id]]);
    $admin = workspaceMember($this->workspace, 'admin');

    $this->actingAs($admin)->delete(route('app.invites.destroy', $theirs))->assertNotFound();
    $this->actingAs($admin)->delete(route('app.invites.destroy', $both))->assertNotFound();
    $this->actingAs($admin)->put(route('app.invites.update', $both), membershipPivot('admin'))->assertNotFound();

    expect(Invite::query()->whereKey([$theirs->id, $both->id])->count())->toBe(2)
        ->and(editInviteTestAccess($both))->toBe(['is_admin' => false, 'requires_approval' => false]);

    // The owner manages both brands.
    $this->actingAs($this->owner)->delete(route('app.invites.destroy', $both))->assertRedirect();
    expect(Invite::query()->find($both->id))->toBeNull();
});
