<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

test('switching workspace updates the loaded current workspace', function () {
    $user = User::factory()->create();
    $first = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $second = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $user->update(['current_workspace_id' => $first->id]);

    expect($user->currentWorkspace->is($first))->toBeTrue();

    $user->switchWorkspace($second);

    expect($user->currentWorkspace->is($second))->toBeTrue()
        ->and($user->fresh()->current_workspace_id)->toBe($second->id);
});

test('the switch endpoint renders the next page for the new workspace', function () {
    $user = User::factory()->create();
    $first = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id, 'name' => 'Brand One']);
    $second = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id, 'name' => 'Brand Two']);
    $first->members()->attach($user->id, membershipPivot('admin'));
    $second->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $first->id]);

    $this->actingAs($user)->get(route('app.posts.index'))->assertOk();

    $this->post(route('app.workspaces.switch', $second))->assertRedirect(route('app.calendar'));

    $this->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page->where('auth.currentWorkspace.name', 'Brand Two'));
});
