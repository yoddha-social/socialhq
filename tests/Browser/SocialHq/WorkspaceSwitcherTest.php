<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

$ownerWithTwoWorkspaces = function (): array {
    $user = User::factory()->create();
    $first = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id, 'name' => 'Brand One']);
    $second = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id, 'name' => 'Brand Two']);
    $first->members()->attach($user->id, membershipPivot('admin'));
    $second->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $first->id]);

    return [$user->fresh(), $first, $second];
};

test('the sidebar switcher shows the current workspace and switches to another', function () use ($ownerWithTwoWorkspaces) {
    [$user, $first, $second] = $ownerWithTwoWorkspaces();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitFor($page, '@socialhq-workspace-switcher');
    $page->assertSeeIn('@socialhq-workspace-switcher-name', 'Brand One');

    $page->click('@socialhq-workspace-switcher');
    waitFor($page, "@socialhq-workspace-{$second->id}");
    $page->assertVisible('@socialhq-workspace-all')
        ->assertVisible('@socialhq-workspace-settings')
        ->click("@socialhq-workspace-{$second->id}");

    $page->wait(1)->assertSeeIn('@socialhq-workspace-switcher-name', 'Brand Two')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->current_workspace_id)->toBe($second->id);
});

test('the switcher links to the list of all workspaces', function () use ($ownerWithTwoWorkspaces) {
    [$user] = $ownerWithTwoWorkspaces();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitFor($page, '@socialhq-workspace-switcher');
    $page->click('@socialhq-workspace-switcher');
    waitFor($page, '@socialhq-workspace-all');
    $page->click('@socialhq-workspace-all');

    waitForPath($page, parse_url(route('app.workspaces.index'), PHP_URL_PATH));
    $page->assertSee('Brand One')->assertSee('Brand Two')->assertNoJavaScriptErrors();
});

test('members who cannot manage the workspace see no settings link', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $this->actingAs(workspaceMember($workspace));

    $page = visit(route('app.posts.index'));
    waitFor($page, '@socialhq-workspace-switcher');
    $page->click('@socialhq-workspace-switcher');
    waitFor($page, '@socialhq-workspace-all');
    $page->assertMissing('@socialhq-workspace-settings')->assertNoJavaScriptErrors();
});
