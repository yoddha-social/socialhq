<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Inertia\Inertia;

test('every request shares the socialhq props, even after a server flushed the shared props', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);

    $sharesEverything = fn ($page) => $page->has('socialhq.approvals')
        ->has('socialhq.aiGraphics')
        ->has('socialhq.companyAi')
        ->has('socialhq.signup')
        ->has('socialhq.platform')
        ->where('socialhq.publishNow.confirm', false)
        ->has('socialhq.inbox.count');

    $this->actingAs($owner->fresh())->get(route('app.profile.edit'))->assertInertia($sharesEverything);

    // Pest's browser server keeps the app between requests and flushes the
    // shared props after each one (as Octane would).
    Inertia::flushShared();

    $this->get(route('app.profile.edit'))->assertInertia($sharesEverything);
});
