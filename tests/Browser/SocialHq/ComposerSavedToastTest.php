<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/*
 * QA 2026-11-18, item 12: saving a draft gave no sign it worked. A composer
 * opened from the list steps back through history after the save; the toast
 * must survive that step.
 */

test('saving a draft opened from the drafts list says so on the list', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $draft = Post::factory()->forAccount($channel)->draft()->create([
        'user_id' => $user->id,
        'content' => 'Winter menu draft',
    ]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.posts.index', ['tab' => 'drafts']))->resize(1440, 900);
    waitFor($page, "@post-edit-{$draft->id}");
    $page->click("@post-edit-{$draft->id}");
    waitFor($page, '@composer-save-draft');
    $page->click('@composer-save-draft');

    waitForGone($page, '@post-composer-dialog');
    waitForText($page, '[data-sonner-toast]', trans_choice('socialhq.composer.saved.draft', 1, ['count' => 1]));
    $page->assertQueryStringHas('tab', 'drafts')->assertNoJavaScriptErrors();
});
